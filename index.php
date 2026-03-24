<?php

declare(strict_types=1);

/**
 * Fake Docman Connect API for local development and testing.
 *
 * Mimics the real Docman Connect API endpoints with realistic authentication,
 * document validation, DC01.xx error codes, and configurable failure simulation.
 *
 * Endpoints:
 *   POST   /token                          - OAuth password grant (returns bearer token)
 *   POST   /api/documents                  - Document upload with validation
 *   GET    /api/health                     - Health check
 *   GET    /api/history                    - List all received documents
 *   GET    /api/history/{externalSystemId} - Get specific document by ExternalSystemId
 *   DELETE /api/history                    - Clear document history
 *
 * Run with: php -S 0.0.0.0:8089 index.php
 * Or as a Docker container (see Dockerfile alongside).
 *
 * Set DOCMAN_API_URL=http://docman-fake:8089 in your .env
 */

// ---------------------------------------------------------------------------
// Configuration (from environment variables)
// ---------------------------------------------------------------------------

final class Config
{
    public readonly bool $requireAuth;
    public readonly string $docmanUsername;
    public readonly string $docmanPassword;
    public readonly int $tokenExpirySeconds;
    public readonly float $failRate;
    public readonly int $slowResponseMs;
    public readonly ?string $forceErrorCode;
    public readonly ?int $forceHttpStatus;
    public readonly int $maxFileSizeMb;
    public readonly bool $simulateDc02;
    public readonly int $chaosInterval;

    private static ?self $instance = null;

    private function __construct()
    {
        $this->requireAuth = filter_var(
            getenv('REQUIRE_AUTH') ?: 'false',
            FILTER_VALIDATE_BOOLEAN,
        );
        $this->docmanUsername = getenv('DOCMAN_USERNAME') ?: 'admin';
        $this->docmanPassword = getenv('DOCMAN_PASSWORD') ?: 'password';
        $this->tokenExpirySeconds = (int) (getenv('TOKEN_EXPIRY_SECONDS') ?: '3600');
        $this->failRate = (float) (getenv('FAIL_RATE') ?: '0.0');
        $this->slowResponseMs = (int) (getenv('SLOW_RESPONSE_MS') ?: '0');
        $this->forceErrorCode = getenv('FORCE_ERROR_CODE') ?: null;
        $this->forceHttpStatus = getenv('FORCE_HTTP_STATUS') ? (int) getenv('FORCE_HTTP_STATUS') : null;
        $this->maxFileSizeMb = (int) (getenv('MAX_FILE_SIZE_MB') ?: '10');
        $this->simulateDc02 = filter_var(
            getenv('SIMULATE_DC02') ?: 'false',
            FILTER_VALIDATE_BOOLEAN,
        );
        $this->chaosInterval = (int) (getenv('CHAOS_INTERVAL') ?: '0');
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }
}

// ---------------------------------------------------------------------------
// History persistence (file-backed in-memory store)
// ---------------------------------------------------------------------------

final class DocumentHistory
{
    private const STORAGE_PATH = '/tmp/docman-history.json';

    public static function load(): array
    {
        if (!file_exists(self::STORAGE_PATH)) {
            return [];
        }

        $data = file_get_contents(self::STORAGE_PATH);
        if ($data === false || $data === '') {
            return [];
        }

        return json_decode($data, true) ?: [];
    }

    public static function save(array $history): void
    {
        file_put_contents(self::STORAGE_PATH, json_encode($history, JSON_PRETTY_PRINT));
    }

    public static function append(array $document): void
    {
        $history = self::load();
        $history[] = $document;
        self::save($history);
    }

    public static function findByExternalSystemId(int|string $id): ?array
    {
        $history = self::load();

        foreach ($history as $document) {
            if (($document['ExternalSystemId'] ?? null) == $id) {
                return $document;
            }
        }

        return null;
    }

    public static function externalSystemIdExists(int|string $id): bool
    {
        return self::findByExternalSystemId($id) !== null;
    }

    public static function clear(): void
    {
        self::save([]);
    }

    public static function count(): int
    {
        return count(self::load());
    }
}

// ---------------------------------------------------------------------------
// Token generation and validation
// ---------------------------------------------------------------------------

final class TokenManager
{
    private const SECRET = 'docman-fake-secret-key-not-for-production';

