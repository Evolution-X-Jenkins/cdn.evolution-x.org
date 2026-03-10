# Deployment Guide

Complete guide for deploying PHP File Browser V2 to production environments.

---

## Table of Contents

1. [Server Requirements](#server-requirements)
2. [Pre-Deployment Checklist](#pre-deployment-checklist)
3. [Installation Methods](#installation-methods)
4. [Web Server Configuration](#web-server-configuration)
5. [Database Setup](#database-setup)
6. [Cron Job Configuration](#cron-job-configuration)
7. [Redis Setup](#redis-setup)
8. [Security Hardening](#security-hardening)
9. [Monitoring & Logging](#monitoring--logging)
10. [Backup & Recovery](#backup--recovery)
11. [Performance Tuning](#performance-tuning)
12. [Troubleshooting](#troubleshooting)

---

## Server Requirements

### Minimum Requirements

- **OS**: Ubuntu 20.04 LTS or higher / CentOS 7+ / Debian 10+
- **PHP**: 8.0 or higher
- **Memory**: 2GB RAM minimum, 4GB recommended
- **Storage**: 20GB for application + space for ROM files
- **Database**: MySQL 8.0+ or SQLite 3
- **Redis**: Optional but highly recommended

### PHP Extensions

Required:
```
php-cli
php-fpm (for Nginx) or libapache2-mod-php (for Apache)
php-pdo
php-pdo-mysql (if using MySQL)
php-sqlite3 (if using SQLite)
php-json
php-curl
php-mbstring
php-xml
```

Recommended:
```
php-redis
php-apcu
php-opcache
```

### Package Installation

**Ubuntu/Debian:**
```bash
sudo apt update
sudo apt install -y php8.1 php8.1-fpm php8.1-cli php8.1-mysql \
  php8.1-sqlite3 php8.1-json php8.1-curl php8.1-mbstring \
  php8.1-xml php8.1-redis php8.1-apcu
```

**CentOS/RHEL:**
```bash
sudo yum install -y epel-release
sudo yum install -y php php-fpm php-cli php-mysqlnd php-pdo \
  php-json php-curl php-mbstring php-xml php-pecl-redis php-pecl-apcu
```

---

## Pre-Deployment Checklist

- [ ] Server meets minimum requirements
- [ ] PHP and extensions installed
- [ ] Composer installed (`php composer.phar --version`)
- [ ] Database server installed and running
- [ ] Redis installed (optional)
- [ ] Web server installed (Nginx/Apache)
- [ ] SSL certificate obtained (Let's Encrypt recommended)
- [ ] Cloudflare R2 credentials ready
- [ ] Push API token generated
- [ ] Backup strategy planned
- [ ] Monitoring solution ready

---

## Installation Methods

### Method 1: Manual Installation (Recommended for Production)

1. **Create application directory:**
   ```bash
   sudo mkdir -p /var/www/filebrowser
   cd /var/www/filebrowser
   ```

2. **Clone repository:**
   ```bash
   git clone https://github.com/your-repo/php_filebrowser_v2.git .
   ```

3. **Install dependencies:**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

4. **Set permissions:**
   ```bash
   sudo chown -R www-data:www-data /var/www/filebrowser
   sudo chmod -R 755 /var/www/filebrowser
   sudo chmod -R 775 data/ logs/
   ```

5. **Create environment file:**
   ```bash
   cp .env.example .env
   nano .env
   ```

6. **Configure application:**
   ```bash
   nano modules/setup/config.php
   ```

### Method 2: Docker Deployment (Coming Soon)

Docker support is planned for future releases.

---

## Web Server Configuration

### Nginx Configuration (Recommended)

Create `/etc/nginx/sites-available/filebrowser`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name cdn.example.com;
    
    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name cdn.example.com;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/cdn.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cdn.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    # Root directory
    root /var/www/filebrowser;
    index index.php;

    # Logging
    access_log /var/log/nginx/filebrowser_access.log;
    error_log /var/log/nginx/filebrowser_error.log;

    # Max upload size (adjust as needed)
    client_max_body_size 5G;

    # Main location block
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # API endpoints
    location ~ ^/api/ {
        try_files $uri $uri/ /api.php?$query_string;
    }

    # PHP processing
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Increase timeout for large file operations
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ /\.env {
        deny all;
    }

    location ~* /(data|logs)/ {
        deny all;
    }

    # Static file caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1000;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/filebrowser /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### Apache Configuration

Create `/etc/apache2/sites-available/filebrowser.conf`:

```apache
<VirtualHost *:80>
    ServerName cdn.example.com
    Redirect permanent / https://cdn.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName cdn.example.com
    DocumentRoot /var/www/filebrowser

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/cdn.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/cdn.example.com/privkey.pem

    <Directory /var/www/filebrowser>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Rewrite rules
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^api/ api.php [L,QSA]
        RewriteRule ^ index.php [L,QSA]
    </Directory>

    # Deny access to sensitive directories
    <DirectoryMatch "/(\.git|data|logs|modules/setup)">
        Require all denied
    </DirectoryMatch>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/filebrowser_error.log
    CustomLog ${APACHE_LOG_DIR}/filebrowser_access.log combined

    # PHP settings
    php_value upload_max_filesize 5G
    php_value post_max_size 5G
    php_value max_execution_time 300
</VirtualHost>
```

Enable required modules and site:
```bash
sudo a2enmod rewrite ssl headers
sudo a2ensite filebrowser
sudo apache2ctl configtest
sudo systemctl reload apache2
```

---

## Database Setup

### MySQL Setup (Recommended for Production)

1. **Install MySQL:**
   ```bash
   sudo apt install mysql-server
   sudo mysql_secure_installation
   ```

2. **Create database and user:**
   ```bash
   sudo mysql
   ```
   
   ```sql
   CREATE DATABASE filebrowser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'filebrowser'@'localhost' IDENTIFIED BY 'strong_password_here';
   GRANT ALL PRIVILEGES ON filebrowser.* TO 'filebrowser'@'localhost';
   FLUSH PRIVILEGES;
   EXIT;
   ```

3. **Configure in config.php:**
   ```php
   define('DB_TYPE', 'mysql');
   define('DB_HOST', 'localhost');
   define('DB_PORT', 3306);
   define('DB_NAME', 'filebrowser');
   define('DB_USER', 'filebrowser');
   define('DB_PASS', 'strong_password_here');
   ```

4. **Tables will be created automatically** on first run.

### SQLite Setup (Development/Small Deployments)

1. **Ensure SQLite is installed:**
   ```bash
   sudo apt install sqlite3 php8.1-sqlite3
   ```

2. **Configure in config.php:**
   ```php
   define('DB_TYPE', 'sqlite');
   ```

3. **Set permissions:**
   ```bash
   sudo chmod 775 data/
   sudo chown www-data:www-data data/
   ```

---

## Cron Job Configuration

### Setup Cron Jobs

1. **Edit crontab for www-data user:**
   ```bash
   sudo crontab -e -u www-data
   ```

2. **Add the following lines:**
   ```cron
   # Cache maintenance every 15 minutes
   0,15,30,45 * * * * /usr/bin/php /var/www/filebrowser/cron.php >> /var/www/filebrowser/logs/cron.log 2>&1

   # Push queue processing every minute
   * * * * * /usr/bin/php /var/www/filebrowser/cron_push_queue.php >> /var/www/filebrowser/logs/push_queue.log 2>&1

   # Log rotation daily at 2 AM
   0 2 * * * find /var/www/filebrowser/logs -name "*.log" -mtime +30 -delete
   ```

3. **Verify PHP path:**
   ```bash
   which php
   # Use the output path in crontab
   ```

4. **Test cron manually:**
   ```bash
   sudo -u www-data php /var/www/filebrowser/cron.php
   ```

### Log Rotation

Create `/etc/logrotate.d/filebrowser`:

```
/var/www/filebrowser/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0644 www-data www-data
    sharedscripts
}
```

---

## Redis Setup

### Installation

**Ubuntu/Debian:**
```bash
sudo apt install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

**CentOS/RHEL:**
```bash
sudo yum install redis
sudo systemctl enable redis
sudo systemctl start redis
```

### Configuration

1. **Edit Redis config:**
   ```bash
   sudo nano /etc/redis/redis.conf
   ```

2. **Set password:**
   ```
   requirepass evoCDN
   ```

3. **Performance settings:**
   ```
   maxmemory 1gb
   maxmemory-policy allkeys-lru
   save ""
   ```

4. **Restart Redis:**
   ```bash
   sudo systemctl restart redis
   ```

5. **Test connection:**
   ```bash
   redis-cli
   AUTH evoCDN
   PING
   # Should return PONG
   ```

6. **Configure in config.php:**
   ```php
   define('CACHE_REDIS_ENABLED', true);
   define('CACHE_REDIS_HOST', 'localhost');
   define('CACHE_REDIS_PORT', 6379);
   define('CACHE_REDIS_PASSWORD', 'evoCDN');
   ```

---

## Security Hardening

### File Permissions

```bash
# Application files - read only
sudo find /var/www/filebrowser -type f -exec chmod 644 {} \;
sudo find /var/www/filebrowser -type d -exec chmod 755 {} \;

# Writable directories
sudo chmod 775 /var/www/filebrowser/data
sudo chmod 775 /var/www/filebrowser/logs
sudo chown -R www-data:www-data /var/www/filebrowser/data
sudo chown -R www-data:www-data /var/www/filebrowser/logs
```

### Environment Security

1. **Protect .env file:**
   ```bash
   sudo chmod 600 /var/www/filebrowser/.env
   sudo chown www-data:www-data /var/www/filebrowser/.env
   ```

2. **Generate strong tokens:**
   ```bash
   # Generate random token
   openssl rand -hex 32
   ```

3. **Update .env:**
   ```bash
   PUSH_API_TOKEN=<generated_token>
   DB_PASS=<strong_database_password>
   CACHE_REDIS_PASSWORD=<strong_redis_password>
   ```

### Firewall Configuration

```bash
# Allow SSH
sudo ufw allow 22/tcp

# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Enable firewall
sudo ufw enable
```

### PHP Security

Edit `/etc/php/8.1/fpm/php.ini` (or Apache equivalent):

```ini
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
post_max_size = 5G
upload_max_filesize = 5G
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.1-fpm
```

---

## Monitoring & Logging

### Application Logs

- **Cron logs:** `/var/www/filebrowser/logs/cron_YYYY-MM-DD.log`
- **Push queue:** `/var/www/filebrowser/logs/push_queue_YYYY-MM-DD.log`
- **PHP errors:** `/var/log/php/error.log`
- **Web server:** `/var/log/nginx/` or `/var/log/apache2/`

### Health Monitoring

Create monitoring script `/usr/local/bin/check-filebrowser-health.sh`:

```bash
#!/bin/bash

API_URL="https://cdn.example.com/api/health"
ALERT_EMAIL="admin@example.com"

response=$(curl -s -o /dev/null -w "%{http_code}" $API_URL)

if [ "$response" != "200" ]; then
    echo "File browser health check failed: HTTP $response" | \
        mail -s "Alert: File Browser Health Check Failed" $ALERT_EMAIL
    exit 1
fi

# Check specific components
health_status=$(curl -s $API_URL | jq -r '.database.status')

if [ "$health_status" != "healthy" ]; then
    echo "Database health check failed" | \
        mail -s "Alert: Database Health Check Failed" $ALERT_EMAIL
    exit 1
fi

exit 0
```

Add to crontab:
```cron
*/5 * * * * /usr/local/bin/check-filebrowser-health.sh
```

### Monitoring Tools

Recommended tools:
- **Uptime monitoring:** UptimeRobot, Pingdom
- **Server monitoring:** Prometheus + Grafana, Netdata
- **Log aggregation:** ELK Stack, Graylog
- **APM:** New Relic, Datadog

---

## Backup & Recovery

### Database Backup

Create `/usr/local/bin/backup-filebrowser-db.sh`:

```bash
#!/bin/bash

BACKUP_DIR="/var/backups/filebrowser"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="filebrowser"
DB_USER="filebrowser"
DB_PASS="your_password"

mkdir -p $BACKUP_DIR

# MySQL backup
mysqldump -u $DB_USER -p$DB_PASS $DB_NAME | \
    gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Keep only last 30 days
find $BACKUP_DIR -name "db_*.sql.gz" -mtime +30 -delete

# SQLite backup (if using SQLite)
# cp /var/www/filebrowser/data/filebrowser.db $BACKUP_DIR/db_$DATE.db
# gzip $BACKUP_DIR/db_$DATE.db
```

Add to crontab:
```cron
0 2 * * * /usr/local/bin/backup-filebrowser-db.sh
```

### Configuration Backup

```bash
# Backup configuration files
tar -czf /var/backups/filebrowser/config_$(date +%Y%m%d).tar.gz \
    /var/www/filebrowser/.env \
    /var/www/filebrowser/modules/setup/config.php \
    /etc/nginx/sites-available/filebrowser
```

### Recovery Procedure

1. **Restore database:**
   ```bash
   gunzip < /var/backups/filebrowser/db_20240101_020000.sql.gz | \
       mysql -u filebrowser -p filebrowser
   ```

2. **Restore configuration:**
   ```bash
   tar -xzf /var/backups/filebrowser/config_20240101.tar.gz -C /
   ```

3. **Restart services:**
   ```bash
   sudo systemctl restart php8.1-fpm
   sudo systemctl restart nginx
   sudo systemctl restart redis
   ```

---

## Performance Tuning

### PHP-FPM Optimization

Edit `/etc/php/8.1/fpm/pool.d/www.conf`:

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 500

; Performance
php_value[opcache.enable] = 1
php_value[opcache.memory_consumption] = 128
php_value[opcache.interned_strings_buffer] = 8
php_value[opcache.max_accelerated_files] = 10000
php_value[opcache.revalidate_freq] = 60
```

### MySQL Optimization

Edit `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
query_cache_size = 0
query_cache_type = 0
max_connections = 200
```

### Redis Memory Optimization

```bash
redis-cli
CONFIG SET maxmemory 1gb
CONFIG SET maxmemory-policy allkeys-lru
CONFIG REWRITE
```

### Nginx Caching

Add to Nginx config:

```nginx
# Cache configuration
proxy_cache_path /var/cache/nginx levels=1:2 keys_zone=filebrowser_cache:10m max_size=1g inactive=60m use_temp_path=off;

# In server block
location /api/download/statistics {
    proxy_cache filebrowser_cache;
    proxy_cache_valid 200 5m;
    proxy_cache_key "$request_uri";
    try_files $uri $uri/ /api.php?$query_string;
}
```

---

## Troubleshooting

### Common Issues

**1. Database connection failed**
```bash
# Check MySQL is running
sudo systemctl status mysql

# Test connection
mysql -u filebrowser -p filebrowser

# Check credentials in config.php
```

**2. Permission denied errors**
```bash
# Fix permissions
sudo chown -R www-data:www-data /var/www/filebrowser
sudo chmod -R 755 /var/www/filebrowser
sudo chmod -R 775 data/ logs/
```

**3. Cron jobs not running**
```bash
# Check crontab
sudo crontab -l -u www-data

# Test manually
sudo -u www-data php /var/www/filebrowser/cron.php

# Check logs
tail -f /var/www/filebrowser/logs/cron.log
```

**4. Redis connection failed**
```bash
# Check Redis is running
sudo systemctl status redis

# Test connection
redis-cli -a evoCDN ping

# Application falls back to file cache automatically
```

**5. API returns 500 error**
```bash
# Check PHP error log
tail -f /var/log/php/error.log

# Check web server error log
tail -f /var/log/nginx/filebrowser_error.log

# Enable debug mode (temporarily)
# Edit api.php and add:
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

---

## Production Checklist

Before going live:

- [ ] All dependencies installed and updated
- [ ] Database configured and tables created
- [ ] Redis installed and configured
- [ ] Cron jobs scheduled and tested
- [ ] SSL certificate installed and valid
- [ ] File permissions set correctly
- [ ] .env file configured with production values
- [ ] Strong passwords/tokens generated
- [ ] Firewall configured
- [ ] Backup system in place
- [ ] Monitoring configured
- [ ] Health check endpoint responding
- [ ] API endpoints tested
- [ ] Log rotation configured
- [ ] PHP opcache enabled
- [ ] Web server optimized
- [ ] Documentation updated

---

## Support

For issues and questions:
- Check logs: `/var/www/filebrowser/logs/`
- Review health status: `/api/health`
- Check this deployment guide
- Review main README.md
- Contact system administrator

---

**Last Updated:** 2026-03-10
