# 🚀 Title Embedding API - Laravel Backend

A production-ready Laravel application that manages titles with vector embeddings using PostgreSQL's vector extension. Perfect for content creators, marketers, and anyone looking to create and search for engaging titles using semantic similarity.

## ✅ Running tests

Tests run against an in-memory SQLite database (configured in `phpunit.xml`) — no DB setup or `.env` changes required.

```bash
php artisan test                          # run the full suite
composer test                             # same, but clears cached config first
php artisan test --filter ViewsMaxApiTest # run only the auth/API feature tests
```

## ✨ Features

php artisan db:wipe --database=outlier_db ; php artisan migrate:fresh --seed

### 🎯 Core Functionality

-   **Title Management**: Store and manage titles with virality scores
-   **Vector Embeddings**: Generate embeddings using OpenAI's text-embedding-3-large model
-   **Semantic Search**: Find similar titles using PostgreSQL vector similarity search
-   **Pagination**: Efficient pagination for large datasets

### 🗄️ Database Design

-   **Titles Table**: Store titles with virality scores and vector embeddings
-   **Vector Extension**: PostgreSQL pgvector extension for similarity search
-   **Optimized Queries**: Fast vector similarity operations

### 🔍 Advanced Search

-   **Vector Similarity**: Find the 5 most similar titles using cosine distance
-   **OpenAI Integration**: Generate embeddings using state-of-the-art models
-   **ChatGPT Enhancement**: Use ChatGPT to adapt viral titles to match your specific project
-   **Fallback Support**: Dummy embeddings for testing without API key

## 🏗️ Architecture

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   API Layer     │    │  Service Layer   │    │  Data Layer     │
│                 │    │                  │    │                 │
│ TitleController │◄──►│ OpenAI Service   │◄──►│ PostgreSQL      │
│ Routes          │    │ Vector Search    │    │ pgvector        │
│ Validation      │    │                  │    │                 │
└─────────────────┘    └──────────────────┘    └─────────────────┘
```

## 🚀 Quick Start

### Prerequisites

-   PHP 8.1+
-   Composer
-   PostgreSQL 15+ with pgvector extension
-   Laravel 11+
-   Docker & Docker Compose (recommended)

### Installation

1. **Clone the repository**

    ```bash
    git clone <repository-url>
    cd viewsmaxBackend
    ```

2. **Install dependencies**

    ```bash
    composer install
    ```

3. **Environment setup**

    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

4. **Configure database**

    ```env
    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=postgres
    DB_USERNAME=postgres
    DB_PASSWORD=postgres

    OPENAI_API_KEY=your-openai-api-key-here
    OPENAI_MODEL=text-embedding-3-large
    ```

5. **Run migrations**

    ```bash
    php artisan migrate
    ```

    **Full reset (both databases).** Outlier/search tables live on a separate
    connection, `outlier_db` (the Postgres `viewsmax_search` database).
    `migrate:fresh` only drops tables on the default connection, so wipe the
    outlier database first — otherwise the `outlier_db`-pinned migrations fail
    trying to re-create tables that still exist:

    ```bash
    php artisan db:wipe --database=outlier_db
    php artisan migrate:fresh --seed
    ```

    > In Docker, prefix each with `docker exec viewsmax_app `, e.g.
    > `docker exec viewsmax_app php artisan db:wipe --database=outlier_db`

6. **Seed the database**

    ```bash
    php artisan db:seed --class=TitleSeeder
    ```

7. **Start the server**
    ```bash
    php artisan serve
    ```

## 🐳 Docker Deployment

### Prerequisites

-   Docker
-   Docker Compose

### Quick Docker Setup

1. **Start all services**

    ```bash
    docker-compose up -d
    ```

2. **Run migrations**

    ```bash
    docker exec viewsmax_app php artisan migrate
    ```

3. **Seed the database**

    ```bash
    docker exec viewsmax_app php artisan db:seed --class=TitleSeeder
    ```

4. **View running containers**

    ```bash
    docker ps
    ```

5. **Stop all services**
    ```bash
    docker-compose down
    ```

### Docker Services

The Docker setup includes:

-   **App Container**: Laravel application running on port 8000
-   **PostgreSQL**: Database with pgvector extension on port 5432

### Access Points

-   **Application**: http://localhost:8000
-   **Database**: localhost:5432

## 📚 API Endpoints

### Health Check

```http
GET /api/health
```

Response:
```json
{
  "status": "healthy",
  "timestamp": "2025-09-02T07:53:02.000000Z",
  "service": "Title Embedding API",
  "version": "1.0.0"
}
```

### List Titles

```http
GET /api/titles?per_page=10&page=1
```

Response:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "10 Mind-Blowing Facts About Space",
      "virality_score": 95,
      "created_at": "2025-09-02T07:51:05.000000Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 2,
    "per_page": 10,
    "total": 12,
    "from": 1,
    "to": 10
  }
}
```

