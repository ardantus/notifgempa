-- Schema untuk D1 Database
-- Jalankan dengan: wrangler d1 execute gempa-db --file=schema.sql

CREATE TABLE IF NOT EXISTS gempa (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  datetime TEXT,
  tanggal TEXT,
  jam TEXT,
  magnitude TEXT,
  kedalaman TEXT,
  wilayah TEXT,
  lintang TEXT,
  bujur TEXT,
  coordinates TEXT,
  potensi TEXT,
  dirasakan TEXT,
  shakemap TEXT,
  source TEXT,
  UNIQUE(datetime, source)
);

-- Index untuk performa query
CREATE INDEX IF NOT EXISTS idx_datetime_source ON gempa(datetime, source);
CREATE INDEX IF NOT EXISTS idx_source ON gempa(source);
