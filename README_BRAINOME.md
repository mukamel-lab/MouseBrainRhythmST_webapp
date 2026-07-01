# Diurnal Brain Transcriptome Atlas — Brainome PHP/SQLite deployment

This package is a self-contained Apache/PHP version of the app. **R, Node.js, npm, and a long-running backend process are not required on Brainome.**

The scientific calculations remain in the offline R export. At runtime, PHP reads three read-only SQLite databases and returns JSON or SVG to the compiled React browser app.

## Required database files

Copy the three databases produced by `export_brainome_sqlite.R` into `data-private/`:

```text
data-private/
├── diurnal.sqlite
├── dorsal_ventral.sqlite
└── supplemental.sqlite
```

The databases are deliberately not included in the app package.

## Expected deployment layout

```text
MouseBrainRhythmST3/
├── index.php
├── index.html
├── .htaccess
├── assets/
├── api/
├── metadata/
├── cache/
├── data-private/
└── check.php
```

The app is built with relative URLs, so it can run from a subdirectory such as:

```text
https://brainome.ucsd.edu/MouseBrainRhythmST3/
```

## Install without sudo

The commands below assume your Brainome application directory is:

```text
$HOME/mysqlpool/emukamel/MouseBrainRhythmST3
```

Adjust the path if needed.

### 1. Back up any existing copy

```bash
cd "$HOME/mysqlpool/emukamel"

if [ -d MouseBrainRhythmST3 ]; then
  mv MouseBrainRhythmST3 "MouseBrainRhythmST3.backup.$(date +%Y%m%d_%H%M%S)"
fi
```

### 2. Extract the PHP app

```bash
cd "$HOME/mysqlpool/emukamel"
tar -xzf /path/to/brainome_diurnal_php_sqlite_app.tar.gz
mv brainome_diurnal_php_sqlite_app MouseBrainRhythmST3
cd MouseBrainRhythmST3
```

### 3. Copy the exported SQLite databases

For example, if the export directory is in your home directory:

```bash
cp /path/to/brainome-sqlite-export/diurnal.sqlite data-private/
cp /path/to/brainome-sqlite-export/dorsal_ventral.sqlite data-private/
cp /path/to/brainome-sqlite-export/supplemental.sqlite data-private/
```

Verify:

```bash
ls -lh data-private/*.sqlite
```

### 4. Set permissions

Start with private/read-only permissions:

```bash
chmod 755 . api assets metadata data-private
chmod 755 cache
chmod 644 index.php index.html .htaccess
find api -type d -exec chmod 755 {} +
find api -type f -exec chmod 644 {} +
find assets metadata -type f -exec chmod 644 {} +
chmod 644 data-private/*.sqlite data-private/.htaccess data-private/index.php
```

The API never writes to the SQLite databases. `cache/` only stores optional Allen API metadata. If PHP cannot write there, the Allen panel still works but does not cache responses.

If `check.php` says the databases are unreadable, Brainome may run Apache/PHP under a different account. In that case, first ask the server administrator how existing PHP apps grant file access. For public study data, a fallback is:

```bash
chmod 755 data-private
chmod 644 data-private/*.sqlite
```

The included `data-private/.htaccess` denies HTTP access to that directory.

## Verify server capabilities

Open this temporary diagnostic page:

```text
https://brainome.ucsd.edu/MouseBrainRhythmST3/check.php
```

You need:

```text
PHP 7.4 or newer
PDO SQLite enabled
all three databases readable
compiled frontend present
```

For the Allen panel, PHP additionally needs either the cURL extension or `allow_url_fopen=1`, and the server must be permitted to make outbound HTTPS requests.

You can also inspect command-line PHP, but Apache may use a different PHP configuration:

```bash
php -v
php -r 'print_r(PDO::getAvailableDrivers()); echo "curl=".(extension_loaded("curl")?"yes":"no")." allow_url_fopen=".ini_get("allow_url_fopen").PHP_EOL;'
```

Before removing the diagnostic page, also verify that Apache blocks direct database access:

```bash
BASE='https://brainome.ucsd.edu/MouseBrainRhythmST3'
curl -I "$BASE/data-private/diurnal.sqlite"
```

The response should be `403 Forbidden` (or `404 Not Found`), never `200 OK`. If `.htaccess` protection is not enabled, move the databases outside the web document root and place their absolute directory in `api/db-path.txt`.

After setup, rename or remove the diagnostic file:

```bash
mv check.php check.php.disabled
```

## Test the API

In a browser:

```text
https://brainome.ucsd.edu/MouseBrainRhythmST3/api/index.php?route=health
```

Or from a shell:

```bash
BASE='https://brainome.ucsd.edu/MouseBrainRhythmST3'

curl -fsS "$BASE/api/index.php?route=health"
curl -fsS "$BASE/api/index.php?route=genes&q=Dbp"
curl -fsS "$BASE/api/index.php?route=rhythmicity&gene=Dbp&limit=5"
curl -fsS "$BASE/api/index.php?route=hippocampus-dv&gene=Lct&split_by=none"
```

Then open:

```text
https://brainome.ucsd.edu/MouseBrainRhythmST3/
```

## Database directory outside the web app (optional)

The default `api/db-path.txt` contains:

```text
data-private
```

To keep databases elsewhere, place their absolute directory path on the first line of `api/db-path.txt`. For example:

```bash
printf '%s\n' "$HOME/private/diurnal-databases" > api/db-path.txt
```

This only works if Apache/PHP is permitted to read that location (`open_basedir` may restrict it).

## Parent-page redirect (optional)

If a separate Brainome `index.php` should point visitors to this app:

```php
<?php
header('Location: MouseBrainRhythmST3/');
exit;
```

Use the actual relative directory name.

## Runtime API routes

The frontend calls one query-string router, so Apache rewrite rules are not required:

```text
api/index.php?route=health
api/index.php?route=metadata
api/index.php?route=genes&q=Dbp
api/index.php?route=plot.svg&gene=Dbp
api/index.php?route=spatial&gene=Dbp
api/index.php?route=rhythmicity&gene=Dbp
api/index.php?route=hippocampus-dv&gene=Lct
api/index.php?route=hippocampus-dv/plot.svg&gene=Lct
api/index.php?route=allen/ish&gene=Dbp
```

## Updating data

Generate new SQLite files on the analysis machine, upload them under temporary names, and rename them only after upload completes:

```bash
scp diurnal.sqlite brainome:~/mysqlpool/emukamel/MouseBrainRhythmST3/data-private/diurnal.sqlite.new
ssh brainome 'cd ~/mysqlpool/emukamel/MouseBrainRhythmST3/data-private && mv diurnal.sqlite.new diurnal.sqlite'
```

Repeat for the other databases. This avoids PHP opening a partially uploaded file.

## Source and rebuilding

The deployable browser bundle is already in `index.html` and `assets/`. Brainome does not need npm.

Editable React source is included under `frontend_src/`. To rebuild on a development machine:

```bash
cd frontend_src
npm ci
npm run lint
npm run build
cp ../dist/index.html ..
rsync -a --delete ../dist/assets/ ../assets/
```

## Security notes

- All SQL queries use prepared statements.
- SQLite databases are opened with `PRAGMA query_only=ON`.
- The app does not accept file uploads or execute R/Python commands.
- Directory listings are disabled.
- Direct HTTP access to SQLite/RDS/Parquet files is denied by `.htaccess`.
- Remove `check.php` after validation.
