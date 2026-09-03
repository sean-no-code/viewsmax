-- Enable pg_trgm extension for similarity search
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Enable vector extension for embeddings
CREATE EXTENSION IF NOT EXISTS vector;

-- Create database if it doesn't exist (this will be handled by Docker environment)
-- The database 'postgres' will be created automatically by PostgreSQL
CREATE DATABASE viewsmax;
-- Grant necessary permissions
GRANT ALL PRIVILEGES ON DATABASE postgres TO postgres;
GRANT ALL PRIVILEGES ON DATABASE viewsmax TO postgres;

-- Set timezone
SET timezone = 'UTC';

-- Log the initialization
DO $$
BEGIN
    RAISE NOTICE 'PostgreSQL database initialized successfully with pg_trgm extension';
    RAISE NOTICE 'Database: %', current_database();
    RAISE NOTICE 'User: %', current_user;
    RAISE NOTICE 'Time: %', now();
END $$;