### Create Title

```http
POST /api/titles
Content-Type: application/json

{
  "title": "Amazing New Technology That Will Change Everything",
  "virality_score": 95
}
```

Response:
```json
{
  "success": true,
  "data": {
    "id": 11,
    "title": "Amazing New Technology That Will Change Everything",
    "virality_score": 95,
    "embedding": [0.0, 0.0, ...],
    "created_at": "2025-09-02T07:51:35.000000Z",
    "updated_at": "2025-09-02T07:51:35.000000Z"
  },
  "message": "Title created successfully"
}
```

### Search Similar Titles

```http
POST /api/titles/search
Content-Type: application/json

{
  "query": "technology that changes everything",
  "enhance": false
}
```

Response:
```json
{
  "success": true,
  "data": [
    {
      "id": 11,
      "title": "Amazing New Technology That Will Change Everything",
      "virality_score": 95,
      "distance": 0.0
    }
  ],
  "message": "Search completed successfully"
}
```

### Search with ChatGPT Enhancement

```http
POST /api/titles/search
Content-Type: application/json

{
  "query": "productivity tips for entrepreneurs",
  "enhance": true
}
```

Response (with OpenAI API key configured):
```json
{
  "success": true,
  "data": [
    {
      "id": 2,
      "original_title": "10 Tricks to Skyrocket Your Productivity",
      "enhanced_title": "10 Entrepreneur Productivity Hacks That Actually Work",
      "virality_score": 91,
      "distance": 0.0
    }
  ],
  "message": "Search completed successfully with ChatGPT enhancement"
}
```

Response (without OpenAI API key):
```json
{
  "success": true,
  "data": [
    {
      "id": 2,
      "title": "10 Tricks to Skyrocket Your Productivity",
      "virality_score": 91,
      "distance": 0.0
    }
  ],
  "message": "Search completed successfully with ChatGPT enhancement"
}
```

## 🔧 Configuration

### PostgreSQL Setup

1. Use the pgvector Docker image: `pgvector/pgvector:pg15`
2. The vector extension is automatically enabled in the init script
3. Database is created automatically via Docker

### OpenAI Configuration

