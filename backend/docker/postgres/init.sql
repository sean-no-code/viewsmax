-- Runs once, on first boot of an empty postgres data volume.
--
-- The application uses TWO databases:
--   viewsmax         — the main application database, created by POSTGRES_DB.
--   viewsmax_search  — backs the "outlier_db" connection. Five migrations
--                      target it via Schema::connection('outlier_db'), so
--                      without it `php artisan migrate` fails partway through.

-- Extensions for the main database (pg_trgm = similarity search, vector =
-- embeddings). Extensions are per-database, so viewsmax_search needs its own.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS vector;

-- Postgres has no "CREATE DATABASE IF NOT EXISTS", and this script runs with
-- ON_ERROR_STOP, so creating one that already exists would abort start-up.
-- \gexec runs the generated statement only when the SELECT returns a row.
SELECT 'CREATE DATABASE viewsmax_search'
 WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'viewsmax_search')\gexec

GRANT ALL PRIVILEGES ON DATABASE viewsmax TO postgres;
GRANT ALL PRIVILEGES ON DATABASE viewsmax_search TO postgres;

\connect viewsmax_search
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS vector;
