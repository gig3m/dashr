# southside.cc URL Shortener — Design

**Date:** 2026-05-06
**Domain:** southside.cc
**Runtime:** PHP 8.x + SQLite, deployed via Laravel Forge

## Goal

A small-volume, personal-use URL shortener using human-friendly 6-digit numeric codes (e.g., `456-767`). Codes are easy to read aloud, type, or include in print. Two creation paths: an admin web form and a token-authenticated API. A public landing page lets anyone enter a code to be redirected; direct `/{code}` URLs also work.

## Non-Goals

- High volume. The full namespace is 10⁶ codes; far fewer will ever be used.
- Public link creation. Only the admin (web UI or API) creates links.
- Analytics, click tracking, or per-link stats.
- Link editing. To change a link, delete and re-create it.
- Expiration / TTL. Links live forever.
- Rate limiting. Mitigated by admin-only creation; can be added later if needed.

## Architecture

Plain PHP front controller + SQLite. No framework, no Composer dependencies, no build step. Single SQLite file holds all data. The file lives outside the web root so it cannot be served as a static download.

### Directory layout

```
shortener/
├── public/
│   └── index.php          # front controller — entry point for all routes
├── src/
│   ├── db.php             # PDO connection + auto-migration on boot
│   ├── routes.php         # request handlers, dispatched by index.php
│   └── auth.php           # session helpers + bearer token check
├── views/
│   ├── enter.php          # public code-entry form
│   ├── login.php          # admin login form
│   ├── admin.php          # admin: link list + create form
│   └── notfound.php       # generic "code not found" page
├── data/                  # NOT web-accessible
│   └── links.db           # created on first request
├── .env                   # ADMIN_PASSWORD_HASH, API_TOKEN
├── .env.example
└── .gitignore
```

Forge points the site's web root at `public/`. Everything else is a sibling and unreachable from the web.

## Routes

### Public

| Method | Path        | Behavior                                                                     |
|--------|-------------|------------------------------------------------------------------------------|
| GET    | `/`         | Renders `views/enter.php` — a centered form with a 6-digit code input.       |
| POST   | `/go`       | Reads `code` from form, normalizes to digits, looks up. 302 to URL or 404.   |
| GET    | `/{code}`   | Same lookup as `/go` but from the URL path. Accepts `123456` or `123-456`.   |

### Admin (session-protected)

| Method | Path                          | Behavior                                                |
|--------|-------------------------------|---------------------------------------------------------|
| GET    | `/admin`                      | If authed, redirect to `/admin/links`. Otherwise login. |
| POST   | `/admin/login`                | Verifies password, sets `$_SESSION['admin'] = true`.    |
| POST   | `/admin/logout`               | Destroys session, redirects to `/`.                     |
| GET    | `/admin/links`                | Lists links (newest first). Includes create form.       |
| POST   | `/admin/links`                | Creates a link. Body: `url`, optional `code`.           |
| POST   | `/admin/links/{code}/delete`  | Deletes the link.                                       |

### API (bearer-token-protected)

| Method | Path          | Behavior                                                                                       |
|--------|---------------|------------------------------------------------------------------------------------------------|
| POST   | `/api/links`  | JSON in: `{"url": "...", "code": "optional"}`. JSON out: `{"code": "456-767", "short_url": "https://southside.cc/456-767"}`. |

API errors return JSON: `{"error": "message"}` with appropriate HTTP status (400 invalid input, 401 unauthorized, 409 code taken, 500 unexpected).

## Schema

```sql
CREATE TABLE IF NOT EXISTS links (
  code       TEXT PRIMARY KEY,    -- '456767', exactly 6 digits, no hyphen
  url        TEXT NOT NULL,
  created_at INTEGER NOT NULL     -- unix timestamp (seconds)
);
```

That's the entire schema. SQLite auto-creates the file on first connection; the app runs the `CREATE TABLE IF NOT EXISTS` on every boot, which is cheap and idempotent.

## Code Generation