1. Get your API key from [OpenAI](https://platform.openai.com/api-keys)
2. Add to your `.env` file:
   ```env
   OPENAI_API_KEY=your-actual-api-key
   OPENAI_MODEL=text-embedding-3-large
   ```

### Testing Without OpenAI

The application includes fallback support for testing without an OpenAI API key. It will use dummy embeddings (all zeros) when no API key is configured.

## 🧪 Testing

### Test API Endpoints

```bash
# Test health check
curl -X GET http://localhost:8000/api/health

# Test list titles
curl -X GET http://localhost:8000/api/titles

# Test create title
curl -X POST http://localhost:8000/api/titles \
  -H "Content-Type: application/json" \
  -d '{"title": "Test Title", "virality_score": 85}'

# Test search
curl -X POST http://localhost:8000/api/titles/search \
  -H "Content-Type: application/json" \
  -d '{"query": "test search"}'

# Test enhanced search with ChatGPT
curl -X POST http://localhost:8000/api/titles/search \
  -H "Content-Type: application/json" \
  -d '{"query": "productivity tips", "enhance": true}'
```

## 📊 Database Schema

### Titles Table

```sql
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE titles (
    id SERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    virality_score INT NOT NULL,
    embedding vector(3072),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Vector Search Query

```sql
SELECT id, title, virality_score, embedding <-> $1 AS distance
FROM titles
ORDER BY embedding <-> $1
LIMIT 5;
```

## 🔮 Future Enhancements

### AI Integration

-   **Multiple AI Providers**: Support for Claude, Cohere, and other embedding models
-   **Custom Models**: Fine-tuned models for specific domains
-   **Batch Processing**: Process multiple titles at once

### Advanced Features

-   **Title Categories**: Organize titles by topic or industry
-   **User Management**: Multi-user support with personal title collections
-   **Analytics**: Track title performance and engagement metrics
-   **Export/Import**: Bulk operations for title management

### Performance Improvements

-   **Caching**: Redis caching for frequently accessed embeddings
-   **Indexing**: Optimized vector indexes for faster searches
-   **API Rate Limiting**: Protect against abuse
-   **Background Jobs**: Async embedding generation

## 📧 Kit (ConvertKit) email flow

**"No Kit on registration" is by design, not a bug.** The Aug 11 2026 refactor
(`84a2207`, see the `SyncKitOnSubscription` docblock: "replaces the old
registration-time subscribe") deliberately stopped adding users to Kit at
registration. Instead, every signup reaches Kit through one of two doors:

1. **Abandoned cart** — `kit:tag-abandoned-carts` (scheduled every 3 hours in
   `bootstrap/app.php`) tags users who signed up 1–4h ago and never started a
   subscription with `viewsmax: abandoned cart`. Marketing consent is
   **intentionally ignored** for this tag.
2. **Converted** — `SyncKitOnSubscription` (on `SubscriptionStarted`) applies
   `viewsmax: new subscriber`, but **only with marketing consent**. It also
   unconditionally removes the abandoned-cart tag, so a paying user can never
   keep receiving abandoned-cart emails.

If consented registrants should ever be pushed to Kit immediately at signup
again, that's a **design change**, not a fix — add it alongside this flow,
don't assume the missing registration-time subscribe is an oversight.

### Invariants & gotchas

-   `KIT_ABANDONED_CART_WINDOW_HOURS` (default 3) **must equal** the scheduler
    cadence (`everyThreeHours`). The window slices tile the timeline with no DB
    flag; change one, change the other.
-   The no-flag trade-off: any scheduler tick that doesn't run (downtime,
    deploy, daemon restart at the wrong minute) **permanently drops** that
    3-hour slice of signups. Sweep up after outages with the backfill below.
-   The scheduler (`schedule:work` daemon / `viewsmax_scheduler` container)
    holds old code in memory just like queue workers — **restart it after every
    deploy** (`queue:restart` does not touch it). A stale scheduler predating
    `84a2207` won't run the tagger at all.
-   Kit is production-only by default (`APP_ENV=production`); set
    `KIT_ENABLED=true/false` to force it in any environment. Requires
    `KIT_API_KEY` + `KIT_API_SECRET`.

### Ops commands

```bash
php artisan schedule:list | grep kit                 # is the tagger scheduled?
php artisan kit:list-abandoned-carts --days=14       # read-only: who's eligible
php artisan kit:submit-abandoned-carts --days=14     # backfill missed users (idempotent)
grep 'Abandoned-cart tagging run complete' storage/logs/laravel.log | tail  # run history
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Commit changes: `git commit -m 'Add amazing feature'`
4. Push to branch: `git push origin feature/amazing-feature`
5. Open a Pull Request

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 📚 Documentation

-   **Main Documentation**: [README/README.md](./README/README.md) - Complete API documentation
-   **Thumbnail Services**: [README/THUMBNAIL_SERVICES.md](./README/THUMBNAIL_SERVICES.md) - Service configuration and setup
-   **Download API**: [README/THUMBNAIL_DOWNLOAD_API.md](./README/THUMBNAIL_DOWNLOAD_API.md) - Thumbnail download functionality
-   **Email Setup**: [README/EMAIL_SETUP_GUIDE.md](./README/EMAIL_SETUP_GUIDE.md) - Brevo email integration
-   **Google OAuth**: [README/GOOGLE_OAUTH_SETUP.md](./README/GOOGLE_OAUTH_SETUP.md) - YouTube API OAuth setup

## 🆘 Support

-   **Documentation**: Check the organized documentation in the README folder
-   **Issues**: Report bugs and feature requests via GitHub Issues
-   **Discussions**: Join community discussions for help and ideas

## 🏆 Performance Metrics

-   **Response Time**: < 500ms for embedding generation
-   **Search Performance**: < 100ms for vector similarity search
-   **Scalability**: Designed for high-traffic applications
-   **Vector Dimensions**: 3072 dimensions for high-quality embeddings

---

**Built with ❤️ using Laravel, PostgreSQL pgvector, and OpenAI**