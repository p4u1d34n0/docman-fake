# docman-fake

A fake Docman Connect API for local development and testing. Mimics the real Docman Connect API with realistic authentication, document validation, DC01.xx/DC02/DC03 error codes, and configurable failure simulation -- so you can develop and test Docman Connect integrations without hitting the real API.

Single-file PHP application. No framework, no Composer dependencies. Runs anywhere PHP 8.4 is available.

## Quick Start

### Docker (recommended)

```bash
docker build -t docman-fake .
docker run -p 8089:8089 docman-fake
```

### Docker Compose

```bash
docker compose up
```

### Standalone PHP

```bash
php -S 0.0.0.0:8089 index.php
```

Then point your application at the fake:

```env
DOCMAN_API_URL=http://localhost:8089
# Or with Docker Compose networking:
DOCMAN_API_URL=http://docman-fake:8089
```

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/token` | No | OAuth password grant -- returns a bearer token |
| `POST` | `/api/documents` | Yes | Document upload with full Docman Connect validation |
| `GET` | `/api/health` | No | Health check with uptime and config |
| `GET` | `/api/history` | Yes | List all received documents |
| `GET` | `/api/history/{id}` | Yes | Get a specific document by ExternalSystemId |
| `DELETE` | `/api/history` | Yes | Clear document history |

## Authentication

The fake implements OAuth 2.0 password grant, matching the real Docman Connect API.

### Obtain a token

```bash
curl -s -X POST http://localhost:8089/token \
  -d 'grant_type=password&username=admin&password=password'