- For auto-assigned codes: `random_int(0, 999999)`, then `str_pad($n, 6, '0', STR_PAD_LEFT)`.
- Attempt `INSERT`; on `UNIQUE` constraint violation, retry up to 10 times. At low fill rate, collision probability per attempt is `usedCount / 1_000_000`, so retries are essentially never needed.
- For custom codes: validated against `^\d{3}-?\d{3}$`, normalized to digits-only, rejected with 409 if already present.

## Input Normalization

Anywhere a user supplies a code (URL path, form, API), the server:

1. Strips non-digit characters.
2. Requires exactly 6 digits remaining.
3. Looks up by the normalized form.

This means `123-456`, `123456`, `123 456`, and `123.456` all resolve identically. Any other shape returns 404.

## Display Formatting

- Codes are **stored** as 6 raw digits.
- Codes are **displayed** as `XXX-XXX` everywhere humans see them: admin list, API `short_url` field, success pages.
- A trivial helper `format_code('456767') === '456-767'`.

## Authentication

### Admin (session)

- `.env` contains `ADMIN_PASSWORD_HASH`, generated once via `password_hash($pw, PASSWORD_BCRYPT)`.
- Login form posts password; server calls `password_verify`. On success, sets `$_SESSION['admin'] = true`.
- Session cookie attributes: `Secure`, `HttpOnly`, `SameSite=Lax`. PHP session config set in `index.php` before `session_start()`.
- All `/admin/*` routes (except `GET /admin` and `POST /admin/login`) require the session flag; otherwise redirect to `/admin`.

### API (bearer token)

- `.env` contains `API_TOKEN` (random ≥32-char string).
- Server reads `Authorization: Bearer <token>` from the request.
- Comparison via `hash_equals(getenv('API_TOKEN'), $supplied)` (constant-time).
- Missing/invalid token returns `401` with JSON `{"error": "unauthorized"}`.

### CSRF

- Admin forms include a CSRF token stored in `$_SESSION['csrf']`, rendered as a hidden field.
- `POST /admin/*` handlers verify the token with `hash_equals` before mutating state.
- API endpoints are exempt — they require the bearer token, which serves the same role.

## Configuration (.env)

```
ADMIN_PASSWORD_HASH='$2y$12$...'
API_TOKEN='<32+ random chars>'
BASE_URL='https://southside.cc'
```

A minimal `.env` parser (no Composer / vlucas/phpdotenv needed): split lines on `=`, trim, ignore `#` comments. Loaded once at the top of `public/index.php` into `$_ENV`.

`BASE_URL` is used to construct `short_url` in API responses.

## Error Handling

- `/{code}` and `/go` with an unknown code → 404 + `views/notfound.php`.
- Malformed code → same 404 path.
- API errors → JSON with `error` field and appropriate status code.
- Unhandled PHP exceptions → 500 with a generic page; the actual error is logged via PHP's default error log (Forge captures it).
- Database errors are not retried for read paths; only the insert-collision retry described above.

## Setup & Deployment

1. Forge: create a new site pointing to `southside.cc`, web root `/public`.
2. PHP 8.2+ (default on current Forge).
3. HTTPS via Let's Encrypt (Forge one-click).
4. Upload `.env` (not in git) with `ADMIN_PASSWORD_HASH`, `API_TOKEN`, `BASE_URL`.
5. Ensure the `data/` directory is writable by the PHP user (e.g., `forge`).
6. First request creates `data/links.db` automatically.

`.gitignore`: `.env`, `data/links.db`.

## Testing Strategy

For a project this small, a handful of focused tests are enough:

- **Code normalization** — unit test `normalize_code()` against `123-456`, `123456`, `12-3456`, `abcd-ef`, `1234567`.
- **Code generation collision** — mock the random source to force collisions; verify retry succeeds.
- **Auth** — request `/admin/links` without a session → redirect; with session → 200. Request `/api/links` without bearer → 401; with bearer → 200.
- **End-to-end** — create a link via API, then `GET /{code}` → 302 to expected URL.

Tests run against a temporary SQLite file (`data/test.db`), wiped between runs. PHPUnit if comfortable, or a simple `tests/run.php` script — pick during implementation.

## Open Items

None. All decisions resolved during brainstorming.
