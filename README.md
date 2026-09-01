# manuals.hondabase.com

Full-text-searchable archive of Honda/Acura/Rover service manuals, wiring diagrams, and technical documents. PHP app backed by MySQL (catalog + search index) and Cloudflare R2 (file storage/delivery).

## Architecture

- **App**: PHP 8.5, single-file router — `index.php` handles both the AJAX search endpoint and directory browsing; `indexer.php` is a standalone CLI/admin script for adding new manuals.
- **Database** (`manuals_db`):
  - `folders` / `files` — a catalog of everything under `/cars` (id, parent/folder id, name, size), used to render directory listings without touching the filesystem. Rebuilt once via a one-time backfill when the archive moved to R2; kept current by `indexer.php` for anything added afterward.
  - `pdf_files` / `pdf_pages` — OCR'd/extracted full text per PDF page, used by the `MATCH() AGAINST()` full-text search (`?q=` endpoint). `pdf_files.file_path` is stored as `cars/<rel path>` (matches the on-disk layout the indexer was originally built around).
- **Storage**: **Cloudflare R2** (`hondabase-manuals` bucket) is the only copy of the actual files — nothing under `/cars` lives on the app server. `assets/` (site CSS) is the one thing that *does* stay on local disk, since it's tiny and part of the app itself, not archive content.

## How browsing/downloading works

Everything under `/cars/...` is resolved against the `folders`/`files` catalog instead of `scandir()`:

- A path that resolves to a **folder** renders the directory listing (subfolders + files), exactly like the old filesystem-backed version.
- A path that resolves to a **file** gets a `302` to `https://cdn.hondabase.com/manuals/<rel-path>` — a Cloudflare Worker (`hondabase-cdn`, R2-bound, same one `files.hondabase.com` uses under `/files/*`) that serves the object with Range support and edge caching.

This preserves the exact same URL shape (`/cars/honda/civic/...`) that existed when files were served from local disk, which matters because those URLs are used as the persistent identifier for the favorites feature on `www.hondabase.com` — changing the path format would have silently broken every user's saved manuals.

Everything **outside** `/cars/` (the homepage root listing, `/assets/`) is small, app-owned, and still served directly off local disk via `scandir()` — no reason to route that through R2.

## Adding a new manual

Drop the PDF into `/cars/<path>/` on the server and run `php indexer.php`. For each new/changed PDF it:

1. Extracts text (`pdftotext`, falling back to Tesseract OCR via `pdftoppm` for scanned/image-only PDFs) into `pdf_pages` for search.
2. Adds/updates the `folders`/`files` catalog entry (`catalogFile()`).
3. Uploads the file to R2 at the matching key (`r2Upload()`), so it's servable immediately.
4. Posts progress to Discord (`DISCORD_WEBHOOK_URL`) for long OCR jobs.

The local file can be deleted after indexing — it's only needed on disk long enough for the OCR/text-extraction tools to read it.

## Configuration

`config.local.php` (gitignored):

```php
define('DB_DSN', 'mysql:host=localhost;dbname=manuals_db;charset=utf8mb4');
define('DB_USER', 'manuals_usr');
define('DB_PASS', '...');
define('DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/...');
```

`.rclone.conf` (gitignored) — R2 S3-compatible credentials, same shape as `files.hondabase.com`'s:

```ini
[r2manuals]
type = s3
provider = Cloudflare
access_key_id = ...
secret_access_key = ...
endpoint = https://<account-id>.r2.cloudflarestorage.com
acl = private
no_check_bucket = true
```

## Hosting

Runs on the same box as `hondabase.com`, `www.hondabase.com`, and `files.hondabase.com` (nginx + PHP-FPM, shared Let's Encrypt cert covering all `*.hondabase.com` subdomains in use).
