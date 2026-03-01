#!/usr/bin/env bash
set -euo pipefail

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  DevAgent — Railway Startup"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

cd /app

# ── 1. Wait for MySQL to be ready ─────────────────────────
echo "⏳ Waiting for database..."
MAX_TRIES=30
COUNT=0

while ! php -r "
  try {
    \$pdo = new PDO(
      'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: 3306) . ';charset=utf8mb4',
      getenv('DB_USER'),
      getenv('DB_PASS'),
      [PDO::ATTR_TIMEOUT => 3]
    );
    echo 'ok';
  } catch (Exception \$e) {
    exit(1);
  }
" 2>/dev/null | grep -q "ok"; do
  COUNT=$((COUNT + 1))
  if [ $COUNT -ge $MAX_TRIES ]; then
    echo "❌ Database not reachable after ${MAX_TRIES} attempts. Check DB_HOST, DB_USER, DB_PASS."
    exit 1
  fi
  echo "   Attempt $COUNT/$MAX_TRIES — retrying in 2s..."
  sleep 2
done

echo "✓ Database is up"

# ── 2. Run migrations ──────────────────────────────────────
echo "📦 Running database migrations..."
php bin/migrate.php
echo "✓ Migrations complete"

# ── 3. Write Nginx config ──────────────────────────────────
PORT="${PORT:-8080}"
echo "🌐 Configuring Nginx on port $PORT..."

cat > /tmp/nginx.conf << NGINX
worker_processes auto;
error_log /dev/stderr warn;
pid /tmp/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;

    log_format main '\$remote_addr - \$remote_user [\$time_local] '
                    '"\$request" \$status \$body_bytes_sent '
                    '"\$http_referer" "\$http_user_agent"';
    access_log /dev/stdout main;

    sendfile        on;
    keepalive_timeout 75;
    client_max_body_size 64k;

    # PHP-FPM upstream
    upstream php_fpm {
        server unix:/tmp/php-fpm.sock;
    }

    server {
        listen ${PORT};
        server_name _;
        root /app/public;
        index index.php;

        # Security headers (belt-and-suspenders alongside PHP)
        add_header X-Content-Type-Options nosniff always;
        add_header X-Frame-Options DENY always;
        add_header Referrer-Policy strict-origin-when-cross-origin always;

        # Disable buffering for SSE endpoints
        location ~ ^/api/logs/stream {
            fastcgi_pass php_fpm;
            fastcgi_param SCRIPT_FILENAME /app/public/index.php;
            include fastcgi_params;
            fastcgi_buffering off;
            fastcgi_read_timeout 360s;
            proxy_buffering off;
            proxy_cache off;
        }

        # All other requests → index.php
        location / {
            try_files \$uri /index.php\$is_args\$args;
        }

        location ~ \.php$ {
            fastcgi_pass php_fpm;
            fastcgi_param SCRIPT_FILENAME /app/public/index.php;
            fastcgi_param PATH_INFO \$fastcgi_path_info;
            include fastcgi_params;
            fastcgi_read_timeout 120s;
            fastcgi_buffers 8 16k;
            fastcgi_buffer_size 32k;
        }

        # Block access to sensitive files
        location ~ /\.(env|git|htaccess) {
            deny all;
            return 404;
        }

        location ~ ^/(bootstrap|routes|composer) {
            deny all;
            return 404;
        }
    }
}
NGINX

echo "✓ Nginx configured"

# ── 4. Write PHP-FPM config ────────────────────────────────
cat > /tmp/php-fpm.conf << FPM
[global]
error_log = /dev/stderr
log_level = warning

[www]
listen = /tmp/php-fpm.sock
listen.mode = 0666

user = nobody
group = nobody

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 5
pm.max_requests = 500

; Pass env vars to PHP-FPM workers
env[DB_HOST]           = \$DB_HOST
env[DB_PORT]           = \$DB_PORT
env[DB_NAME]           = \$DB_NAME
env[DB_USER]           = \$DB_USER
env[DB_PASS]           = \$DB_PASS
env[GITHUB_CLIENT_ID]  = \$GITHUB_CLIENT_ID
env[GITHUB_CLIENT_SECRET] = \$GITHUB_CLIENT_SECRET
env[GITHUB_REDIRECT_URI] = \$GITHUB_REDIRECT_URI
env[ANTHROPIC_API_KEY] = \$ANTHROPIC_API_KEY
env[CLAUDE_MODEL]      = \$CLAUDE_MODEL
env[ENCRYPTION_KEY]    = \$ENCRYPTION_KEY
env[ALLOWED_ORIGINS]   = \$ALLOWED_ORIGINS
env[APP_ENV]           = \$APP_ENV
env[APP_URL]           = \$APP_URL
env[TRUST_PROXY]       = \$TRUST_PROXY

; Resource limits
php_admin_value[memory_limit] = 128M
php_admin_value[max_execution_time] = 120
php_admin_value[upload_max_filesize] = 1M
php_admin_value[post_max_size] = 2M

; Security
php_admin_flag[expose_php] = off
php_admin_flag[display_errors] = off
php_admin_value[log_errors] = on
php_admin_value[error_log] = /dev/stderr
FPM

echo "✓ PHP-FPM configured"

# ── 5. Start PHP-FPM ──────────────────────────────────────
echo "🚀 Starting PHP-FPM..."
php-fpm83 -y /tmp/php-fpm.conf --nodaemonize &
PHP_PID=$!

# Wait for FPM socket to appear
for i in $(seq 1 10); do
  [ -S /tmp/php-fpm.sock ] && break
  sleep 0.5
done

if [ ! -S /tmp/php-fpm.sock ]; then
  echo "❌ PHP-FPM failed to start"
  exit 1
fi
echo "✓ PHP-FPM running (pid $PHP_PID)"

# ── 6. Start Nginx ─────────────────────────────────────────
echo "🌐 Starting Nginx on port $PORT..."
nginx -c /tmp/nginx.conf &
NGINX_PID=$!
echo "✓ Nginx running (pid $NGINX_PID)"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  ✅ DevAgent backend is live"
echo "  Port: $PORT"
echo "  Health: http://localhost:$PORT/api/health"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# ── 7. Keep alive — restart on crash ──────────────────────
trap 'echo "Shutting down..."; kill $PHP_PID $NGINX_PID 2>/dev/null; exit 0' SIGTERM SIGINT

# Monitor both processes
while true; do
  if ! kill -0 $PHP_PID 2>/dev/null; then
    echo "⚠️  PHP-FPM crashed, restarting..."
    php-fpm83 -y /tmp/php-fpm.conf --nodaemonize &
    PHP_PID=$!
  fi
  if ! kill -0 $NGINX_PID 2>/dev/null; then
    echo "⚠️  Nginx crashed, restarting..."
    nginx -c /tmp/nginx.conf &
    NGINX_PID=$!
  fi
  sleep 5
done
