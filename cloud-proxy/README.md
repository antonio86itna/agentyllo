# Agentyllo Cloud — free-AI proxy

The centralized service behind the plugin's **“Agentyllo Cloud — Free AI, no
API key”** tier. It gives every Agentyllo site a monthly quota of free AI by
pooling **OpenRouter free models** behind rotating keys, auto-selecting the best
free model, and enforcing a hard **paid-model block**.

Zero runtime dependencies (Node's built-in `http` + `fetch`). State is a single
JSON file. Designed to drop onto your **Hetzner box via Plesk (Passenger)**.

## What it does

- `POST /v1/register` `{domain, site_url, plugin_version}` → `{site_token}`
  (idempotent per domain; rate-limited per client IP).
- `POST /v1/chat` (Bearer token) → an OpenAI-style answer from a **`:free`**
  OpenRouter model, with per-domain monthly quota + per-minute burst limits.
- `GET /v1/usage` (Bearer token) → `{used, limit, remaining, resets_at, period}`.
- `GET /healthz`, `GET /admin/stats` (Bearer `ADMIN_TOKEN`).

**Paid models can never be used:** every model id sent ends in `:free` and its
pricing is verified `$0`; a `402` aborts instead of spending; failed calls are
never charged to a domain’s quota and we honour `Retry-After` (a failed request
still counts against a *key’s* daily free allowance, so we rotate, we don’t
hammer).

## 1. OpenRouter setup (do this first)

1. Create an account at <https://openrouter.ai>.
2. **Buy $10 of credits once.** This is a *permanent* unlock that raises each
   key’s free-model allowance from **50 → 1,000 requests/day** (the per-minute
   cap stays 20/min). You do **not** need to keep a balance; you can spend it to
   zero and keep the 1,000/day tier.
3. Create one or more **API keys** (Keys page). More keys = more daily
   throughput (the proxy rotates across them). For belt-and-braces, set each
   key’s **credit limit to 0** (or a few cents) so even a hypothetical paid call
   can’t spend — the proxy already only sends `:free` ids.
4. (Optional) In **Settings → Privacy**, keep prompt logging off if you prefer.

Put the keys in `OPENROUTER_KEYS` (comma-separated).

## 2. Deploy on Plesk (Node.js / Passenger)

1. **DNS:** point `api.agentyllo.com` (an A/AAAA record) at the server, and add
   the domain/subdomain in Plesk with a Let’s Encrypt certificate (HTTPS).
2. Upload this `cloud-proxy/` folder to the domain’s document root area (e.g.
   `httpdocs/` or a sibling like `/var/www/vhosts/agentyllo.com/api/`).
3. In Plesk: **Websites & Domains → Node.js**:
   - **Application root** → the folder you uploaded.
   - **Application startup file** → `src/server.js`.
   - **Application mode** → `production`.
   - Click **NPM install** (there are no dependencies, but this initializes it).
   - Add **Custom environment variables** from `.env.example` (at least
     `OPENROUTER_KEYS`, `MONTHLY_LIMIT_PER_DOMAIN`), **or** upload a `.env` file.
   - Ensure `DATA_FILE` points somewhere writable and **outside the web root**
     (e.g. `/var/www/vhosts/agentyllo.com/cloud-data/state.json`).
   - **Enable Node.js** / **Restart App**.
4. Passenger sets `PORT` automatically and proxies HTTPS → the app. Confirm:
   `curl https://api.agentyllo.com/healthz` → `{"ok":true,...}`.

> No Plesk Node.js? Run it under systemd or pm2 instead
> (`node src/server.js`) behind an Nginx/Apache HTTPS reverse proxy that
> forwards to `PORT`. The app honours `X-Forwarded-For` for per-IP limits.

## 3. Point the plugin at it

The plugin already targets `https://api.agentyllo.com` by default. To use a
different host, add to a site (or mu-plugin):

```php
add_filter( 'agyl_cloud_endpoint', fn () => 'https://api.agentyllo.com' );
```

Then in **wp-admin → Agentyllo → AI Models**, click **“Turn on free AI.”**

## 4. Tuning & anti-abuse

- `MONTHLY_LIMIT_PER_DOMAIN` — the visible per-site monthly quota. Keep total
  expected volume (sites × limit) within `keys × 1,000/day`.
- `PER_MINUTE_PER_DOMAIN`, `REGISTER_PER_HOUR_PER_IP` — burst/abuse guards.
- `PREFERRED_MODELS` — bias which free models are chosen first (the roster
  rotates; these are hints). `MODEL_FALLBACK_COUNT` — how many free models to
  offer OpenRouter as an in-request fallback chain.
- Add more `OPENROUTER_KEYS` as you grow; the proxy load-balances and cools down
  any key that returns `429`.

## 5. Local smoke test

```bash
MOCK=1 PORT=8788 MONTHLY_LIMIT_PER_DOMAIN=3 node src/server.js
# then, in another shell:
curl -X POST localhost:8788/v1/register -d '{"domain":"example.com"}'
curl -X POST localhost:8788/v1/chat -H "Authorization: Bearer <token>" -d '{"messages":[{"role":"user","content":"hi"}]}'
```

`MOCK=1` returns a canned reply so you can verify register → chat → usage →
quota without spending anything. Set `MOCK=0` (default) with real keys in
production.
