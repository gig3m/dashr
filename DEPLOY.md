# Deploying southside.cc shortener to Forge

## One-time setup

1. **Forge: create site**
   - Domain: `southside.cc`
   - Project type: General PHP / Static
   - Web Directory: `/public`
   - PHP Version: 8.2 or newer

2. **Provision HTTPS** via Forge's Let's Encrypt one-click.

3. **Deploy the code**: connect the Git repo and run `git pull`, or push via `forge deploy`. There is no build step.

4. **Generate secrets**

   On any machine with PHP:
   ```bash
   php -r 'echo password_hash("YOUR_PASSWORD_HERE", PASSWORD_BCRYPT) . "\n";'
   php -r 'echo bin2hex(random_bytes(24)) . "\n";'
   ```

5. **Create `.env` on the server** (project root, sibling of `public/`):

   ```
   ADMIN_PASSWORD_HASH='$2y$12$...'
   API_TOKEN='<the random hex from above>'
   BASE_URL='https://southside.cc'
   ```

   Permissions: `chown forge:forge .env && chmod 600 .env`.

6. **Ensure `data/` is writable**:
   ```bash
   chown -R forge:forge data && chmod 755 data
   ```

7. **First request** creates `data/links.db` automatically.

## Smoke test

After deploy:

```bash
# Should return the entry form HTML
curl -s https://southside.cc/ | grep 'name="code"'

# Create a link via API
curl -X POST https://southside.cc/api/links \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://anthropic.com"}'

# Should respond with {"code":"XXX-XXX","short_url":"https://southside.cc/XXX-XXX"}
```

## Backup

The entire app state is `data/links.db`. To back it up:

```bash
sqlite3 data/links.db ".backup /tmp/links-$(date +%F).db"
```

Schedule this nightly via Forge's scheduler (cron) if you care.

## Updating

```bash
cd /home/forge/southside.cc
git pull
```

No migrations — schema is idempotent and runs on every boot.
