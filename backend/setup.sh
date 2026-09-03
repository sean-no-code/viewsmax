#!/bin/bash

echo "🚀 Setting up Viral Title Generator - Laravel Backend"
echo "====================================================="
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker and try again."
    exit 1
fi

# Check if Docker Compose is available
if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose is not installed. Please install it and try again."
    exit 1
fi

echo "✅ Docker and Docker Compose are available"
echo ""

# Start PostgreSQL and Redis services
echo "🐘 Starting PostgreSQL and Redis services..."
docker-compose up -d postgres redis

# Wait for services to be ready
echo "⏳ Waiting for services to be ready..."
sleep 10

# Check if services are running
if ! docker-compose ps | grep -q "Up"; then
    echo "❌ Services failed to start. Check Docker logs."
    exit 1
fi

echo "✅ Services are running"
echo ""

# Install PHP dependencies
echo "📦 Installing PHP dependencies..."
composer install --no-interaction

# Copy environment file
if [ ! -f .env ]; then
    echo "📝 Creating .env file..."
    cp .env.example .env
fi

# Update .env file for Docker services
echo "🔧 Updating .env file for Docker services..."
sed -i '' 's/DB_HOST=127.0.0.1/DB_HOST=127.0.0.1/' .env
sed -i '' 's/DB_PORT=5432/DB_PORT=5432/' .env
sed -i '' 's/DB_DATABASE=laravel/DB_DATABASE=postgres/' .env
sed -i '' 's/DB_USERNAME=root/DB_USERNAME=postgres/' .env
sed -i '' 's/DB_PASSWORD=/DB_PASSWORD=postgres/' .env
sed -i '' 's/CACHE_STORE=database/CACHE_STORE=redis/' .env

# Generate application key
echo "🔑 Generating application key..."
php artisan key:generate

# Wait a bit more for database to be fully ready
echo "⏳ Waiting for database to be fully ready..."
sleep 5

# Run migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force

# Seed the database
echo "🌱 Seeding the database..."
php artisan db:seed --class=TemplateSeeder --force
php artisan db:seed --class=KeywordSeeder --force

echo ""
echo "🎉 Setup complete! Your Viral Title Generator is ready."
echo ""
echo "📋 Next steps:"
echo "1. Start the Laravel server: php artisan serve"
echo "2. Test the API: php test_api.php"
echo "3. Access the API at: http://localhost:8000/api/v1"
echo ""
echo "🔧 Services running:"
echo "- PostgreSQL: localhost:5432"
echo "- Redis: localhost:6379"
echo "- Laravel: http://localhost:8000"
echo ""
echo "📚 API Documentation: See README.md for detailed API usage"
echo ""
echo "🛑 To stop services: docker-compose down"
echo "🔄 To restart services: docker-compose restart"

























































