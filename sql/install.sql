-- SQL for pgvector (run after configuring PG connection)
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE IF NOT EXISTS product_vectors (
    id SERIAL PRIMARY KEY,
    id_product INTEGER UNIQUE,
    name TEXT,
    description TEXT,
    features JSONB,
    attributes JSONB,
    vector VECTOR(1536)
);

CREATE INDEX IF NOT EXISTS product_vectors_vector_idx ON product_vectors USING ivfflat (vector) WITH (lists = 100);
