# Deploy to Websupport hosting (tomas.gart.sk)

Production target:
- **Domain**: `tomas.gart.sk` — serves both the frontend SPA and `/api/v1/*`
- **Postgres**: `db.r2.websupport.sk:5432` (managed; only reachable from the
  hosting servers)
- **PHP**: 8.3+
- **Shell**: SSH available

Everything below runs on the Websupport server, not your laptop. The DB is
firewalled to hosting IPs so local `php artisan migrate` will fail —
migrations must be triggered from the server.

## 1. Upload the backend

Pick one:

**A) git clone on the server (recommended if SSH + git are available):**

```bash
cd ~/web/  # or wherever Websupport puts your document root
git clone <your-repo-url> lodenica
cd lodenica/backend-php
composer install --no-dev --optimize-autoloader --no-interaction
```

**B) rsync from local laptop:**

```bash
# from /home/tomas/projects/lodenica on your laptop
rsync -avz --delete \
  --exclude='.env' --exclude='vendor' --exclude='node_modules' \
  --exclude='storage/logs/*' --exclude='storage/framework/cache/*' \
  backend-php/ user@tomas.gart.sk:~/web/lodenica/backend-php/

# then on the server:
cd ~/web/lodenica/backend-php
composer install --no-dev --optimize-autoloader --no-interaction
```

## 2. Create the production `.env`

```bash
cd ~/web/lodenica/backend-php
cp .env.production.example .env
php artisan key:generate           # writes APP_KEY into .env
```

Then `nano .env` and fill in the three DB blanks. Final values for this
deployment:

```
DB_DATABASE=tomas-test
DB_USERNAME=<from Websupport admin>
DB_PASSWORD=<from Websupport admin>
```

Set strict file permissions:

```bash
chmod 600 .env
chmod -R 775 storage bootstrap/cache
```

## 3. Run the migration

```bash
php artisan migrate --force
```

This creates the `btree_gist` + `pgcrypto` extensions, all enums, the five
tables and the `EXCLUDE USING gist` constraint that guarantees no two
CONFIRMED reservations overlap. **If Websupport doesn't allow CREATE
EXTENSION**, run the equivalent SQL manually as the DB owner (or open a
support ticket — extensions are typically pre-installed on managed
Postgres). To verify:

```bash
php artisan tinker --execute="DB::select('SELECT extname FROM pg_extension');"
```

You should see `btree_gist` and `pgcrypto` in the output.

Pick **one** of the following to populate the DB:

**A) Import the real club inventory from the Google Sheet (recommended):**

```bash
php artisan lodenica:import-sheet --force
```

This fetches the CSV from
https://docs.google.com/spreadsheets/d/1PfWpT2bBr37j2W8G_JBrMCXskL-906kyDQ17oQocFCY/
parses the section-grouped rows, recreates the two boathouse spaces
(`SPACE-NOVA`, `SPACE-STARA`), inserts every kayak / canoe / inflatable /
rowing boat / trailer with the right `ResourceType`, and adds a handful of
sample reservations for the timeline view. It is **destructive** — wipes
existing damages, reservations and resources first. Use `--no-sample-reservations`
to skip the demo bookings, or `--csv-file=/path/to/export.csv` to import
from a manually downloaded snapshot.

Re-run the same command after every sheet update to refresh inventory.
The boathouse spaces and seeded sample reservations are always recreated.

**B) Tiny demo dataset (for early UI testing only):**

```bash
php artisan db:seed --force
```

## 4. Cache config + routes for production speed

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

If you ever change `.env`, `config/*` or routes on the server, re-run these.

## 5. Point Websupport's web root at `public/`

The most common Websupport layout puts your domain at `~/web/`. Two ways:

**A) Symlink (cleanest):** in the Websupport admin, set the domain document
root to `~/web/lodenica/backend-php/public`.

**B) Symlink workaround if the admin doesn't allow custom document roots:**

```bash
cd ~/web        # current document root
rm -rf index.html index.php
ln -s ~/web/lodenica/backend-php/public public-laravel
# then either move the contents of public-laravel/ to ~/web/ root or
# adjust an .htaccess to rewrite to it.
```

The cleanest is option A. Open a support ticket if the admin doesn't expose
it.

## 6. Deploy the frontend into `public/`

The Vue SPA in `frontend/` builds into `frontend/dist/`. Drop those files
into the Laravel `public/` directory so Apache serves them as static files,
and Laravel's `Route::fallback()` returns `public/index.html` for any
unmatched non-API path (so Vue Router works on refresh).

Build locally and upload:

```bash
# laptop
cd frontend
pnpm install
VITE_API_BASE_URL=https://tomas.gart.sk/api/v1 pnpm build
rsync -avz dist/ user@tomas.gart.sk:~/web/lodenica/backend-php/public/
```

Now https://tomas.gart.sk/ serves the SPA, and the SPA's axios client hits
https://tomas.gart.sk/api/v1/* (same origin — no CORS overhead).

## 7. Verify

From your laptop:

```bash
curl -s https://tomas.gart.sk/health | jq
curl -s https://tomas.gart.sk/api/v1/resources | jq '.total, .items[0]'
curl -s https://tomas.gart.sk/api/v1/availability/dashboard | jq '.totals'
```

The first call should report `database: "up"`. If you get HTML instead of
JSON, Apache is hitting a static file before Laravel — re-check that the
web root points to `public/` and that `.htaccess` is enabled
(`AllowOverride All` in Apache config; Websupport allows this by default).

## 8. Updates

For subsequent deploys:

```bash
cd ~/web/lodenica
git pull
cd backend-php
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

## Rotating the DB password

The password from the initial setup was shared over chat — rotate it in the
Websupport admin and update `.env` after first successful deploy. Then:

```bash
php artisan config:clear
php artisan config:cache
```

Laravel reads `.env` only at boot; cached config keeps the *old* password
until you clear it.
