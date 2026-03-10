# API Documentation

## Base URL

```
https://your-domain.com/api
```

For local development:
```
http://localhost:8000/api
```

---

## Authentication

Most API endpoints are public. Protected endpoints (marked with 🔒) use these authentication methods:

### Push API (`/api/push` and `/api/push/*`):

**Priority order:**
1. **User-Agent bypass**: If request has `User-Agent: evoxupdater/*` → automatically authorized (no token needed)
2. **Token authentication** (fallback if user-agent is not evoxupdater):
   - `Authorization: Bearer YOUR_TOKEN` (HTTP header) - preferred
   - `X-API-Key: YOUR_TOKEN` (HTTP header) - alternative

### Examples:

**Using evoxupdater user-agent (no token required):**
```bash
curl -X POST "https://domain.com/api/push" \
  -H "User-Agent: evoxupdater/1.0" \
  -H "Content-Type: application/json" \
  -d '{"codename":"device","date":"2024-01-01","version":"9","buildType":"OFFICIAL"}'
```

**Using bearer token (standard auth):**
```bash
curl -X POST "https://domain.com/api/push" \
  -H "Authorization: Bearer YOUR_SECRET_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codename":"device","date":"2024-01-01","version":"9","buildType":"OFFICIAL"}'
```

**Using X-API-Key header:**
```bash
curl -X POST "https://domain.com/api/push" \
  -H "X-API-Key: YOUR_SECRET_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codename":"device","date":"2024-01-01","version":"9","buildType":"OFFICIAL"}'
```

Token must be set in environment variable `PUSH_API_TOKEN` or exported in `.env` file.

---

## Common Response Format

### Success Response:
```json
{
  "success": true,
  "data": { ... }
}
```

### Error Response:
```json
{
  "success": false,
  "error": "Error message",
  "APICode": "T-XXXX"
}
```

### API Error Codes:
- `T-0001`: Database/Internal error
- `T-0002`: Bad request / Invalid parameters
- `T-0003`: Unauthorized / Authentication failed
- `T-0004`: Method not allowed
- `T-0005`: Not found
- `T-0006`: Internal server error
- `T-0007`: Accepted / Processing

---

## Endpoints

### 1. Health Check

**Endpoint:** `GET /api/health`

**Description:** Check system health status for all components.

**Authentication:** None

**Response:**
```json
{
  "database": {
    "status": "healthy",
    "message": "Database connection successful"
  },
  "filesystem": {
    "status": "healthy",
    "message": "Filesystem accessible",
    "base_path": "/mnt/evolution-x",
    "readable": true,
    "writable": true
  },
  "prerelease": {
    "status": "healthy",
    "message": "Pre-release path accessible",
    "path": "/mnt/pre-release",
    "exists": true,
    "readable": true
  },
  "r2": {
    "status": "healthy",
    "message": "R2 configuration present",
    "configured": true
  },
  "php_extensions": {
    "status": "healthy",
    "message": "All required extensions loaded",
    "required": ["pdo", "pdo_sqlite", "json", "curl"],
    "missing": []
  },
  "push_queue": {
    "status": "healthy",
    "message": "Push queue operational",
    "details": {
      "queued": 0,
      "processing": 0,
      "total_processed": 150
    }
  }
}
```

**Example:**
```bash
curl http://localhost:8000/api/health
```

---

### 2. Download Statistics

**Endpoint:** `GET /api/download-statistics`

**Alias:** `GET /api/download-stats`

**Description:** Retrieve download statistics with flexible filtering options.

**Authentication:** None

**Query Parameters:**

| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| `filename` | string | No | Filter by filename pattern | `evolution-x` |
| `folder` | string | No | Filter by folder path | `OnePlus/OnePlus6` |
| `timeStart` | string | No | Start date (YYYY-MM-DD) | `2024-01-01` |
| `timeEnd` | string | No | End date (YYYY-MM-DD) | `2024-12-31` |
| `limit` | integer | No | Result limit (default: 50, max: 1000) | `100` |
| `sort` | string | No | Sort by: downloads, name | `downloads` |
| `format` | string | No | Response format: summary, detailed | `summary` |

**Response Format:**
```json
[
  {
    "folder": "OnePlus/OnePlus6",
    "downloadCount": 5432,
    "timeStart": "2024-01-01",
    "timeEnd": "2024-12-31",
    "individualFiles": [
      {
        "filename": "evolution-x-8.0-OnePlus6-20240101.zip",
        "downloadCount": 2567,
        "md5": "abc123def456...",
        "sha256": "789ghi012jkl...",
        "fileSize": 1234567890
      },
      {
        "filename": "evolution-x-8.0-OnePlus6-20240115.zip",
        "downloadCount": 2865
      }
    ]
  }
]
```

