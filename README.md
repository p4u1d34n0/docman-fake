# docman-fake

A fake Docman API for local development and testing. Mimics the token and document upload endpoints so you can develop and test Docman integrations without hitting the real API.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/token` | Returns a fake bearer token |
| POST | `/api/documents` | Accepts a document payload, returns a fake GUID |

Any other request returns a 404.

## Quick Start

### Docker (recommended)

```bash
docker build -t docman-fake .
docker run -p 8089:8089 docman-fake
```

### Docker Compose

Add to your `docker-compose.yml`:

```yaml
services:
  docman-fake:
    build: .
    ports:
      - "8089:8089"
```

### Standalone PHP

```bash
php -S 0.0.0.0:8089 index.php
```

## Configuration

Point your application at the fake API:

```env
DOCMAN_API_URL=http://localhost:8089
# or if using Docker Compose networking:
DOCMAN_API_URL=http://docman-fake:8089
```

## Logging

All requests are logged to stderr with method, path, and payload size. Document uploads also log the consultation ID, patient name, and assigned GUID.

## Example

```bash
# Get a token
curl -X POST http://localhost:8089/token
# {"access_token":"fake-docman-token-...","token_type":"bearer","expires_in":3600}

# Upload a document
curl -X POST http://localhost:8089/api/documents \
  -H "Content-Type: application/json" \
  -d '{"Document":{"ExternalSystemId":"123"},"Patient":{"FamilyName":"Smith","GivenNames":"John"}}'
# {"Guid":"FAKE-...","Status":"Accepted","Message":"Document accepted (fake)"}
```
