#!/bin/sh
set -e

echo "🚀 Starting SpareTrack Container Entrypoint..."

cd /var/www/html

# Storage and cache permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache || true

# Check .env file
if [ ! -f ".env" ]; then
    echo "📌 .env file not found! Copying .env.example..."
    cp .env.example .env
fi

# Auto-normalize Docker database and redis hostnames
if [ "$DB_HOST" = "127.0.0.1" ] || [ "$DB_HOST" = "localhost" ] || [ -z "$DB_HOST" ]; then
    DB_HOST="postgres"
fi
if [ "$REDIS_HOST" = "127.0.0.1" ] || [ "$REDIS_HOST" = "localhost" ] || [ -z "$REDIS_HOST" ]; then
    REDIS_HOST="redis"
fi
DB_PORT="${DB_PORT:-5432}"

# Wait for PostgreSQL database
echo "⏳ Waiting for PostgreSQL database ($DB_HOST:$DB_PORT)..."
timeout=60
while ! nc -z "$DB_HOST" "$DB_PORT"; do
    sleep 2
    timeout=$((timeout - 2))
    if [ "$timeout" -le 0 ]; then
        echo "❌ Database is not reachable after 60 seconds. Exiting."
        exit 1
    fi
done
echo "✅ Database is reachable!"

# Install vendor dependencies if not present
if [ ! -f "vendor/autoload.php" ]; then
    echo "📦 Installing Composer dependencies..."
    composer config -g --disable-tls true 2>/dev/null || true
    composer config -g secure-http false 2>/dev/null || true
    composer install --no-interaction --prefer-dist --optimize-autoloader || true
fi

# Install frontend dependencies & build if needed
if [ ! -d "node_modules" ] || [ -z "$(ls -A node_modules 2>/dev/null)" ]; then
    echo "📦 Installing NPM dependencies..."
    npm install || true
    npm run build || true
fi

# Generate APP_KEY if missing
if ! grep -q "^APP_KEY=base64:" .env; then
    echo "🔑 Generating Laravel Application Key..."
    php artisan key:generate
fi

# Create storage symlink
php artisan storage:link || true

# Run database migrations & seeders
echo "📜 Running database migrations..."
php artisan migrate --force

echo "🌱 Running database seeders..."
php artisan db:seed --force || true

# Clear caches
echo "⚡ Clearing Laravel caches..."
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan cache:clear || true

echo "✅ SpareTrack readiness checks completed!"

# If arguments passed, execute command (e.g. queue worker or reverb), otherwise run php-fpm
if [ $# -gt 0 ]; then
    exec "$@"
else
    echo "⚡ Starting PHP-FPM..."
    exec php-fpm -F
fi
