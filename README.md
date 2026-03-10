# PHP File Browser V2 - Evolution X CDN

A comprehensive PHP-based file browser and download management system for Evolution X ROM files, integrated with Cloudflare R2 storage and featuring advanced caching, download statistics, and push release management.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Architecture](#architecture)
- [File Structure](#file-structure)
- [Core Components](#core-components)
- [API Endpoints](#api-endpoints)
- [Installation](#installation)
- [Configuration](#configuration)
- [Cron Jobs](#cron-jobs)
- [Database Schema](#database-schema)
- [Caching Strategy](#caching-strategy)
- [Development](#development)

---

## Overview

This application provides a web-based interface for browsing, downloading, and managing ROM files stored on Cloudflare R2 (S3-compatible storage). It includes sophisticated download tracking, multi-tier caching, push release automation, and comprehensive API endpoints for integration with external systems.

## Key Features

### 🚀 Core Functionality
- **File Browsing**: Clean web interface for browsing directories and files
- **Smart Downloads**: Countdown-based downloads with user-agent detection for automated tools
- **Download Statistics**: Comprehensive tracking of downloads with time-series data
- **Pre-release Management**: Separate handling for pre-release builds
- **Health Monitoring**: Built-in health checks for all system components

### 📊 Advanced Features
- **Multi-tier Caching**: Redis → File → Database fallback for optimal performance
- **Push Release Queue**: Automated deployment pipeline for new ROM releases
- **File Hash Management**: MD5, SHA1, SHA256 hash calculation and caching
- **Bucket Size Caching**: Background calculation of storage usage
- **Download Method Detection**: Client-side detection of optimal download methods
- **Fallback Domains**: Multiple CDN endpoints for reliability

### 🔧 Developer Features
- **RESTful API**: Comprehensive API for all operations
- **Jenkins Integration**: Webhook support for CI/CD pipelines
- **Queue-based Processing**: Background job processing for long-running tasks
- **Extensible Module System**: Modular architecture for easy extension

---

## Architecture

### Request Flow

```
User Request
    ↓
router.php (Development Server Router)
    ↓
index.php (Main Router)
    ├── /api/* → api.php (API Router)
    ├── /health → modules/core/health.php
    ├── /pre-release/* → Pre-release handler
    └── /* → File browser / Download handler
```

### Caching Hierarchy

```
Request for Data
    ↓
Redis Cache (fastest, volatile)
    ↓ (on miss)
File Cache (persistent, survives reboots)
    ↓ (on miss)
Database (source of truth)
    ↓ (on miss)
Calculate/Fetch (and backfill all caches)
```

---

## File Structure

### Root Files

#### Main Application Files

**api.php**
- Main API router handling all `/api/*` endpoints
- Implements CORS headers for cross-origin requests
- Routes requests to appropriate module handlers
- Modules: download, file_operations, health, push, daily_downloads, hash_management

**index.php**
- Primary request router for the application
- Handles URL parsing and routing decisions
- Routes to: API, health checks, pre-release, downloads, file listings
- Implements user-agent based download bypass logic

**router.php**
- Development server router for PHP's built-in server
- Routes API calls to api.php
- Serves static files directly
- Routes all other requests to index.php

**list.php**
- File listing functionality
- Generates breadcrumb navigation
- Sorts directories and files appropriately
- Displays file icons and metadata

#### Automation Scripts

**cron.php**
- Main cron job script for system maintenance
- Updates bucket cache in background
- Cleans up expired database entries
- Builds 7-day download statistics cache for all files
- Caches data in Redis, file system, and JSON format
- Should run every 15 minutes via crontab

**cron_push_queue.php**
- Push release queue worker
- Processes queued ROM release deployments
- Handles callbacks to external systems (Jenkins)
- Comprehensive logging of job processing
- Should run every minute via crontab

**generate_hashes.php**
- CLI utility for bulk hash generation
- Scans directory tree and calculates file hashes
- Generates SQL import scripts (MySQL or SQLite)
- Supports MD5, SHA1, SHA256 algorithms
- Usage: `php generate_hashes.php [output.sql] [--mysql|--sqlite]`

**log_download.php**
- Simple download logging endpoint
- Accepts POST requests with file information
- Records download initiation events
- Returns JSON success response

**start.sh**
- Bash script to start development server
- Launches PHP built-in server on localhost:8000
- Filters verbose connection logs
- Handles graceful shutdown

#### Configuration

**composer.json**
- PHP dependency management
- Dependencies: AWS SDK for PHP (Cloudflare R2/S3 compatibility)

---

## Core Components

### Modules

The application is organized into modular components under the `modules/` directory:

#### modules/setup/

**config.php**
- Application configuration and constants
- Environment variable loading from .env
- Path definitions (BASE_PATH, PRE_RELEASE_PATH)
- R2/S3 configuration
- Database configuration (MySQL/SQLite)
- Cache configuration (Redis, File, APCu)
- Jenkins integration settings
- Fallback domain definitions

**database.php**
- Database abstraction layer
- Singleton pattern for connection management
- Supports both MySQL and SQLite
- Automatic table creation and migration
- WAL mode optimization for SQLite
- Main tables:
  - `download_stats`: Individual download records
  - `download_stat`: Aggregated statistics with hashes
  - `download_stats_cache`: 7-day cache for UI
  - `push_release_queue`: Release deployment queue
  - `cache_entries`: Cache management

**r2_download.php**
- Cloudflare R2 integration
- S3Client wrapper for R2 operations
- Presigned URL generation
- File listing and metadata retrieval
- Direct download capabilities

#### modules/core/

**bucket_cache.php**
- Background caching of bucket size calculations
- Lock-based concurrent update prevention
- Change detection to avoid unnecessary recalculation
- JSON-based persistent cache
- File modification time tracking
- Cron job integration

**cache.php**
- Multi-tier cache manager (Redis → File → Database)
- Automatic fallback between cache layers
- No TTL - caches persist until cleared
- JSON serialization for complex data
- Automatic backfill of higher-tier caches on hits
- Methods: `get()`, `set()`, `delete()`, `exists()`, `clear()`

**download.php**
- Download page display logic
- File size formatting
- Breadcrumb generation
- Download countdown initialization

**file_operations.php (FileHasher class)**
- File hash calculation with caching
- Multi-algorithm support (MD5, SHA1, SHA256)
- Chunked reading for large files (8MB chunks)
- Background processing for files >500MB
- Database integration for hash persistence
- Pre-release file listing

**health.php**
- System health check implementation
- Checks:
  - Database connectivity
  - Filesystem access (read/write)
  - Pre-release path availability
  - R2 configuration validation
  - PHP extension requirements
  - Push queue status
- Returns detailed status for each component

**update_bucket_cache.php**
- CLI script for manual bucket cache updates
- Force refresh capability
- Detailed logging of update process
- Used by cron jobs

#### modules/api/

**download.php**
- Download statistics API endpoint
- Query parameters:
  - `filename`: Filter by filename pattern
  - `folder`: Filter by folder path
  - `timeStart`, `timeEnd`: Date range filtering (YYYY-MM-DD)
  - `limit`: Result limit (max 1000)
  - `sort`: Sort order
  - `format`: Response format
- Returns aggregated statistics by folder/file
- Supports total downloads without filters
- Uses download_stat table for efficiency

**file_operations.php**
- File hash retrieval API
- File listing endpoints
- Pre-release file management
- File metadata (size, modified time)

**health.php**
- Health check API endpoint (`GET /api/health`)
- Returns JSON status of all system components
- Used for monitoring and alerting

**push.php**
- ROM release deployment API
- Queue-based processing system
- Authentication via query parameter or header
- Job tracking with unique IDs
- Status: queued → processing → completed
- Jenkins callback integration
- Endpoints:
  - `POST /api/push` - Queue new deployment
  - `GET /api/push/jobs` - List jobs
  - `GET /api/push/jobs/{id}` - Get job details
  - `GET /api/push/stats` - Queue statistics

**daily_downloads.php**
- Daily download statistics aggregation
- Time-series data for charts and graphs
- Cached results for performance

**hash_management.php**
- Hash calculation API
- Cache management for hashes
- Bulk hash operations

**store_hashes.php**
- API endpoint for storing calculated hashes
- Updates download_stat table
- Used by background hash calculation workers

### Templates

Located in `templates/`, these PHP templates render HTML pages:

- **layout.php**: Main page layout wrapper
- **list.php**: File/directory listing view
- **download.php**: Download countdown page
- **health.php**: Health status display
- **info.php**: System information page
- **pre_release_list.php**: Pre-release file listing

### Static Assets

Located in `static/`:

- **custom.css**: Custom styling for the application
- **download-method-detector.js**: Client-side download optimization
  - Detects supported download methods (aria2c, wget, curl)
  - Provides appropriate download commands
  - Handles fallback domain selection

---

## API Endpoints

### Download Statistics

```http
GET /api/download-statistics
```

Alias also supported:

```http
GET /api/download-stats
```

Query parameters:
- `filename` - Filter by filename pattern
- `folder` - Filter by folder path
- `timeStart` - Start date (YYYY-MM-DD)
- `timeEnd` - End date (YYYY-MM-DD)
- `limit` - Result limit (default 50, max 1000)
- `sort` - Sort by: downloads, name
- `format` - Response format: summary, detailed

Response format:
```json
[
  {
    "folder": "device/codename",
    "downloadCount": 1234,
    "timeStart": "2024-01-01",
    "timeEnd": "2024-12-31",
    "individualFiles": [
      {
        "filename": "evolution-x-file.zip",
        "downloadCount": 1234
      }
    ]
  }
]
```

### Push Release Management

**Queue a release (with Bearer Token):**
```http
POST /api/push
Authorization: Bearer YOUR_SECRET_TOKEN
Content-Type: application/json

{
  "codename": "device_name",
  "date": "2024-01-01",
  "version": "9",
  "buildType": "OFFICIAL"
}
```

**Or with evoxupdater user-agent (no token needed):**
```http
POST /api/push
User-Agent: evoxupdater/1.0
Content-Type: application/json

{
  "codename": "device_name",
  "date": "2024-01-01",
  "version": "9",
  "buildType": "OFFICIAL"
}
```

**List jobs:**
```http
GET /api/push/jobs?status=queued&limit=100
```

**Get job details:**
```http
GET /api/push/jobs/{job_id}
```

**Queue statistics:**
```http
GET /api/push/stats
```

### Health Check

```http
GET /api/health
```

Returns:
```json
{
  "database": {"status": "healthy", "message": "..."},
  "filesystem": {"status": "healthy", "readable": true, "writable": true},
  "r2": {"status": "healthy", "configured": true},
  "php_extensions": {"status": "healthy", "missing": []},
  "push_queue": {"status": "healthy", "details": {...}}
}
```

### File Hashes

```http
GET /api/hash?file=path/to/file.zip
```

Returns:
```json
{
  "md5": "abc123...",
  "sha1": "def456...",
  "sha256": "789ghi...",
  "file_size": 1234567890,
  "status": "ready"
}
```

---

## Installation

### Prerequisites

- PHP 8.0 or higher
- MySQL 8.0+ or SQLite 3
- Redis (optional, recommended for production)
- Composer
- Cloudflare R2 account (or S3-compatible storage)

### PHP Extensions Required

- pdo
- pdo_mysql or pdo_sqlite
- json
- curl
- redis (optional)
- apcu (optional)

### Installation Steps

1. **Clone the repository:**
   ```bash
   git clone <repository-url>
   cd php_filebrowser_v2
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Configure environment:**
   ```bash
   cp .env.example .env
   nano .env
   ```

4. **Set up database:**
   - For SQLite: Automatic on first run
   - For MySQL: Create database and configure in config.php

5. **Configure paths:**
   Edit `modules/setup/config.php`:
   - Set `BASE_PATH` to your file storage location
   - Set `PRE_RELEASE_PATH` for pre-release builds
   - Configure R2 credentials
   - Set database credentials

6. **Set up cron jobs:**
   ```bash
   crontab -e
   ```
   Add:
   ```
   # Cache maintenance every 15 minutes
   0,15,30,45 * * * * php /path/to/cron.php >> /path/to/logs/cron.log 2>&1
   
   # Push queue processing every minute
   * * * * * php /path/to/cron_push_queue.php >> /path/to/logs/push_queue.log 2>&1
   ```

7. **Start Redis (optional but recommended):**
   ```bash
   redis-server
   redis-cli
   CONFIG SET requirepass "evoCDN"
   ```

8. **Set permissions:**
   ```bash
   chmod -R 755 data/ logs/
   chown -R www-data:www-data data/ logs/
   ```

---

## Configuration

### Environment Variables (.env)

```env
# R2/S3 Configuration
R2_ACCOUNT_ID=your-account-id
R2_ACCESS_KEY=your-access-key-id
R2_SECRET_KEY=your-secret-access-key
R2_BUCKET_NAME=your-bucket-name

# Database (if using MySQL)
DB_HOST=localhost
DB_PORT=3306
DB_NAME=database_name
DB_USER=database_user
DB_PASS=database_password

# Push API Authentication
PUSH_API_TOKEN=your-secret-token

# Jenkins Integration (optional)
JENKINS_URL=https://your-jenkins.com
JENKINS_JOB_NAME=deploy-rom
```

### config.php Settings

Key configuration options in `modules/setup/config.php`:

```php
// Storage paths
define('BASE_PATH', '/mnt/evolution-x');
define('PRE_RELEASE_PATH', '/mnt/pre-release');

// Database type: 'mysql' or 'sqlite'
define('DB_TYPE', 'mysql');

// Cache configuration
define('CACHE_REDIS_ENABLED', true);
define('CACHE_REDIS_HOST', 'localhost');
define('CACHE_REDIS_PASSWORD', 'evoCDN');

define('CACHE_FILE_ENABLED', true);
define('CACHE_FILE_DIR', __DIR__ . '/../../data/cache');

// Fallback domains for download redundancy
define('FALLBACK_DOMAINS', [
    'primary-cdn.example.com',
    'backup-cdn.example.com',
    'r2-direct.example.com'
]);

// Presigned URL expiry (seconds)
define('PRESIGNED_URL_EXPIRY', 3600);
```

---

## Cron Jobs

### Main Maintenance Cron (cron.php)

**Purpose:** System maintenance and cache updates

**Frequency:** Every 15 minutes

**Tasks:**
1. Update bucket size cache with change detection
2. Clean up expired database entries
3. Build 7-day download statistics cache for all files
4. Update Redis, file, and JSON caches
5. Log all operations

**Crontab entry:**
```cron
0,15,30,45 * * * * php /path/to/cron.php
```

**Log location:** `logs/cron_YYYY-MM-DD.log`

### Push Queue Worker (cron_push_queue.php)

**Purpose:** Process queued ROM release deployments

**Frequency:** Every minute

**Tasks:**
1. Fetch next queued job from push_release_queue
2. Copy files from pre-release to production
3. Update job status (queued → processing → completed)
4. Send callback to Jenkins if configured
5. Log all operations with detailed status

**Crontab entry:**
```cron
* * * * * php /path/to/cron_push_queue.php
```

**Log location:** `logs/push_queue_YYYY-MM-DD.log`

---

## Database Schema

### download_stats
```sql
CREATE TABLE download_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename VARCHAR(1024) NOT NULL,
    user_agent TEXT,
    ip_address VARCHAR(45),
    download_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_filename (filename),
    INDEX idx_download_time (download_time)
);
```

### download_stat
```sql
CREATE TABLE download_stat (
    `key` VARCHAR(1024) PRIMARY KEY,
    `count` INT DEFAULT 0,
    `sha256` VARCHAR(64),
    `md5` VARCHAR(32),
    `file_size` BIGINT,
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### download_stats_cache
```sql
CREATE TABLE download_stats_cache (
    filename VARCHAR(255) PRIMARY KEY,
    downloads_7day INT DEFAULT 0,
    downloads_alltime INT DEFAULT 0,
    cached_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cached_at (cached_at)
);
```

### push_release_queue
```sql
CREATE TABLE push_release_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codename VARCHAR(128) NOT NULL,
    release_date DATE NOT NULL,
    version VARCHAR(16) NOT NULL,
    build_type VARCHAR(32) NOT NULL,
    requested_by VARCHAR(128) DEFAULT 'unknown',
    source_path VARCHAR(1024) NOT NULL,
    destination_path VARCHAR(1024) NOT NULL,
    status ENUM('queued', 'processing', 'completed') NOT NULL DEFAULT 'queued',
    success TINYINT(1) DEFAULT NULL,
    error_message TEXT NULL,
    callback_enabled TINYINT(1) NOT NULL DEFAULT 0,
    callback_url VARCHAR(2048) NULL,
    callback_http_code INT NULL,
    callback_response TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_created (status, created_at)
);
```

---

## Caching Strategy

### Multi-Tier Architecture

1. **Redis Cache (Tier 1 - Fastest)**
   - Volatile memory cache
   - Fastest access (<1ms)
   - Lost on Redis restart
   - Ideal for frequently accessed data

2. **File Cache (Tier 2 - Persistent)**
   - JSON files in `data/cache/`
   - Survives application/server restarts
   - Medium speed (5-20ms)
   - Automatic backfill to Redis

3. **Database (Tier 3 - Source of Truth)**
   - MySQL or SQLite
   - Persistent, reliable
   - Slower access (20-100ms)
   - Backfills all higher tiers

### Cache Keys

```
download_stats_7day_{filename}  # 7-day download count
bucket_size                      # Total bucket size/file count
file_hashes_{filepath}          # File checksums
push_job_{id}                   # Push job status
```

### Cache Invalidation

- Automatic on file uploads/deletions
- Manual via cache management API
- No TTL - explicit invalidation only
- Cron jobs refresh stale data

---

## Development

### Running Development Server

**Using start.sh:**
```bash
./start.sh
```

**Manual start:**
```bash
php -S localhost:8000 router.php
```

**Access:**
- Web UI: http://localhost:8000
- API: http://localhost:8000/api/
- Health: http://localhost:8000/health

### Testing

**Test health endpoint:**
```bash
curl http://localhost:8000/api/health
```

**Test statistics:**
```bash
curl "http://localhost:8000/api/download-statistics?limit=10"
```

**Test push API (with token):**
```bash
curl -X POST "http://localhost:8000/api/push" \
  -H "Authorization: Bearer YOUR_SECRET_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codename":"device","date":"2024-01-01","version":"9","buildType":"OFFICIAL"}'
```

**Test push API (with evoxupdater user-agent):**
```bash
curl -X POST "http://localhost:8000/api/push" \
  -H "User-Agent: evoxupdater/1.0" \
  -H "Content-Type: application/json" \
  -d '{"codename":"device","date":"2024-01-01","version":"9","buildType":"OFFICIAL"}'
```

### Directory Structure for Development

```
php_filebrowser_v2/
├── api.php                 # API router
├── index.php              # Main router
├── router.php             # Dev server router
├── composer.json          # Dependencies
├── start.sh              # Development server script
├── cron.php              # Maintenance script
├── cron_push_queue.php   # Queue worker
├── generate_hashes.php   # Hash generation utility
├── modules/
│   ├── api/              # API endpoints
│   ├── core/             # Core functionality
│   └── setup/            # Configuration
├── templates/            # HTML templates
├── static/              # CSS/JS assets
├── data/                # Cache and SQLite data
│   ├── cache/           # File-based cache
│   └── stats_cache/     # Statistics cache
└── logs/                # Application logs
```

### Adding New API Endpoints

1. Create module in `modules/api/your_module.php`
2. Implement handler function: `handleYourModuleApi($method, $pathParts)`
3. Add route in `api.php` switch statement
4. Return structured JSON response
5. Document in this README

### Best Practices

- **Error Logging**: Use `error_log()` for debugging
- **Database**: Use prepared statements (PDO)
- **Cache**: Always check cache before expensive operations
- **API Responses**: Use consistent JSON structure
- **Security**: Validate all input, sanitize paths
- **Performance**: Use Redis for hot data
- **Monitoring**: Check `/api/health` regularly

---

## Security Considerations

### Authentication

**Push API (`/api/push` and related endpoints):**
- Auto-authorized if `User-Agent` contains `evoxupdater` (no token required)
- Otherwise requires: `Authorization: Bearer TOKEN` or `X-API-Key: TOKEN` header
- Configure `PUSH_API_TOKEN` in environment (`.env` file) for non-evoxupdater clients

### Path Sanitization

- All file paths are sanitized via `sanitize_path()`
- No directory traversal attacks possible
- Restricted to BASE_PATH and PRE_RELEASE_PATH

### CORS Configuration

- Whitelist specific domains in production
- Configurable in `api.php`
- Default allows localhost for development

### Database Security

- All queries use prepared statements
- PDO with emulated prepares disabled
- MySQL user should have minimal permissions

---

## Troubleshooting

### Common Issues

**Database connection failed:**
- Check credentials in config.php
- Verify MySQL is running: `systemctl status mysql`
- Check SQLite file permissions: `ls -la data/`

**Redis connection failed:**
- Verify Redis is running: `redis-cli ping`
- Check password: `redis-cli -a evoCDN ping`
- Falls back to file cache automatically

**Cron jobs not running:**
- Check crontab: `crontab -l`
- Review logs: `tail -f logs/cron_*.log`
- Verify PHP path: `which php`

**Files not found:**
- Verify BASE_PATH in config.php
- Check mount points: `df -h`
- Review permissions: `ls -la /mnt/evolution-x`

**Push queue stuck:**
- Check queue status: `curl localhost:8000/api/push/stats`
- Review logs: `tail -f logs/push_queue_*.log`
- Verify source/destination paths exist

### Debug Mode

Enable detailed logging by editing modules:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

### Log Locations

- Application logs: `logs/`
- Cron logs: `logs/cron_YYYY-MM-DD.log`
- Push queue logs: `logs/push_queue_YYYY-MM-DD.log`
- PHP errors: Check system logs or configure in php.ini

---

## Performance Optimization

### Recommendations

1. **Use Redis** - 10-100x faster than file cache
2. **Enable APCu** - Additional in-process caching
3. **MySQL over SQLite** - Better for high concurrency
4. **CDN/R2** - Offload file serving to Cloudflare
5. **Optimize Cron** - Run during low-traffic periods
6. **Index Database** - Ensure all foreign keys are indexed
7. **Use Connection Pooling** - For MySQL connections

### Monitoring

- Watch `/api/health` for component status
- Monitor Redis memory: `redis-cli info memory`
- Check database size: `du -sh data/`
- Track log growth: `du -sh logs/`
- Monitor queue depth: `/api/push/stats`

---

## Support and Contributing

### Reporting Issues

- Check logs first: `logs/`
- Review health status: `/api/health`
- Include PHP version, OS, and error messages

### Contributing

1. Fork the repository
2. Create feature branch
3. Follow existing code style
4. Test thoroughly
5. Submit pull request with description

---

## License

[Specify your license here]

---

## Acknowledgments

- **Evolution X Team** - For the ROM and infrastructure
- **Cloudflare** - For R2 storage
- **AWS SDK for PHP** - For S3 compatibility
- **Redis** - For caching excellence

---

## Changelog

### Version 2.0.0
- Complete modular rewrite
- Multi-tier caching (Redis/File/DB)
- Push release queue system
- Enhanced statistics API
- Health check system
- Background hash calculation
- Bucket size caching
- Jenkins integration

### Version 1.0.0
- Initial release
- Basic file browsing
- Download tracking
- R2 integration

---

**Last Updated:** 2026-03-10
**Maintained By:** Evolution X CDN Team
