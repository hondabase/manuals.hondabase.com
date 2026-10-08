-- Schema change: Add download_count to files table
-- Applied: 2026-10-08
-- Database: manuals_db

ALTER TABLE files ADD COLUMN download_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER size;
