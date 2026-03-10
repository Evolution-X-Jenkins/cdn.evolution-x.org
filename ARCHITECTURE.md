# Architecture & Design Documentation

Technical architecture and design decisions for PHP File Browser V2.

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Design Principles](#design-principles)
3. [Architecture Patterns](#architecture-patterns)
4. [Module Structure](#module-structure)
5. [Data Flow](#data-flow)
6. [Caching Architecture](#caching-architecture)
7. [Database Design](#database-design)
8. [API Design](#api-design)
9. [Queue System](#queue-system)
10. [Security Architecture](#security-architecture)
11. [Scalability Considerations](#scalability-considerations)
12. [Design Decisions](#design-decisions)

---

## System Overview

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                         Client Layer                        │
│  (Web Browser, Mobile Apps, CLI Tools, External Services)   │
└─────────────────────────┬───────────────────────────────────┘
                          │ HTTP/HTTPS
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                      Web Server Layer                       │
│              (Nginx/Apache + PHP-FPM)                       │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                        │
│  ┌───────────────┐  ┌───────────────┐  ┌───────────────┐    │
│  │   router.php  │  │   index.php   │  │    api.php    │    │
│  │  (Dev Router) │  │ (Main Router) │  │  (API Router) │    │
│  └───────────────┘  └───────────────┘  └───────────────┘    │
│                          │                                  │
│  ┌───────────────────────┴───────────────────────────────┐  │
│  │              Module Controllers                       │  │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────────────┐     │  │
│  │  │   Core   │  │   API    │  │      Setup       │     │  │
│  │  └──────────┘  └──────────┘  └──────────────────┘     │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────┬───────────────────────────────────┘
                          │
        ┌─────────────────┼─────────────────┐
        │                 │                 │
        ▼                 ▼                 ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│ Redis Cache  │  │   Database   │  │ File Storage │
│  (Tier 1)    │  │ MySQL/SQLite │  │  (R2/Local)  │
└──────────────┘  └──────────────┘  └──────────────┘
        │
        ▼
┌──────────────┐
│  File Cache  │
│  (Tier 2)    │
└──────────────┘
```

### Component Responsibilities

| Component | Responsibility |
|-----------|---------------|
| **Router** | Request routing, URL parsing, authentication |
| **Controllers** | Business logic, data processing, response formatting |
| **Models** | Data access, database operations, validation |
| **Cache** | Performance optimization, data persistence |
| **Storage** | File operations, R2 integration |
| **Queue** | Async job processing, long-running tasks |

---

## Design Principles

### 1. Modularity
- **Separation of Concerns**: Each module has a single, well-defined purpose
- **Loose Coupling**: Modules communicate through well-defined interfaces
- **High Cohesion**: Related functionality is grouped together

### 2. Performance First
- **Multi-tier Caching**: Redis → File → Database hierarchy
- **Lazy Loading**: Calculate expensive data only when needed
- **Background Processing**: Long-running tasks in queue system
- **Query Optimization**: Indexed queries, prepared statements

### 3. Reliability
- **Graceful Degradation**: System continues with reduced functionality if components fail
- **Error Handling**: Comprehensive exception handling and logging
- **Failover**: Automatic fallback between cache tiers
- **Idempotency**: Queue jobs can be safely retried

### 4. Security
- **Input Validation**: All user input is validated and sanitized
- **Path Sanitization**: Prevent directory traversal attacks
- **Authentication**: Token-based auth for sensitive operations
- **Least Privilege**: Database users have minimal required permissions

### 5. Maintainability
- **Clear Code Structure**: Logical organization of files and modules
- **Documentation**: Inline comments and external documentation
- **Consistent Naming**: Predictable function and variable names
- **Version Control**: Git-based workflow

---

## Architecture Patterns

### 1. Front Controller Pattern

All requests flow through a single entry point:

```
HTTP Request
    ↓
router.php (dev server only)
    ↓
index.php (main entry)
    ↓
Route to appropriate handler
```

**Benefits:**
- Centralized request handling
- Easy to add middleware (auth, logging)
- Consistent error handling

### 2. Module Pattern

Functionality is organized into self-contained modules:

```
modules/
  ├── setup/      # Configuration and database
  ├── core/       # Core business logic
  └── api/        # API endpoints
```

**Benefits:**
- Clear separation of concerns
- Easy to test individual modules
- Reusable components

### 3. Singleton Pattern

Used for shared resources (Database, Cache):

```php
$db = Database::getInstance();
$cache = CacheManager::getInstance();
```

**Benefits:**
- Single connection/instance
- Resource efficiency
- Consistent state

### 4. Strategy Pattern (Caching)

Multiple cache implementations with fallback:

```
Redis (try first)
  ↓ (on failure)
File Cache (try second)
  ↓ (on failure)
Database (source of truth)
```

**Benefits:**
- Flexible caching strategy
- Graceful degradation
- Easy to add new cache types

### 5. Queue Pattern (Push Release)

Asynchronous job processing:

```
API Request → Queue → Background Worker → Callback
```

**Benefits:**
- Non-blocking API responses
- Retry capability
- Job tracking and monitoring

---

## Module Structure

### Setup Module (`modules/setup/`)

**Purpose:** Configuration and database management

**Files:**
- `config.php` - Application configuration
- `database.php` - Database abstraction layer
- `r2_download.php` - R2/S3 client wrapper

**Pattern:** Singleton for database connections

### Core Module (`modules/core/`)

**Purpose:** Core business logic

**Files:**
- `bucket_cache.php` - Storage size caching
- `cache.php` - Multi-tier cache manager
- `download.php` - Download page rendering
- `file_operations.php` - File hash calculation
- `health.php` - Health check implementation

**Pattern:** Service classes with static methods

### API Module (`modules/api/`)

**Purpose:** RESTful API endpoints

**Files:**
- `download.php` - Download statistics
- `file_operations.php` - File operations API
- `health.php` - Health check endpoint
- `push.php` - Push release management
- `daily_downloads.php` - Time-series statistics
- `hash_management.php` - File hash API

**Pattern:** Handler functions per endpoint

---

## Data Flow

### File Browsing Flow

```
1. User navigates to /path/to/folder
   ↓
2. index.php parses URL
   ↓
3. Check if path exists
   ↓
4. List directory contents
   ↓
5. Generate breadcrumb navigation
   ↓
6. Render list.php template
   ↓
7. Return HTML response
```

### Download Flow

```
1. User clicks download link
   ↓
2. Check user agent (bypass for curl/wget/etc)
   ↓
3. If human user: Show countdown page
   ↓
4. Record download in database
   ↓
5. Generate presigned R2 URL (if using R2)
   ↓
6. Redirect to download URL
   ↓
7. Update statistics (async)
```

### API Statistics Flow

```
1. GET /api/download-statistics?folder=X
   ↓
2. Parse and validate parameters
   ↓
3. Check cache (Redis)
   ↓ (on miss)
4. Check file cache
   ↓ (on miss)
5. Query database (download_stats_cache table)
   ↓ (on miss)
6. Aggregate from download_stats (heavy query)
   ↓
7. Store in cache (backfill levels)
   ↓
8. Return JSON response
```

### Push Release Flow

```
1. POST /api/push with release data
   ↓
2. Authenticate request
   ↓
3. Validate parameters
   ↓
4. Insert into push_release_queue (status: queued)
   ↓
5. Return job ID immediately
   ↓
[Background Worker - cron_push_queue.php]
   ↓
6. Fetch next queued job
   ↓
7. Update status to 'processing'
   ↓
8. Copy files from pre-release to production
   ↓
9. Update status to 'completed' or 'failed'
   ↓
10. Send callback to Jenkins (if configured)
   ↓
11. Send Discord failure webhook (if configured and job failed)
   ↓
12. Log results
```

---

## Caching Architecture

### Cache Hierarchy

```
┌──────────────────────────────────────┐
│       Application Request            │
└──────────────┬───────────────────────┘
               │
               ▼
┌──────────────────────────────────────┐
│  Redis Cache (Tier 1)                │
│  - In-memory                          │
│  - ~1ms access time                   │
│  - Volatile (lost on restart)         │
└──────────────┬───────────────────────┘
               │ (on miss)
               ▼
┌──────────────────────────────────────┐
│  File Cache (Tier 2)                 │
│  - JSON files in data/cache/          │
│  - ~10ms access time                  │
│  - Persistent (survives restarts)     │
└──────────────┬───────────────────────┘
               │ (on miss)
               ▼
┌──────────────────────────────────────┐
│  Database (Source of Truth)          │
│  - MySQL or SQLite                    │
│  - ~50ms access time                  │
│  - Persistent, reliable               │
└──────────────┬───────────────────────┘
               │ (on miss)
               ▼
┌──────────────────────────────────────┐
│  Calculate/Fetch (Expensive)         │
│  - Query aggregation                  │
│  - File system scan                   │
│  - Hash calculation                   │
└──────────────────────────────────────┘
```

### Cache Operations

**Read Path:**
1. Try Redis: `return if found`
2. Try File: `backfill Redis, return if found`
3. Try Database: `backfill File + Redis, return if found`
4. Calculate: `backfill all levels, return result`

**Write Path:**
1. Write to Database (source of truth)
2. Update File cache
3. Update Redis cache

**Invalidation:**
- Explicit invalidation on data changes
- No automatic TTL (persist until invalidated)
- Cron jobs refresh stale data

### Cache Key Design

Pattern: `{type}_{identifier}_{subtype}`

Examples:
```
download_stats_7day_evolution-x-8.0-OnePlus6.zip
bucket_size
file_hashes_OnePlus/OnePlus6/evolution-x-8.0.zip
push_job_42
```

**Benefits:**
- Easy to understand
- Allows pattern-based invalidation
- Namespace separation

---

## Database Design

### Entity Relationship Diagram

```
┌────────────────────┐         ┌──────────────────────┐
│  download_stats    │         │  download_stat       │
├────────────────────┤         ├──────────────────────┤
│ id (PK)            │         │ key (PK)             │
│ filename           │────┐    │ count                │
│ user_agent         │    │    │ sha256               │
│ ip_address         │    │    │ md5                  │
│ download_time      │    │    │ file_size            │
└────────────────────┘    │    │ last_updated         │
                          │    └──────────────────────┘
                          │
                          │    ┌──────────────────────┐
                          └───▶│ download_stats_cache │
                               ├──────────────────────┤
                               │ filename (PK)        │
                               │ downloads_7day       │
                               │ downloads_alltime    │
                               │ cached_at            │
                               └──────────────────────┘

┌────────────────────────────────────┐
│  push_release_queue                │
├────────────────────────────────────┤
│ id (PK)                            │
│ codename                           │
│ release_date                       │
│ version                            │
│ build_type                         │
│ requested_by                       │
│ source_path                        │
│ destination_path                   │
│ status [queued|processing|complete]│
│ success                            │
│ error_message                      │
│ callback_enabled                   │
│ callback_url                       │
│ callback_http_code                 │
│ callback_response                  │
│ created_at                         │
│ started_at                         │
│ completed_at                       │
│ updated_at                         │
└────────────────────────────────────┘
```

### Table Purposes

**download_stats** (Individual Records)
- Stores each download event
- Used for detailed analytics
- Time-series data
- Supports date range queries

**download_stat** (Aggregated Totals)
- Pre-aggregated download counts
- Fast lookups for total downloads
- Stores file metadata (hashes, size)
- Updated on each download

**download_stats_cache** (Performance Cache)
- 7-day and all-time statistics
- Updated by cron job
- Fast API responses
- Reduces expensive aggregation queries

**push_release_queue** (Job Queue)
- Stores push release jobs
- Tracks job lifecycle
- Enables retry and monitoring
- Callback integration

### Indexing Strategy

```sql
-- download_stats
CREATE INDEX idx_filename ON download_stats(filename);
CREATE INDEX idx_download_time ON download_stats(download_time);
CREATE INDEX idx_filename_time ON download_stats(filename, download_time);

-- push_release_queue
CREATE INDEX idx_status_created ON push_release_queue(status, created_at);
CREATE INDEX idx_created ON push_release_queue(created_at);

-- download_stats_cache
CREATE INDEX idx_cached_at ON download_stats_cache(cached_at);
```

**Benefits:**
- Fast queries on common filters
- Efficient sorting
- Composite indexes for multi-column queries

---

## API Design

### RESTful Principles

| Method | Purpose | Idempotent |
|--------|---------|------------|
| GET | Read data | Yes |
| POST | Create/Action | No |
| PUT | Update (replace) | Yes |
| DELETE | Remove | Yes |

### Endpoint Naming

Pattern: `/api/{resource}/{action}`

Examples:
```
GET  /api/health              # Check system health
GET  /api/download-statistics # Get download stats
GET  /api/download-stats      # Alias for download statistics
GET  /api/daily-downloads     # Get one day's per-file download stats
GET  /api/daily-summary       # Get multi-day download totals
POST /api/push                # Queue push release
GET  /api/push/jobs           # List jobs
GET  /api/push/jobs/42        # Get specific job
GET  /api/hash?file=path      # Get file hashes
```

### Response Format

**Success:**
```json
{
  "success": true,
  "data": {
    // Response data
  }
}
```

**Error:**
```json
{
  "success": false,
  "error": "Human readable message",
  "APICode": "T-XXXX"
}
```

### API Code System

Format: `T-XXXX`

| Code | Meaning | HTTP Status |
|------|---------|-------------|
| T-0001 | Database/Internal error | 500 |
| T-0002 | Bad request | 400 |
| T-0003 | Unauthorized | 401 |
| T-0004 | Method not allowed | 405 |
| T-0005 | Not found | 404 |
| T-0006 | Internal server error | 500 |
| T-0007 | Accepted/Processing | 202 |

**Benefits:**
- Machine-readable error codes
- Easy to document and handle
- Consistent across API

---

## Queue System

### Architecture

```
┌──────────────┐    ┌──────────────────┐    ┌──────────────┐
│  API Request │───▶│  Queue (DB)      │◀───│ Cron Worker  │
│  (Immediate) │    │  - queued        │    │ (Every 1min) │
└──────────────┘    │  - processing    │    └──────────────┘
                    │  - completed     │
                    │  - failed        │
                    └──────────────────┘
                            │
                            ▼
                    ┌──────────────────┐
                    │  Callback        │
                    │  (To Jenkins)    │
                    └──────────────────┘
```

### State Machine

```
[queued] ──┬──> [processing] ──┬──> [completed]
           │                   └──> [failed]
           │
           └──> [queued] (if worker crashes, re-queued)
```

### Job Processing Logic

```php
function processNextQueuedPushReleaseJob() {
    // 1. Fetch next job with status=queued (FIFO)
    $job = fetchOldestQueuedJob();
    
    if (!$job) {
        return ['status' => 'idle', 'message' => 'No jobs in queue'];
    }
    
    // 2. Update status to 'processing'
    updateJobStatus($job->id, 'processing');
    
    try {
        // 3. Perform file copy operation
        $result = copyFilesFromPreReleaseToProduction($job);
        
      // 4. Update status to 'completed' or 'failed'
      updateJobStatus($job->id, $result['success'] ? 'completed' : 'failed', $result['success']);
        
      // 5. Send callback if configured
        if ($job->callback_enabled) {
            sendCallbackToJenkins($job, $result);
        }

      // 6. Send Discord webhook on failure if configured
      if (!$result['success']) {
         sendDiscordFailureWebhook($job, $result);
      }
        
        return ['status' => 'processed', 'jobId' => $job->id];
        
    } catch (Exception $e) {
      // Mark as failed and notify
      updateJobStatus($job->id, 'failed', false, $e->getMessage());
      sendDiscordFailureWebhook($job, ['success' => false, 'error' => $e->getMessage()]);
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
```

### Benefits

- **Non-blocking**: API responds immediately
- **Reliable**: Jobs persisted in database
- **Monitorable**: Track job status and history
- **Retry-able**: Failed jobs can be requeued
- **Scalable**: Multiple workers can process queue

---

## Security Architecture

### Authentication Methods

Push API (`/api/push`) uses priority-based authentication:

1. **User-Agent bypass** (first priority):
   - If `User-Agent` header contains `evoxupdater` → immediately authorized
   - **No token required** for this method
   - Used by Evolution X's official updater client

2. **Token authentication** (fallback for other clients):
   - `Authorization: Bearer YOUR_SECRET_TOKEN` (preferred)
   - `X-API-Key: YOUR_SECRET_TOKEN` (alternative)
   - Token configured via `PUSH_API_TOKEN` environment variable

```php
function resolvePushApiIdentity() {
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    $isEvoxUpdater = strpos($userAgent, 'evoxupdater') !== false;

    // Priority 1: evoxupdater bypass (no token needed)
    if ($isEvoxUpdater) {
        return [
            'authorized' => true,
            'username' => 'evoxupdater'
        ];
    }

    // Priority 2: Token authentication (for other clients)
    $authToken = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    $authToken = str_replace('Bearer ', '', $authToken);

    if (!validatePushToken($authToken)) {
        return ['authorized' => false, 'username' => 'unknown'];
    }

    return ['authorized' => true, 'username' => 'api_user'];
}
```

### Path Sanitization

```php
function sanitize_path($path, $base_path) {
    // Remove '..' and other dangerous patterns
    $path = str_replace('..', '', $path);
    
    // Build full path
    $full_path = realpath($base_path . '/' . $path);
    
    // Ensure path is within base_path
    if (strpos($full_path, $base_path) !== 0) {
        throw new Exception('Invalid path');
    }
    
    return $full_path;
}
```

### SQL Injection Prevention

Always use prepared statements:

```php
$stmt = $db->prepare('SELECT * FROM files WHERE filename = ?');
$stmt->execute([$filename]);
```

### CORS Configuration

```php
$allowedOrigins = [
    'https://evolution-x.org',
    'https://cdn.evolution-x.org'
];

if (in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
} else {
    header("Access-Control-Allow-Origin: *"); // GET requests only
}
```

---

## Scalability Considerations

### Horizontal Scaling

**Current State:** Single server

**Path to Multi-Server:**

1. **Database:** 
   - Already using centralized MySQL
   - Add read replicas for queries
   - Master for writes

2. **Cache:**
   - Redis already centralized
   - Can scale Redis with clustering

3. **File Storage:**
   - Already using R2 (distributed)
   - Stateless file serving

4. **Queue Workers:**
   - Multiple workers can process queue
   - Add more servers running cron_push_queue.php
   - Database row-locking prevents conflicts

5. **Load Balancer:**
   - Add Nginx/HAProxy in front
   - Session-less design (stateless)
   - No sticky sessions needed

### Vertical Scaling

**Resource Bottlenecks:**

1. **PHP-FPM Workers:**
   - Increase `pm.max_children`
   - Monitor with `php-fpm status`

2. **Database Connections:**
   - Increase `max_connections`
   - Use connection pooling

3. **Redis Memory:**
   - Increase `maxmemory`
   - Monitor with `redis-cli info memory`

4. **Disk I/O:**
   - SSD for database and cache
   - Separate volumes for logs

### Performance Metrics

**Target Response Times:**
- File listing: < 100ms
- Download page: < 200ms
- API statistics (cached): < 50ms
- API statistics (uncached): < 500ms
- Health check: < 100ms

**Monitoring:**
```php
$start = microtime(true);
// ... operation ...
$duration = microtime(true) - $start;
error_log("Operation took: {$duration}s");
```

---

## Design Decisions

### Why PHP?

**Pros:**
- Mature ecosystem for web applications
- Easy deployment (shared hosting compatible)
- Excellent AWS SDK support
- Familiar to team

**Cons:**
- Not ideal for long-running processes
- Solved with cron-based workers

### Why Multi-Tier Caching?

**Rationale:**
- Redis is fast but volatile
- File cache survives restarts
- Database is source of truth
- Graceful degradation if Redis fails

**Trade-off:**
- More complexity
- Benefit: Better reliability and performance

### Why Queue System for Push Releases?

**Alternatives Considered:**
1. Synchronous processing (blocking)
2. Background process (PHP exec)
3. **Queue system (chosen)**

**Rationale:**
- Non-blocking API responses
- Trackable job status
- Retry capability
- Clean shutdown handling
- No orphan processes

### Why Separate Statistics Tables?

**download_stats** vs **download_stat** vs **download_stats_cache**

**Rationale:**
- **download_stats**: Detailed records for analytics
- **download_stat**: Fast aggregated totals
- **download_stats_cache**: Pre-computed 7-day stats

**Trade-off:**
- Storage overhead
- Benefit: Query performance (100x faster)

### Why Cloudflare R2?

**Alternatives:**
- AWS S3: More expensive
- Local storage: Not scalable
- **R2 (chosen)**: S3-compatible, free egress

**Rationale:**
- Cost-effective for CDN
- S3-compatible API
- Global distribution
- Free bandwidth

---

## Future Enhancements

### Planned Features

1. **Image Thumbnails**: Generate thumbnails for ROM screenshots
2. **Torrent Support**: Generate .torrent files for large ROMs
3. **Multi-CDN**: Automatic failover between multiple CDN providers
4. **GraphQL API**: Alternative to REST for complex queries
5. **WebSocket**: Real-time job status updates
6. **Admin Dashboard**: Web UI for monitoring and management

### Technical Debt

1. **Test Coverage**: Add unit and integration tests
2. **PSR Compliance**: Align with PSR-4 autoloading
3. **Dependency Injection**: Replace singletons with DI container
4. **ORM**: Consider Doctrine or Eloquent for database
5. **Framework**: Evaluate migration to Symfony/Laravel

---

## Contributing

When contributing, please follow:

1. **Architecture patterns** defined in this document
2. **Modular structure** - new features in appropriate module
3. **Caching strategy** - use multi-tier cache for expensive operations
4. **Database design** - add indexes, use prepared statements
5. **API conventions** - RESTful design, consistent error codes
6. **Security practices** - sanitize input, validate authentication

---

**Document Version:** 2.0  
**Last Updated:** 2026-03-10  
**Maintained By:** Evolution X CDN Team
