# Deploying dashr

dashr runs on any host with PHP 8.2+ and the `pdo_sqlite` extension. There is no build step. Point a web server at `public/` and you're done.

## What dashr needs at runtime

- PHP 8.2+ with `pdo_sqlite` and `curl`.
- A `.env` file at the project root (sibling of `public/`) — never web-accessible.
- A writable `data/` directory (also at the project root, also not web-accessible).
- HTTPS strongly recommended (admin login + API token transit).

## Generic web-server setup

Whatever stack you use (nginx, Apache, Caddy, Forge, lighttpd, …):

1. Clone the repo.
2. `cp .env.example .env` and fill it in (see "Configuration" in [README](README.md)).
3. Generate an admin password hash and API token:
   ```bash
   php -r 'echo password_hash("YOUR_PASSWORD", PASSWORD_BCRYPT) . "\n";'
   php -r 'echo bin2hex(random_bytes(24)) . "\n";'
   ```
4. Point the web server's document root at `public/`.
5. Make `data/` writable by the PHP process user:
   ```bash
   chown -R <php-user>:<php-user> data && chmod 755 data
   chmod 600 .env
   ```
6. The first request creates `data/links.db` automatically — schema runs idempotently on every boot, so no migration tooling is needed.

### nginx example

```nginx
server {
    listen 443 ssl http2;
    server_name links.example.com;
    root /var/www/dashr/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Apache

If your virtual host's `DocumentRoot` is `/var/www/dashr/public`, no rewrites are needed — every request hits `index.php` via the existing routing. If you cannot set `DocumentRoot`, drop a `.htaccess` in `public/`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

### Caddy

```
links.example.com {
    root * /var/www/dashr/public
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}
```

## Laravel Forge (one-click)

dashr's PHP-only / no-build profile is a perfect fit for Forge:

1. **Create site**
   - Domain: `links.example.com`
   - Project type: General PHP / Static
   - Web Directory: `/public`
   - PHP Version: 8.2 or newer
2. **Provision HTTPS** via Forge's Let's Encrypt one-click.
3. **Connect the Git repo** and `forge deploy` (or `git pull` from the server).
4. **Generate secrets** locally and paste them into Forge's Environment editor (Forge writes them to `.env` for you):
   ```
   ADMIN_PASSWORD_HASH='$2y$12$...'
   API_TOKEN='<random hex>'
   BASE_URL='https://links.example.com'
   SITE_NAME='your shortener name'
   ```
5. **Make `data/` writable** via SSH:
   ```bash
   chown -R forge:forge data && chmod 755 data
   ```
6. First request creates `data/links.db` automatically.

## Smoke test

After deploy:

```bash
# Should return the entry form HTML
curl -s https://links.example.com/ | grep 'name="code"'

# Create a link via API
curl -X POST https://links.example.com/api/links \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com"}'
# → {"code":"XXX-XXX","short_url":"https://links.example.com/XXX-XXX"}
```

## Backup

The entire app state is `data/links.db`. To back it up safely (WAL-aware):

```bash
sqlite3 data/links.db ".backup /tmp/links-$(date +%F).db"
```

A nightly cron is the simplest scheduling option. Forge has a built-in scheduler; on plain hosts, drop a line into `crontab -e`.

## Updating

```bash
cd /path/to/dashr
git pull
```

No migrations — the schema is idempotent and runs on every boot.