**Examples:**

Get overall statistics:
```bash
curl "http://localhost:8000/api/download-statistics"
```

Get statistics for specific device:
```bash
curl "http://localhost:8000/api/download-statistics?folder=OnePlus/OnePlus6&limit=10"
```

Get statistics for date range:
```bash
curl "http://localhost:8000/api/download-statistics?timeStart=2024-01-01&timeEnd=2024-12-31"
```

Filter by filename pattern:
```bash
curl "http://localhost:8000/api/download-statistics?filename=evolution-x-8.0"
```

Using the alias endpoint:
```bash
curl "http://localhost:8000/api/download-stats?folder=OnePlus/OnePlus6&limit=10"
```

---

### 3. File Hashes

**Endpoint:** `GET /api/hash`

**Description:** Get file checksums (MD5, SHA1, SHA256).

**Authentication:** None

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | string | Yes | Relative file path |

**Response:**
```json
{
  "md5": "abc123def456789...",
  "sha1": "def456ghi789012...",
  "sha256": "ghi789jkl012345...",
  "file_size": 1234567890,
  "status": "ready"
}
```

**For large files (>500MB):**
```json
{
  "md5": null,
  "sha1": null,
  "sha256": null,
  "status": "queued_for_background_processing"
}
```

**Example:**
```bash
curl "http://localhost:8000/api/hash?file=OnePlus/OnePlus6/evolution-x-8.0-20240101.zip"
```

---

### 4. Push Release - Queue Job 🔒

**Endpoint:** `POST /api/push`

**Description:** Queue a new ROM release for deployment from pre-release to production.

**Authentication:** 
- **evoxupdater user-agent**: Auto-authorized (no token required)
- **Other clients**: Requires `Authorization: Bearer TOKEN` or `X-API-Key: TOKEN` header

**Headers:**
```
Content-Type: application/json
```

**Request Body:**
```json
{
  "codename": "OnePlus6",
  "date": "20240101",
  "version": "9.0",
  "buildType": "OFFICIAL"
}
```

**Fields:**

| Field | Type | Required | Description | Example |
|-------|------|----------|-------------|---------|
| `codename` | string | Yes | Device codename | `OnePlus6` |
| `date` | string | Yes | Build date (YYYYMMDD) | `20240101` |
| `version` | string | Yes | Android version | `9.0` |
| `buildType` | string | Yes | Build type | `OFFICIAL`, `UNOFFICIAL`, `GAPPS` |

**Response:**
```json
{
  "status": "success",
  "APICode": "T-0007",
  "message": "Push release queued successfully",
  "jobId": 42,
  "queuePosition": 3
}
```

**Error Response:**
```json
{
  "status": "error",
  "APICode": "T-0003",
  "message": "Authentication failed"
}
```

**Example with Bearer Token:**
```bash
curl -X POST "http://localhost:8000/api/push" \
  -H "Authorization: Bearer YOUR_SECRET_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "codename": "OnePlus6",
    "date": "2024-01-01",
    "version": "9",
    "buildType": "OFFICIAL"
  }'
```

**Example with evoxupdater user-agent:**
```bash
curl -X POST "http://localhost:8000/api/push" \
  -H "User-Agent: evoxupdater/1.0" \
  -H "Content-Type: application/json" \
  -d '{
    "codename": "OnePlus6",
    "date": "2024-01-01",
    "version": "9",
    "buildType": "OFFICIAL"
  }'
```

---

### 5. Push Release - List Jobs 🔒

**Endpoint:** `GET /api/push/jobs`

**Description:** List push release jobs with optional filtering.

**Authentication:** Required

**Query Parameters:**

| Parameter | Type | Required | Description | Values |
|-----------|------|----------|-------------|--------|
| `status` | string | No | Filter by job status | `queued`, `processing`, `completed` |
| `limit` | integer | No | Result limit (default: 100, max: 500) | `50` |

**Response:**
```json
{
  "success": true,
  "data": {
    "jobs": [
      {
        "id": 42,
        "codename": "OnePlus6",
        "release_date": "2024-01-01",
        "version": "9.0",
        "build_type": "OFFICIAL",
        "requested_by": "jenkins",
        "source_path": "/mnt/pre-release/OnePlus/OnePlus6/...",
        "destination_path": "/mnt/evolution-x/OnePlus/OnePlus6/...",
        "status": "completed",
        "success": true,
        "error_message": null,
        "callback_enabled": true,
        "callback_url": "https://jenkins.com/webhook",
        "callback_http_code": 200,
        "callback_response": "OK",
        "created_at": "2024-01-01 10:00:00",
        "started_at": "2024-01-01 10:01:00",
        "completed_at": "2024-01-01 10:05:00",
        "updated_at": "2024-01-01 10:05:00"
      }
    ],
    "count": 1
  }
}
```