    public static function generate(): string
    {
        $config = Config::get();

        $payload = [
            'sub' => 'docman-fake-user',
            'iat' => time(),
            'exp' => time() + $config->tokenExpirySeconds,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = base64_encode(json_encode($payload));
        $signature = base64_encode(hash_hmac('sha256', "{$header}.{$body}", self::SECRET, true));

        return "{$header}.{$body}.{$signature}";
    }

    public static function validate(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$header, $body, $signature] = $parts;

        // Verify signature
        $expectedSignature = base64_encode(hash_hmac('sha256', "{$header}.{$body}", self::SECRET, true));
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Check expiry
        $payload = json_decode(base64_decode($body), true);
        if (!$payload || !isset($payload['exp'])) {
            return false;
        }

        return $payload['exp'] > time();
    }

    public static function extractFromHeader(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

// ---------------------------------------------------------------------------
// Document validation (Docman Connect DC01.xx error codes)
// ---------------------------------------------------------------------------

final class DocumentValidator
{
    /** Allowed file extensions per Docman Connect specification (no leading dot). */
    private const ALLOWED_EXTENSIONS = [
        'html', 'htm', 'pdf', 'rtf', 'doc', 'docx',
        'tif', 'tiff', 'txt', 'jpeg', 'jpg', 'xps', 'png',
    ];

    /** Valid Gender enum values: 0=Unspecified, 10=Male, 20=Female. */
    private const VALID_GENDERS = [0, 10, 20];

    /** Valid ClinicalCode Scheme enum values: 0=Read, 2=SNOMED. */
    private const VALID_CLINICAL_SCHEMES = [0, 2];

    /**
     * Validate the document payload against the Docman Connect specification.
     *
     * @return array{valid: bool, errors: array<array{code: string, message: string}>}
     */
    public static function validate(array $payload): array
    {
        $errors = [];

        // --- DC01.01: Patient Data Missing ---
        $patient = $payload['Patient'] ?? null;
        if (!is_array($patient) || count($patient) === 0) {
            $errors[] = self::error('DC01.01', 'Patient data is missing.');
        } else {
            self::validatePatient($patient, $errors);
        }

        // --- DC01.02: Document Data Missing ---
        $document = $payload['Document'] ?? null;
        if (!is_array($document) || count($document) === 0) {
            $errors[] = self::error('DC01.02', 'Document data is missing.');
        } else {
            self::validateDocument($document, $errors);
        }

        // --- DC01.03: Notes field exceeds 255 characters ---
        if (isset($payload['Notes']) && mb_strlen((string) $payload['Notes']) > 255) {
            $errors[] = self::error('DC01.03', 'Notes field exceeds 255 characters.');
        }

        // --- DC01.20: CaptureSource is invalid ---
        $captureSource = $payload['CaptureSource'] ?? null;
        if ($captureSource === null) {
            $errors[] = self::error('DC01.20', 'CaptureSource is required.');
        } elseif ((int) $captureSource !== 2) {
            $errors[] = self::error('DC01.20', "CaptureSource must be 2. Received: {$captureSource}.");
        }

        // --- DC01.21: Recipient ODS Code Missing or Invalid ---
        $recipientOds = $payload['RecipientOdsCode'] ?? null;
        if ($recipientOds === null || $recipientOds === '') {
            $errors[] = self::error('DC01.21', 'RecipientOdsCode is required.');
        } elseif (!preg_match('/^[A-Za-z]\d{5}$/', (string) $recipientOds)) {
            $errors[] = self::error('DC01.21', "Invalid RecipientOdsCode format. Expected: letter followed by 5 digits (e.g. A12345). Received: {$recipientOds}.");
        }

        // --- Sender validation ---
        $sender = $payload['Sender'] ?? null;
        if (!is_array($sender) || count($sender) === 0) {
            // Sender is required but no specific DC01 code for missing sender object;
            // the individual field codes cover this.
            $errors[] = self::error('DC01.22', 'Sender data is missing. OdsCode is required.');
        } else {
            self::validateSender($sender, $errors);
        }

        // --- DC01.11: Clinical coding data validation ---
        if (isset($payload['ClinicalCodes']) && is_array($payload['ClinicalCodes'])) {
            self::validateClinicalCodes($payload['ClinicalCodes'], $errors);
        }

        // --- MRNNumber max 255 ---
        if (isset($payload['MRNNumber']) && mb_strlen((string) $payload['MRNNumber']) > 255) {
            $errors[] = self::error('DC01.03', 'MRNNumber exceeds 255 characters.');
        }

        // --- Duplicate ExternalSystemId check (simulator-only, no real DC code) ---
        $externalId = $payload['Document']['ExternalSystemId'] ?? null;
        if ($externalId !== null && $externalId !== '' && DocumentHistory::externalSystemIdExists($externalId)) {
            $errors[] = self::error(
                'SIM_DUPLICATE',
                "Duplicate document. ExternalSystemId '{$externalId}' has already been uploaded. (Simulator-only check.)",
            );
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    /**
     * Validate Patient object fields.
     *
     * @param array<string, mixed> $patient
     * @param array<array{code: string, message: string}> &$errors
     */
    private static function validatePatient(array $patient, array &$errors): void
    {
        $requiredFields = ['Identifier', 'FamilyName', 'GivenNames', 'Gender', 'BirthDate'];
        $missing = array_filter($requiredFields, fn(string $field) => empty($patient[$field]) && $patient[$field] !== 0);

        if (count($missing) > 0) {
            $errors[] = self::error('DC01.01', 'Patient data missing required fields: ' . implode(', ', $missing) . '.');
        }

        // --- DC01.07: Patient surname exceeds 255 characters ---
        if (isset($patient['FamilyName']) && mb_strlen((string) $patient['FamilyName']) > 255) {
            $errors[] = self::error('DC01.07', 'Patient surname exceeds 255 characters.');
        }

        // --- DC01.08: Patient first/middle names exceed 255 characters ---
        if (isset($patient['GivenNames']) && mb_strlen((string) $patient['GivenNames']) > 255) {
            $errors[] = self::error('DC01.08', 'Patient first/middle names exceed 255 characters.');
        }

        // --- DC01.09: Patient Birth Date Missing or Future Date ---
        if (isset($patient['BirthDate']) && $patient['BirthDate'] !== '') {
            $birthDate = strtotime((string) $patient['BirthDate']);
            if ($birthDate === false) {
                $errors[] = self::error('DC01.09', 'Patient BirthDate is not a valid date.');
            } elseif ($birthDate > time()) {
                $errors[] = self::error('DC01.09', 'Patient BirthDate must not be a future date.');
            }
        }

        // Validate Gender enum
        if (isset($patient['Gender']) && !in_array((int) $patient['Gender'], self::VALID_GENDERS, true)) {
            $errors[] = self::error('DC01.01', "Patient Gender must be 0 (Unspecified), 10 (Male), or 20 (Female). Received: {$patient['Gender']}.");
        }
    }

    /**
     * Validate Sender object fields.
     *
     * @param array<string, mixed> $sender
     * @param array<array{code: string, message: string}> &$errors
     */
    private static function validateSender(array $sender, array &$errors): void
    {
        // --- DC01.22: Sender ODS Code Missing or Invalid ---
        $senderOds = $sender['OdsCode'] ?? null;
        if ($senderOds === null || $senderOds === '') {
            $errors[] = self::error('DC01.22', 'Sender OdsCode is required.');
        } elseif (!preg_match('/^[A-Za-z]\d{5}$/', (string) $senderOds)) {
            $errors[] = self::error('DC01.22', "Invalid Sender OdsCode format. Expected: letter followed by 5 digits. Received: {$senderOds}.");
        }

        // Sender Organisation, Department, Person are required
        if (empty($sender['Organisation'])) {
            $errors[] = self::error('DC01.04', 'Sender organisation name is required.');
        }
        if (empty($sender['Department'])) {
            $errors[] = self::error('DC01.05', 'Sender department is required.');
        }
        if (empty($sender['Person'])) {
            $errors[] = self::error('DC01.06', 'Sender person field is required.');
        }

        // --- DC01.04: Sender organisation name exceeds 255 characters ---
        if (isset($sender['Organisation']) && mb_strlen((string) $sender['Organisation']) > 255) {
            $errors[] = self::error('DC01.04', 'Sender organisation name exceeds 255 characters.');
        }

        // --- DC01.05: Sender department exceeds 255 characters ---
        if (isset($sender['Department']) && mb_strlen((string) $sender['Department']) > 255) {
            $errors[] = self::error('DC01.05', 'Sender department exceeds 255 characters.');
        }

        // --- DC01.06: Sender person field exceeds 255 characters ---
        if (isset($sender['Person']) && mb_strlen((string) $sender['Person']) > 255) {
            $errors[] = self::error('DC01.06', 'Sender person field exceeds 255 characters.');
        }

        // Group is optional but max 100 characters
        if (isset($sender['Group']) && mb_strlen((string) $sender['Group']) > 100) {
            $errors[] = self::error('DC01.04', 'Sender Group exceeds 100 characters.');
        }
    }

    /**
     * Validate Document object fields.
     *
     * @param array<string, mixed> $document
     * @param array<array{code: string, message: string}> &$errors
     */
    private static function validateDocument(array $document, array &$errors): void
    {
        // --- DC01.40: Document Description Missing ---
        $description = $document['Description'] ?? null;
        if ($description === null || $description === '') {
            $errors[] = self::error('DC01.40', 'Document description is required.');
        }

        // --- DC01.43: Document description exceeds 255 characters ---
        if (isset($document['Description']) && mb_strlen((string) $document['Description']) > 255) {
            $errors[] = self::error('DC01.43', 'Document description exceeds 255 characters.');
        }

        // --- DC01.41: Event Date Missing ---
        $eventDate = $document['EventDate'] ?? null;
        if ($eventDate === null || $eventDate === '') {
            $errors[] = self::error('DC01.41', 'Event date is required.');
        }

        // --- DC01.42: Folder name exceeds 50 characters ---
        if (isset($document['Folder']) && mb_strlen((string) $document['Folder']) > 50) {
            $errors[] = self::error('DC01.42', 'Folder name exceeds 50 characters.');
        }

        // --- DC01.30: File extension absent or unsupported ---
        $extension = strtolower(trim($document['FileExtension'] ?? ''));
        if ($extension === '') {
            $errors[] = self::error('DC01.30', 'FileExtension is required.');
        } elseif (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $allowed = implode(', ', self::ALLOWED_EXTENSIONS);
            $errors[] = self::error('DC01.30', "Unsupported file extension '{$extension}'. Allowed: {$allowed}.");
        }

        // --- DC01.32: File extension exceeds 10 characters ---
        if ($extension !== '' && mb_strlen($extension) > 10) {
            $errors[] = self::error('DC01.32', 'File extension exceeds 10 characters.');
        }

        // --- DC01.31: File Content Missing ---
        $fileContent = $document['FileContent'] ?? null;
        if ($fileContent === null || $fileContent === '') {
            $errors[] = self::error('DC01.31', 'File content is required.');
        } else {
            // Validate base64 encoding
            $decoded = base64_decode((string) $fileContent, true);
            if ($decoded === false) {
                $errors[] = self::error('DC01.31', 'File content must be valid base64-encoded data.');
            } else {
                // Check file size (simulator-only limit)
                $config = Config::get();
                $decodedSize = strlen($decoded);
                $maxBytes = $config->maxFileSizeMb * 1024 * 1024;

                if ($decodedSize > $maxBytes) {
                    $sizeMb = round($decodedSize / (1024 * 1024), 2);
                    $errors[] = self::error('DC01.31', "File content too large. Size: {$sizeMb}MB, Maximum: {$config->maxFileSizeMb}MB.");
                }
            }
        }

        // --- DC01.33: File hash validation fails (corruption) ---
        $fileHash = $document['FileHash'] ?? null;
        if ($fileHash !== null && $fileHash !== '') {
            $hashStr = (string) $fileHash;

            // Must be 40 hex characters (SHA1 format)
            if (!preg_match('/^[0-9a-fA-F]{40}$/', $hashStr)) {
                $errors[] = self::error('DC01.33', 'FileHash must be a valid SHA1 hash (40 hexadecimal characters).');
            } elseif ($fileContent !== null && $fileContent !== '') {
                // Verify hash matches file content
                $decoded = base64_decode((string) $fileContent, true);
                if ($decoded !== false) {
                    $expectedHash = sha1($decoded);
                    if (!hash_equals($expectedHash, strtolower($hashStr))) {
                        $errors[] = self::error('DC01.33', 'FileHash does not match the SHA1 hash of the file content. Possible file corruption.');
                    }
                }
            }
        }
    }

    /**
     * Validate ClinicalCodes array.
     *
     * @param array<int, array<string, mixed>> $clinicalCodes
     * @param array<array{code: string, message: string}> &$errors
     */
    private static function validateClinicalCodes(array $clinicalCodes, array &$errors): void
    {
        foreach ($clinicalCodes as $index => $code) {
            if (!is_array($code)) {
                $errors[] = self::error('DC01.11', "ClinicalCodes[{$index}] must be an object.");
                continue;
            }

            // Validate Scheme
            if (!isset($code['Scheme']) || !in_array((int) $code['Scheme'], self::VALID_CLINICAL_SCHEMES, true)) {
                $errors[] = self::error('DC01.11', "ClinicalCodes[{$index}].Scheme must be 0 (Read) or 2 (SNOMED).");
            }

            // Required fields
            foreach (['Code', 'Description', 'DescriptionId'] as $field) {
                if (empty($code[$field])) {
                    $errors[] = self::error('DC01.11', "ClinicalCodes[{$index}].{$field} is required.");
                }
            }

            // Value and ValueUnit are conditionally required (if one is set, both must be)
            $hasValue = isset($code['Value']) && $code['Value'] !== '';
            $hasValueUnit = isset($code['ValueUnit']) && $code['ValueUnit'] !== '';

            if ($hasValue && !$hasValueUnit) {
                $errors[] = self::error('DC01.11', "ClinicalCodes[{$index}].ValueUnit is required when Value is set.");
            }
            if ($hasValueUnit && !$hasValue) {
                $errors[] = self::error('DC01.11', "ClinicalCodes[{$index}].Value is required when ValueUnit is set.");
            }
        }
    }

    /**
     * Build an error entry.
     *
     * @return array{code: string, message: string}
     */
    private static function error(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}

// ---------------------------------------------------------------------------
// Chaos mode: rotate through different failure types every N requests
// ---------------------------------------------------------------------------

final class ChaosEngine
{
    private const COUNTER_PATH = '/tmp/docman-chaos-counter.txt';

    /**
     * Failure scenarios that cycle in order.
     * Each has: HTTP status, error code, error message, and optional extra fields.
     */
    private const SCENARIOS = [
        [
            'status' => 500,
            'response' => [
                'error' => 'Internal Server Error',
                'message' => 'Simulated system malfunction.',
                'StatusCode' => 5000,
            ],
            'label' => '500 Internal Server Error (StatusCode 5000)',
        ],
        [
            'status' => 400,
            'response' => [
                'Errors' => [['ErrorCode' => 'DC02', 'ErrorMessage' => 'Recipient ODS code is inactive or not found.']],
            ],
            'label' => 'DC02 — Recipient ODS inactive',
        ],
        [
            'status' => 400,
            'response' => [
                'Errors' => [['ErrorCode' => 'DC03', 'ErrorMessage' => 'Recipient ODS code is not an authorised receiving organisation.']],
            ],
            'label' => 'DC03 — Recipient ODS not authorised',
        ],
        [
            'status' => 400,
            'response' => [
                'Errors' => [['ErrorCode' => 'DC01.01', 'ErrorMessage' => 'Patient data is missing.']],
            ],
            'label' => 'DC01.01 — Patient data missing',
        ],
        [
            'status' => 400,
            'response' => [
                'Errors' => [['ErrorCode' => 'DC01.21', 'ErrorMessage' => 'RecipientOdsCode is missing or invalid.']],
            ],
            'label' => 'DC01.21 — Recipient ODS invalid',
        ],
        [
            'status' => 400,
            'response' => [
                'Errors' => [['ErrorCode' => 'DC01.31', 'ErrorMessage' => 'File content is missing.']],
            ],
            'label' => 'DC01.31 — File content missing',
        ],
        [
            'status' => 400,
            'response' => [
                'Errors' => [['ErrorCode' => 'DC01.09', 'ErrorMessage' => 'Patient BirthDate must not be a future date.']],
            ],
            'label' => 'DC01.09 — Future birth date',
        ],
        [
            'status' => 503,
            'response' => [
                'error' => 'Service Unavailable',
                'message' => 'The Docman Connect service is temporarily unavailable.',
                'StatusCode' => 7000,
            ],
            'label' => '503 Service Unavailable (StatusCode 7000)',
        ],
        [
            'status' => 401,
            'response' => [
                'error' => 'invalid_token',
                'error_description' => 'The access token has expired.',
            ],
            'label' => '401 — Token expired',
        ],
        [
            'status' => 429,
            'response' => [
                'error' => 'Too Many Requests',
                'message' => 'Rate limit exceeded. Try again later.',
            ],
            'label' => '429 — Rate limited',
        ],
    ];

    /**
     * Check if this request should be a chaos failure.
     * Returns null if the request should proceed normally, or the failure scenario if it should fail.
     */
    public static function check(int $chaosInterval): ?array
    {
        if ($chaosInterval <= 0) {
            return null;
        }

        $count = self::incrementCounter();

        if ($count % $chaosInterval !== 0) {
            return null;
        }

        // Pick the scenario based on how many failures we've triggered
        $failureNumber = (int) ($count / $chaosInterval);
        $scenarioIndex = ($failureNumber - 1) % count(self::SCENARIOS);

        return self::SCENARIOS[$scenarioIndex];
    }

    public static function getCounter(): int
    {
        if (!file_exists(self::COUNTER_PATH)) {
            return 0;
        }

        return (int) file_get_contents(self::COUNTER_PATH);
    }

    public static function resetCounter(): void
    {
        file_put_contents(self::COUNTER_PATH, '0');
    }

    private static function incrementCounter(): int
    {
        $count = self::getCounter() + 1;
        file_put_contents(self::COUNTER_PATH, (string) $count);

        return $count;
    }
}

// ---------------------------------------------------------------------------
// Response helpers
// ---------------------------------------------------------------------------

function sendJson(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendError(string $message, int $status = 400, ?string $errorCode = null): never
{
    $response = ['error' => $message];

    if ($errorCode !== null) {
        $response['error_code'] = $errorCode;
    }

    sendJson($response, $status);
}

function sendValidationErrors(array $errors, int $status = 400): never
{
    sendJson([
        'Errors' => array_map(fn(array $error) => [
            'ErrorCode' => $error['code'],
            'ErrorMessage' => $error['message'],
        ], $errors),
    ], $status);
}

function logRequest(string $method, string $uri, string $body): void
{
    $timestamp = date('Y-m-d H:i:s');
    $size = strlen($body);
    file_put_contents('php://stderr', "[{$timestamp}] {$method} {$uri} ({$size} bytes)\n");
}

function logMessage(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents('php://stderr', "[{$timestamp}]   -> {$message}\n");
}

// ---------------------------------------------------------------------------
// Startup time tracking
// ---------------------------------------------------------------------------

// Store startup time in a file so it persists across requests in PHP's built-in server
$startupFile = '/tmp/docman-fake-startup.txt';
if (!file_exists($startupFile)) {
    file_put_contents($startupFile, (string) time());
}
$startupTime = (int) file_get_contents($startupFile);

// ---------------------------------------------------------------------------
// Middleware: logging, delay simulation, forced errors
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');

logRequest($method, $uri, $body);

$config = Config::get();

// Apply artificial delay
if ($config->slowResponseMs > 0) {
    usleep($config->slowResponseMs * 1000);
}

// Force a specific HTTP status code for all requests (except health)
if ($config->forceHttpStatus !== null && $uri !== '/api/health') {
    sendJson([
        'error' => 'Forced error response',
        'forced_status' => $config->forceHttpStatus,
    ], $config->forceHttpStatus);
}

// Force a specific error code for document uploads
if ($config->forceErrorCode !== null && $method === 'POST' && $uri === '/api/documents') {
    sendValidationErrors([
        [
            'code' => $config->forceErrorCode,
            'message' => "Forced error: {$config->forceErrorCode}",
        ],
    ]);
}

// Chaos mode: rotate through different failure types every N document uploads
if ($config->chaosInterval > 0 && $method === 'POST' && $uri === '/api/documents') {
    $chaosScenario = ChaosEngine::check($config->chaosInterval);
    if ($chaosScenario !== null) {
        $counter = ChaosEngine::getCounter();
        logMessage("CHAOS [{$counter}]: {$chaosScenario['label']}");
        sendJson($chaosScenario['response'], $chaosScenario['status']);
    }
}

// Random failure simulation (except health and history endpoints)
if ($config->failRate > 0.0 && !str_starts_with($uri, '/api/health') && !str_starts_with($uri, '/api/history')) {
    if ((mt_rand() / mt_getrandmax()) < $config->failRate) {
        logMessage('Simulated random failure (FAIL_RATE=' . $config->failRate . ')');
        sendJson([
            'error' => 'Internal Server Error',
            'message' => 'Simulated failure for testing purposes.',
            'StatusCode' => 5000,
        ], 500);
    }
}

// ---------------------------------------------------------------------------
// Middleware: Bearer token authentication for protected endpoints
// ---------------------------------------------------------------------------

$protectedPrefixes = ['/api/documents', '/api/history'];
$requiresAuth = false;

foreach ($protectedPrefixes as $prefix) {
    if (str_starts_with($uri, $prefix)) {
        $requiresAuth = true;
        break;
    }
}

if ($requiresAuth) {
    $token = TokenManager::extractFromHeader();

    if ($token === null) {
        sendJson([
            'error' => 'unauthorized',
            'error_description' => 'Bearer token is required. Obtain a token from POST /token.',
        ], 401);
    }

    if (!TokenManager::validate($token)) {
        sendJson([
            'error' => 'invalid_token',
            'error_description' => 'The access token is invalid or has expired.',
        ], 401);
    }
}

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

// POST /token - OAuth password grant
if ($method === 'POST' && $uri === '/token') {
    // Parse form-encoded body
    parse_str($body, $params);

    $grantType = $params['grant_type'] ?? null;

    if ($grantType !== 'password') {
        sendJson([
            'error' => 'unsupported_grant_type',
            'error_description' => 'Only password grant type is supported.',
        ], 400);
    }

    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';

    // Validate credentials if strict auth is enabled
    if ($config->requireAuth) {
        if ($username !== $config->docmanUsername || $password !== $config->docmanPassword) {
            logMessage("Authentication failed for user: {$username}");
            sendJson([
                'error' => 'invalid_grant',
                'error_description' => 'The username or password is incorrect.',
            ], 401);
        }
    }

    $token = TokenManager::generate();

    logMessage("Token issued for user: {$username}");

    sendJson([
        'access_token' => $token,
        'token_type' => 'bearer',
        'expires_in' => $config->tokenExpirySeconds,
    ]);
}

// POST /api/documents - Document upload
if ($method === 'POST' && $uri === '/api/documents') {
    $payload = json_decode($body, true);

    if (!is_array($payload) || count($payload) === 0) {
        sendError('Empty or invalid JSON payload.', 400);
    }

    // --- DC02: Simulate inactive/not-found recipient ODS code ---
    if ($config->simulateDc02) {
        $odsCode = $payload['RecipientOdsCode'] ?? 'unknown';
        logMessage("Simulated DC02: Recipient ODS code '{$odsCode}' inactive or not found");
        sendValidationErrors([
            [
                'code' => 'DC02',
                'message' => "Recipient ODS code '{$odsCode}' is inactive or not found in the system.",
            ],
        ]);
    }

    // Validate the document
    $result = DocumentValidator::validate($payload);

    if (!$result['valid']) {
        $codes = implode(', ', array_column($result['errors'], 'code'));
        logMessage("Validation failed: {$codes}");
        sendValidationErrors($result['errors']);
    }

    // Generate a GUID for the accepted document
    $guid = sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        mt_rand(0, 0xFFFFFFFF),
        mt_rand(0, 0xFFFF),
        mt_rand(0, 0x0FFF) | 0x4000,
        mt_rand(0, 0x3FFF) | 0x8000,
        mt_rand(0, 0xFFFFFFFFFFFF),
    );

    $externalId = $payload['Document']['ExternalSystemId'] ?? null;
    $patient = ($payload['Patient']['FamilyName'] ?? '') . ', ' . ($payload['Patient']['GivenNames'] ?? '');
    $odsCode = $payload['RecipientOdsCode'] ?? 'unknown';

    logMessage("Accepted: ExternalSystemId={$externalId}, Patient={$patient}, ODS={$odsCode}, GUID={$guid}");

    // Store in history (without the file content to save memory)
    $historyEntry = [
        'Guid' => $guid,
        'StatusCode' => 4010,
        'Status' => 'Accepted',
        'ExternalSystemId' => $externalId,
        'RecipientOdsCode' => $odsCode,
        'CaptureSource' => $payload['CaptureSource'] ?? null,
        'Patient' => [
            'Identifier' => $payload['Patient']['Identifier'] ?? null,
            'FamilyName' => $payload['Patient']['FamilyName'] ?? null,
            'GivenNames' => $payload['Patient']['GivenNames'] ?? null,
            'BirthDate' => $payload['Patient']['BirthDate'] ?? null,
            'Gender' => $payload['Patient']['Gender'] ?? null,
        ],
        'Sender' => $payload['Sender'] ?? null,
        'Document' => [
            'Description' => $payload['Document']['Description'] ?? null,
            'EventDate' => $payload['Document']['EventDate'] ?? null,
            'FileExtension' => $payload['Document']['FileExtension'] ?? null,
            'Folder' => $payload['Document']['Folder'] ?? null,
            'FilingSectionFolder' => $payload['Document']['FilingSectionFolder'] ?? null,
            'ExternalSystemId' => $externalId,
            'FileContentLength' => strlen((string) ($payload['Document']['FileContent'] ?? '')),
            'FileHash' => $payload['Document']['FileHash'] ?? null,
        ],
        'ActionRequired' => $payload['ActionRequired'] ?? null,
        'MedicationChanged' => $payload['MedicationChanged'] ?? null,
        'Urgent' => $payload['Urgent'] ?? null,
        'Notes' => $payload['Notes'] ?? null,
        'ClinicalCodes' => $payload['ClinicalCodes'] ?? null,
        'MRNNumber' => $payload['MRNNumber'] ?? null,
        'ReceivedAt' => date('c'),
    ];

    DocumentHistory::append($historyEntry);

    sendJson([
        'Guid' => $guid,
        'Status' => 'Accepted',
        'StatusCode' => 4010,
    ]);
}

// GET /api/health - Health check
if ($method === 'GET' && $uri === '/api/health') {
    sendJson([
        'status' => 'ok',
        'uptime' => time() - $startupTime,
        'documents_received' => DocumentHistory::count(),
        'config' => [
            'require_auth' => $config->requireAuth,
            'token_expiry_seconds' => $config->tokenExpirySeconds,
            'fail_rate' => $config->failRate,
            'slow_response_ms' => $config->slowResponseMs,
            'force_error_code' => $config->forceErrorCode,
            'force_http_status' => $config->forceHttpStatus,
            'max_file_size_mb' => $config->maxFileSizeMb,
            'simulate_dc02' => $config->simulateDc02,
            'chaos_interval' => $config->chaosInterval,
            'chaos_request_count' => ChaosEngine::getCounter(),
        ],
    ]);
}

// GET /api/history - List all received documents
if ($method === 'GET' && $uri === '/api/history') {
    $history = DocumentHistory::load();
    sendJson([
        'count' => count($history),
        'documents' => $history,
    ]);
}

// GET /api/history/{externalSystemId} - Get specific document
if ($method === 'GET' && preg_match('#^/api/history/(.+)$#', $uri, $matches)) {
    $externalId = $matches[1];
    $document = DocumentHistory::findByExternalSystemId($externalId);

    if ($document === null) {
        sendError("Document with ExternalSystemId '{$externalId}' not found.", 404);
    }

    sendJson($document);
}

// DELETE /api/history - Clear history
if ($method === 'DELETE' && $uri === '/api/history') {
    $count = DocumentHistory::count();
    DocumentHistory::clear();
    ChaosEngine::resetCounter();

    logMessage("History cleared ({$count} documents removed, chaos counter reset)");

    sendJson([
        'status' => 'cleared',
        'documents_removed' => $count,
    ]);
}

// ---------------------------------------------------------------------------
// 404 fallthrough
// ---------------------------------------------------------------------------
http_response_code(404);
header('Content-Type: application/json');
echo json_encode([
    'error' => 'Not found',
    'message' => "No route matched: {$method} {$uri}",
    'available_endpoints' => [
        'POST /token' => 'OAuth password grant authentication',
        'POST /api/documents' => 'Document upload',
        'GET /api/health' => 'Health check',
        'GET /api/history' => 'List received documents',
        'GET /api/history/{id}' => 'Get document by ExternalSystemId',
        'DELETE /api/history' => 'Clear document history',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