```

Response:

```json
{
    "access_token": "eyJhbGciOi...a-JWT-like-token",
    "token_type": "bearer",
    "expires_in": 3600
}
```

By default, any username/password combination is accepted. Set `REQUIRE_AUTH=true` to enforce credential validation (see Environment Variables).

### Use the token

All `/api/*` endpoints (except `/api/health`) require a Bearer token:

```bash
curl -s http://localhost:8089/api/history \
  -H "Authorization: Bearer <your-token>"
```

A missing or expired token returns `401 Unauthorized`:

```json
{
    "error": "unauthorized",
    "error_description": "Bearer token is required. Obtain a token from POST /token."
}
```

## Document Model

The document upload payload follows the Docman Connect specification.

### Root fields

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `CaptureSource` | int | Yes | Must be `2` |
| `RecipientOdsCode` | string | Yes | Connect Endpoint ODS Code (e.g. `A12345`) |
| `Sender` | object | Yes | See Sender object below |
| `Patient` | object | Yes | See Patient object below |
| `Document` | object | Yes | See Document object below |
| `ActionRequired` | bool | No | |
| `MedicationChanged` | bool | No | |
| `Urgent` | bool | No | |
| `ClinicalCodes` | array | No | See ClinicalCode object below |
| `Notes` | string | No | Max 255 characters |
| `Revision` | bool | No | |
| `MRNNumber` | string | No | Max 255 characters |
| `OverrideSpineCheck` | bool | No | |

### Sender object

| Field | Type | Required | Max Length |
|-------|------|----------|-----------|
| `OdsCode` | string | Yes | -- |
| `Organisation` | string | Yes | 255 |
| `Department` | string | Yes | 255 |
| `Person` | string | Yes | 255 |
| `Group` | string | No | 100 |

### Patient object

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `Identifier` | string | Yes | NHS number |
| `FamilyName` | string | Yes | Max 255 characters |
| `GivenNames` | string | Yes | Max 255 characters |
| `Gender` | enum | Yes | `0`=Unspecified, `10`=Male, `20`=Female |
| `BirthDate` | DateTime | Yes | ISO 8601. Must not be a future date |

### Document object

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `Description` | string | Yes | Max 255 characters |
| `EventDate` | DateTime | Yes | ISO 8601 |
| `Folder` | string | No | Max 50 characters |
| `FilingSectionFolder` | string | No | |
| `FileContent` | string | Yes | Base64-encoded file data |
| `FileExtension` | string | Yes | No leading dot. See allowed list below |
| `FileHash` | string | No | SHA1 hash (40 hex chars) for integrity verification |
| `ExternalSystemId` | string | No | Source system identifier |

### ClinicalCode object

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `Scheme` | enum | Yes | `0`=Read, `2`=SNOMED |
| `Code` | string | Yes | |
| `Description` | string | Yes | |
| `DescriptionId` | string | Yes | |
| `Value` | string | Conditional | Required if `ValueUnit` is set |
| `ValueUnit` | string | Conditional | Required if `Value` is set |

### Allowed File Extensions

`html`, `htm`, `pdf`, `rtf`, `doc`, `docx`, `tif`, `tiff`, `txt`, `jpeg`, `jpg`, `xps`, `png`

No leading dot. Any extension not in this list triggers `DC01.30`.

## Document Upload

### Request

`POST /api/documents` with JSON body:

```json
{
    "CaptureSource": 2,
    "RecipientOdsCode": "A12345",
    "Sender": {
        "OdsCode": "B67890",
        "Organisation": "Oxford Online Pharmacy",
        "Department": "Dispensary",
        "Person": "Dr Smith",
        "Group": "Clinical"
    },
    "Patient": {
        "Identifier": "9434765919",
        "FamilyName": "Smith",
        "GivenNames": "John",
        "BirthDate": "1990-01-15T00:00:00",
        "Gender": 10
    },
    "Document": {
        "Description": "Consultation letter",
        "EventDate": "2026-03-24T10:30:00",
        "FileExtension": "pdf",
        "FileContent": "JVBERi0xLjQK...",
        "Folder": "Consultations",
        "FilingSectionFolder": "Letters",
        "ExternalSystemId": "OOP-12345",
        "FileHash": "da39a3ee5e6b4b0d3255bfef95601890afd80709"
    },
    "ActionRequired": false,
    "MedicationChanged": false,
    "Urgent": false,
    "Notes": "Follow-up consultation"
}
```

### Success Response (200)

```json
{
    "Guid": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "Status": "Accepted",
    "StatusCode": 4010
}
```

### Validation Error Response (400)

```json
{
    "Errors": [
        {
            "ErrorCode": "DC01.01",
            "ErrorMessage": "Patient data missing required fields: Identifier, FamilyName."
        },
        {
            "ErrorCode": "DC01.31",
            "ErrorMessage": "File content is required."
        }
    ]
}
```

### Example: Full Upload

```bash
# Get a token
TOKEN=$(curl -s -X POST http://localhost:8089/token \
  -d 'grant_type=password&username=admin&password=password' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")

# Upload a document
curl -s -X POST http://localhost:8089/api/documents \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "CaptureSource": 2,
    "RecipientOdsCode": "A12345",
    "Sender": {
      "OdsCode": "B67890",
      "Organisation": "Oxford Online Pharmacy",
      "Department": "Dispensary",
      "Person": "Dr Smith"
    },
    "Patient": {
      "Identifier": "9434765919",
      "FamilyName": "Smith",
      "GivenNames": "John",
      "BirthDate": "1990-01-15T00:00:00",
      "Gender": 10
    },
    "Document": {
      "Description": "Test document",
      "EventDate": "2026-03-24T10:30:00",
      "FileExtension": "pdf",
      "FileContent": "dGVzdCBjb250ZW50",
      "ExternalSystemId": "OOP-12345"
    }
  }'
```

## Error Code Reference

### DC01.xx -- Document Validation Errors

These match the real Docman Connect API error codes:

| Code | Trigger |
|------|---------|
| `DC01.01` | Patient data missing (required fields absent) |
| `DC01.02` | Document data missing |
| `DC01.03` | Notes field exceeds 255 characters |
| `DC01.04` | Sender organisation name exceeds 255 characters |
| `DC01.05` | Sender department exceeds 255 characters |
| `DC01.06` | Sender person field exceeds 255 characters |
| `DC01.07` | Patient surname exceeds 255 characters |
| `DC01.08` | Patient first/middle names exceed 255 characters |
| `DC01.09` | Patient Birth Date missing or is a future date |
| `DC01.11` | Clinical coding data validation fails |
| `DC01.20` | CaptureSource is invalid (must be 2) |
| `DC01.21` | Recipient ODS Code missing or invalid |
| `DC01.22` | Sender ODS Code missing or invalid |
| `DC01.30` | File extension absent or unsupported |
| `DC01.31` | File content missing |
| `DC01.32` | File extension exceeds 10 characters |
| `DC01.33` | File hash validation fails (corruption) |
| `DC01.40` | Document description missing |
| `DC01.41` | Event date missing |
| `DC01.42` | Folder name exceeds 50 characters |
| `DC01.43` | Document description exceeds 255 characters |

### DC02/DC03 -- Organisation-Level Errors

| Code | Trigger |
|------|---------|
| `DC02` | Recipient ODS code inactive or not found (simulated via `SIMULATE_DC02=true`) |
| `DC03` | Recipient ODS code not authorised (not simulated; documented for reference) |

### Document Status Codes (v2)

The success response includes a `StatusCode` field. These are the Docman Connect v2 status codes:

| StatusCode | Meaning |
|------------|---------|
| `4002` | Received by Connect |
| `4003` | Delivered |
| `4005` | Rejected |
| `4010` | Accepted |
| `5000` | System error |
| `6000` | Rejection Resolved |
| `7000` | System error, document on hold |

The fake always returns `4010` (Accepted) on successful upload. Failed random simulations return `5000`.

### Simulator-Only Codes

| Code | Trigger |
|------|---------|
| `SIM_DUPLICATE` | `ExternalSystemId` already uploaded in this session. This is a simulator-only check; the real Docman Connect API does not have a specific error code for duplicates. |

## Health Check

`GET /api/health` (no authentication required):

```bash
curl -s http://localhost:8089/api/health
```

```json
{
    "status": "ok",
    "uptime": 3612,
    "documents_received": 5,
    "config": {
        "require_auth": false,
        "token_expiry_seconds": 3600,
        "fail_rate": 0,
        "slow_response_ms": 0,
        "force_error_code": null,
        "force_http_status": null,
        "max_file_size_mb": 10,
        "simulate_dc02": false,
        "chaos_interval": 5,
        "chaos_request_count": 12
    }
}
```

## History Endpoints (for Test Assertions)

Use the history endpoints to verify documents were uploaded correctly in automated tests.

### List all documents

```bash
curl -s http://localhost:8089/api/history \
  -H "Authorization: Bearer $TOKEN"
```

```json
{
    "count": 2,
    "documents": [
        {
            "Guid": "a1b2c3d4-...",
            "StatusCode": 4010,
            "Status": "Accepted",
            "ExternalSystemId": "OOP-12345",
            "RecipientOdsCode": "A12345",
            "Patient": { "FamilyName": "Smith", "GivenNames": "John", ... },
            "Document": { "Description": "Test document", "FileExtension": "pdf", ... },
            "ReceivedAt": "2026-03-24T10:30:00+00:00"
        }
    ]
}
```

### Get a specific document

```bash
curl -s http://localhost:8089/api/history/OOP-12345 \
  -H "Authorization: Bearer $TOKEN"
```

### Clear history (reset between test runs)

```bash
curl -s -X DELETE http://localhost:8089/api/history \
  -H "Authorization: Bearer $TOKEN"
```

```json
{
    "status": "cleared",
    "documents_removed": 2
}
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `REQUIRE_AUTH` | `false` | When `true`, validates username/password against `DOCMAN_USERNAME` and `DOCMAN_PASSWORD` |
| `DOCMAN_USERNAME` | `admin` | Expected username when `REQUIRE_AUTH=true` |
| `DOCMAN_PASSWORD` | `password` | Expected password when `REQUIRE_AUTH=true` |
| `TOKEN_EXPIRY_SECONDS` | `3600` | Lifetime of issued tokens in seconds |
| `FAIL_RATE` | `0.0` | Probability (0.0--1.0) of random 500 errors on API endpoints |
| `SLOW_RESPONSE_MS` | `0` | Artificial delay in milliseconds added to every response |
| `FORCE_ERROR_CODE` | _(empty)_ | If set, all document uploads return this Docman Connect error code (e.g. `DC01.21`) |
| `FORCE_HTTP_STATUS` | _(empty)_ | If set, all requests (except health) return this HTTP status |
| `MAX_FILE_SIZE_MB` | `10` | Maximum decoded file size in megabytes |
| `SIMULATE_DC02` | `false` | When `true`, all document uploads fail with DC02 (inactive ODS code) |
| `CHAOS_INTERVAL` | `0` | When > 0, every Nth document upload fails with a rotating error type (see Chaos Mode) |

## Failure Simulation

The fake supports several modes for testing error handling and retry logic.

### Random failures

Simulate intermittent server errors to test retry logic:

```bash
docker run -p 8089:8089 -e FAIL_RATE=0.3 docman-fake
```

30% of requests to `/token` and `/api/documents` will return `500 Internal Server Error`.

### Slow responses

Simulate network latency or a slow API:

```bash
docker run -p 8089:8089 -e SLOW_RESPONSE_MS=2000 docman-fake
```

Every response will be delayed by 2 seconds.

### Forced error codes

Always return a specific Docman Connect error for every document upload:

```bash
docker run -p 8089:8089 -e FORCE_ERROR_CODE=DC01.31 docman-fake
```

### Forced HTTP status

Return a specific HTTP status for all requests (except health):

```bash
docker run -p 8089:8089 -e FORCE_HTTP_STATUS=503 docman-fake
```

### Inactive ODS code (DC02)

Simulate the recipient ODS code being inactive or not found:

```bash
docker run -p 8089:8089 -e SIMULATE_DC02=true docman-fake
```

### Chaos mode (rotating failures)

The most realistic testing mode. Every Nth document upload fails, cycling through different error types each time. This tests that your application handles a variety of failures, not just one.

```bash
docker run -p 8089:8089 -e CHAOS_INTERVAL=5 docman-fake
```

With `CHAOS_INTERVAL=5`, requests 1-4 succeed, request 5 fails, requests 6-9 succeed, request 10 fails with a different error, and so on. The failure type rotates through:

| # | Failure | HTTP | StatusCode |
|---|---------|------|------------|
| 1st | 500 Internal Server Error | 500 | 5000 |
| 2nd | DC02 -- Recipient ODS inactive | 400 | -- |
| 3rd | DC03 -- Recipient ODS not authorised | 400 | -- |
| 4th | DC01.01 -- Patient data missing | 400 | -- |
| 5th | DC01.21 -- Recipient ODS invalid | 400 | -- |
| 6th | DC01.31 -- File content missing | 400 | -- |
| 7th | DC01.09 -- Future birth date | 400 | -- |
| 8th | 503 Service Unavailable | 503 | 7000 |
| 9th | 401 -- Token expired | 401 | -- |
| 10th | 429 -- Rate limited | 429 | -- |

After cycling through all 10 types, it starts again from the top. The chaos counter is visible in `/api/health` and resets when you `DELETE /api/history`.

Example: to fail every 3rd request for aggressive testing:

```bash
docker run -p 8089:8089 -e CHAOS_INTERVAL=3 docman-fake
```

### Strict authentication

Require correct credentials:

```bash
docker run -p 8089:8089 \
  -e REQUIRE_AUTH=true \
  -e DOCMAN_USERNAME=myuser \
  -e DOCMAN_PASSWORD=mysecret \
  docman-fake
```

### Combining options

```bash
docker run -p 8089:8089 \
  -e REQUIRE_AUTH=true \
  -e FAIL_RATE=0.1 \
  -e SLOW_RESPONSE_MS=500 \
  -e MAX_FILE_SIZE_MB=5 \
  docman-fake
```

## Using with Docker Compose

The included `docker-compose.yml` provides a ready-to-use setup:

```yaml
services:
  docman-fake:
    build: .
    ports:
      - "8089:8089"
    environment:
      - REQUIRE_AUTH=false
      - TOKEN_EXPIRY_SECONDS=3600
      - FAIL_RATE=0.0
      - SLOW_RESPONSE_MS=0
      - MAX_FILE_SIZE_MB=10
      - SIMULATE_DC02=false
```

To use alongside a Laravel application, add your app service with `DOCMAN_API_URL=http://docman-fake:8089` and `depends_on: docman-fake`.

## Using for Automated Testing

The history endpoints make the fake ideal for integration test assertions:

```php
// PHPUnit example
public function test_document_is_sent_to_docman_connect(): void
{
    // Clear history before test
    Http::delete('http://docman-fake:8089/api/history', headers: [
        'Authorization' => 'Bearer ' . $this->getToken(),
    ]);

    // Run the code that sends documents to Docman Connect
    $this->service->sendConsultation($consultation);

    // Assert the document was received
    $response = Http::get('http://docman-fake:8089/api/history/OOP-123', headers: [
        'Authorization' => 'Bearer ' . $this->getToken(),
    ]);

    $this->assertEquals(200, $response->status());
    $this->assertEquals('Smith', $response->json('Patient.FamilyName'));
    $this->assertEquals('pdf', $response->json('Document.FileExtension'));
    $this->assertEquals(4010, $response->json('StatusCode'));
}
```

## Logging

All requests are logged to stderr with timestamps:

```
[2026-03-24 10:30:00] POST /token (45 bytes)
[2026-03-24 10:30:00]   -> Token issued for user: admin
[2026-03-24 10:30:01] POST /api/documents (1234 bytes)
[2026-03-24 10:30:01]   -> Accepted: ExternalSystemId=OOP-12345, Patient=Smith, John, ODS=A12345, GUID=a1b2c3d4-...
```

When running in Docker, view logs with:

```bash
docker compose logs -f docman-fake
```

## Document Storage

Received documents are stored in `/tmp/docman-history.json` inside the container. This file persists for the lifetime of the container but is lost on restart. File content (`FileContent`) is not stored -- only its length is recorded to conserve memory.

## 404 Responses

Any unmatched route returns a helpful 404 with a list of available endpoints:

```json
{
    "error": "Not found",
    "message": "No route matched: GET /unknown",
    "available_endpoints": {
        "POST /token": "OAuth password grant authentication",
        "POST /api/documents": "Document upload",
        "GET /api/health": "Health check",
        "GET /api/history": "List received documents",
        "GET /api/history/{id}": "Get document by ExternalSystemId",
        "DELETE /api/history": "Clear document history"
    }
}
```