**Examples:**

List all jobs:
```bash
curl "http://localhost:8000/api/push/jobs" \
  -H "Authorization: Bearer YOUR_SECRET_TOKEN"
```

List queued jobs only:
```bash
curl "http://localhost:8000/api/push/jobs?status=queued" \
  -H "Authorization: Bearer YOUR_SECRET_TOKEN"
```

---

### 6. Push Release - Get Job Details 🔒

**Endpoint:** `GET /api/push/jobs/{id}`

**Description:** Get detailed information about a specific job.

**Authentication:** Required

**Response:**
```json
{
  "success": true,
  "data": {
    "job": {
      "id": 42,
      "codename": "OnePlus6",
      "release_date": "2024-01-01",
      "version": "9.0",
      "build_type": "OFFICIAL",
      "status": "completed",
      "success": true,
      "created_at": "2024-01-01 10:00:00",
      "completed_at": "2024-01-01 10:05:00"
    }
  }
}
```

**Example:**
```bash
curl "http://localhost:8000/api/push/jobs/42" \
  -H "Authorization: Bearer YOUR_SECRET_TOKEN"
```

---

### 7. Push Release - Queue Statistics 🔒

**Endpoint:** `GET /api/push/stats`

**Description:** Get statistics about the push release queue.

**Authentication:** Required

**Response:**
```json
{
  "success": true,
  "data": {
    "queued": 3,
    "processing": 1,
    "completed_today": 15,
    "completed_this_week": 87,
    "completed_total": 1523,
    "failed_today": 0,
    "failed_this_week": 2,
    "failed_total": 45,
    "average_processing_time": "4m 32s",
    "oldest_queued_job": {
      "id": 40,
      "created_at": "2024-01-01 09:00:00",
      "waiting_time": "1h 5m"
    }
  }
}
```

**Example:**
```bash
curl "http://localhost:8000/api/push/stats" \
  -H "Authorization: Bearer YOUR_SECRET_TOKEN"
```

---

### 8. Daily Downloads

**Endpoint:** `GET /api/daily-downloads`

**Description:** Get per-file download statistics for a single day.

**Authentication:** None

**Path Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `{date}` | string | No | Date in `YYYY-MM-DD` format; defaults to yesterday |

**Response:**
```json
{
  "fordate": "2024-01-01",
  "summary": {
    "total_downloads": 1523,
    "unique_files_downloaded": 245,
    "total_download_events": 1523
  },
  "individual_files": [
    {
      "filename": "OnePlus/OnePlus6/evolution-x-8.0-OnePlus6-20240101.zip",
      "downloads": 168
    },
    {
      "filename": "Google/akita/evolution-x-16-akita-20240101.zip",
      "downloads": 121
    }
  ]
}
```

**Examples:**
```bash
curl "http://localhost:8000/api/daily-downloads"
```

```bash
curl "http://localhost:8000/api/daily-downloads/2024-01-01"
```

---

### 9. Daily Summary

**Endpoint:** `GET /api/daily-summary`

**Description:** Get daily totals for the last 7 days by default.

**Authentication:** None

**Path Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `{days}` | integer | No | Number of days to summarize; defaults to `7`, allowed range `1-30` |

**Response:**
```json
{
  "period": "7 days",
  "daily_summary": [
    {
      "date": "2024-01-07",
      "unique_files": 180,
      "total_downloads": 1321
    },
    {
      "date": "2024-01-06",
      "unique_files": 176,
      "total_downloads": 1288
    }
  ]
}
```

**Examples:**
```bash
curl "http://localhost:8000/api/daily-summary"
```

```bash
curl "http://localhost:8000/api/daily-summary/14"
```

---

## Rate Limiting

Currently, no rate limiting is enforced. However, please be considerate:
- Cache responses where possible
- Use appropriate polling intervals
- Don't hammer the API needlessly

Future versions may implement rate limiting.

---

## CORS

CORS is enabled for the following origins:
- `https://evolution-x.org`
- `https://cdn.evolution-x.org`
- `http://localhost:3000`
- `http://localhost:8000`

Other origins receive `Access-Control-Allow-Origin: *` for GET requests.

---

## Webhooks

### Jenkins Callback (Push Release)

When a push release job completes, the system can send a callback to Jenkins:

**Callback Request:**
```http
POST {callback_url}
Content-Type: application/json

{
  "jobId": 42,
  "status": "completed",
  "success": true,
  "codename": "OnePlus6",
  "version": "9.0",
  "buildType": "OFFICIAL",
  "date": "20240101",
  "started_at": "2024-01-01T10:01:00Z",
  "completed_at": "2024-01-01T10:05:00Z",
  "duration_seconds": 240
}
```

Configure the callback URL in the push release queue job or in the system configuration.

---

## Client Examples

### Python

```python
import requests
import json

# Health check
response = requests.get('http://localhost:8000/api/health')
health = response.json()
print(f"Database: {health['database']['status']}")

# Download statistics
params = {
    'folder': 'OnePlus/OnePlus6',
    'timeStart': '2024-01-01',
    'timeEnd': '2024-12-31',
    'limit': 10
}
response = requests.get(
  'http://localhost:8000/api/download-statistics',
    params=params
)
stats = response.json()
for folder_stat in stats:
    print(f"{folder_stat['folder']}: {folder_stat['downloadCount']} downloads")

# Push release
headers = {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer YOUR_TOKEN'
}
data = {
    'codename': 'OnePlus6',
    'date': '20240101',
    'version': '9.0',
    'buildType': 'OFFICIAL'
}
response = requests.post(
    'http://localhost:8000/api/push',
    headers=headers,
    json=data
)
result = response.json()
print(f"Job ID: {result['jobId']}")
```

### JavaScript (Node.js)

```javascript
const axios = require('axios');

// Health check
async function checkHealth() {
  const response = await axios.get('http://localhost:8000/api/health');
  console.log('Database:', response.data.database.status);
}

// Download statistics
async function getStats() {
  const response = await axios.get('http://localhost:8000/api/download-statistics', {
    params: {
      folder: 'OnePlus/OnePlus6',
      limit: 10
    }
  });
  response.data.forEach(stat => {
    console.log(`${stat.folder}: ${stat.downloadCount} downloads`);
  });
}

// Push release
async function queueRelease() {
  const response = await axios.post(
    'http://localhost:8000/api/push',
    {
      codename: 'OnePlus6',
      date: '20240101',
      version: '9.0',
      buildType: 'OFFICIAL'
    },
    {
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer YOUR_TOKEN'
      }
    }
  );
  console.log('Job ID:', response.data.jobId);
}
```

### cURL

```bash
# Health check
curl http://localhost:8000/api/health

# Download statistics with filters
curl "http://localhost:8000/api/download-statistics?folder=OnePlus/OnePlus6&limit=10"

# Download statistics using the alias
curl "http://localhost:8000/api/download-stats?folder=OnePlus/OnePlus6&limit=10"

# Single-day detailed downloads
curl "http://localhost:8000/api/daily-downloads/2024-01-01"

# 14-day summary
curl "http://localhost:8000/api/daily-summary/14"

# File hashes
curl "http://localhost:8000/api/hash?file=OnePlus/OnePlus6/file.zip"

# Push release (POST with JSON)
curl -X POST "http://localhost:8000/api/push?auth=YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "codename": "OnePlus6",
    "date": "20240101",
    "version": "9.0",
    "buildType": "OFFICIAL"
  }'

# List push jobs
curl "http://localhost:8000/api/push/jobs?status=queued&auth=YOUR_TOKEN"

# Get job details
curl "http://localhost:8000/api/push/jobs/42?auth=YOUR_TOKEN"
```

---

## Error Handling

All endpoints return appropriate HTTP status codes:

- `200 OK` - Success
- `202 Accepted` - Request accepted, processing
- `400 Bad Request` - Invalid parameters
- `401 Unauthorized` - Authentication failed
- `404 Not Found` - Resource not found
- `405 Method Not Allowed` - Wrong HTTP method
- `500 Internal Server Error` - Server error

Always check the `success` field in JSON responses:

```javascript
if (response.data.success) {
  // Handle success
  console.log(response.data.data);
} else {
  // Handle error
  console.error(response.data.error);
  console.error('Error Code:', response.data.APICode);
}
```

---

## Best Practices

1. **Cache responses** - Download statistics don't change frequently
2. **Use appropriate filters** - Don't request more data than needed
3. **Handle errors gracefully** - Check HTTP status and `success` field
4. **Respect the API** - Don't poll excessively
5. **Use webhooks** - Instead of polling for job status
6. **Set timeouts** - Hash calculation can take time for large files
7. **Validate input** - Before sending requests

---

## Changelog

### v2.0.0 (2024-01-01)
- Initial API documentation
- Push release queue system
- Enhanced statistics endpoints
- Job tracking and management

---

**Last Updated:** 2026-03-10
