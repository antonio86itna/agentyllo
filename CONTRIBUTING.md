# Contributing / working on Agentyllo

## Repository layout

| Path | Purpose |
|---|---|
| `agentyllo/` | The plugin distributed on WordPress.org (never downloads/executes code) |
| `agentyllo-local-ai/` | Companion plugin (agentyllo.com): consented installer + supervisor for a local `llama-server` |
| `tools/` | `build-zip.py` (release zips), `registry/sign.php` (Ed25519 signing of the model registry — private key is **never** committed), `playground/blueprint.json` |
| `.github/workflows/` | `ci.yml` (PHP lint + PHPUnit on 8.2/8.3/8.4, JS build, zip artifact) · `release.yml` (tag `vX.Y.Z` → zips attached to a GitHub Release) |

Build artifacts (`agentyllo/assets/build/`, `dist/`) and dev deps are git-ignored; CI rebuilds them.

## Git workflow

- `main` is always releasable. Work happens on short-lived branches: `feat/<topic>`, `fix/<topic>`, `chore/<topic>`.
- Open a Pull Request into `main`; CI must be green. Squash-merge with a Conventional Commit title.
- Commit messages: [Conventional Commits](https://www.conventionalcommits.org) — `feat:`, `fix:`, `perf:`, `refactor:`, `docs:`, `test:`, `chore:`, `build:`. Scope optional: `feat(copilot): …`, `fix(widget): …`.
- Releases: bump `Version:` in `agentyllo/agentyllo.php` + `AGY_VERSION` + `readme.txt` `Stable tag`/changelog, merge, then `git tag vX.Y.Z && git push --tags`. The release workflow verifies the tag matches the plugin version and attaches `agentyllo-X.Y.Z.zip` and `agentyllo-local-ai-X.Y.Z.zip`.
- WordPress.org SVN deploy happens from the tagged zip (manual for now; the `10up/action-wordpress-plugin-deploy` action can be added once the slug is approved).

## Local development

```bash
cd agentyllo && composer install          # PHPUnit + Brain Monkey
cd .. && npm install && npm run build     # admin app + widget (npm run start = watch)
npx wp-env start                          # WordPress on http://localhost:8990 (admin / password)
```

Handy: `npm run lint:php`, `npm run test:php`, `npm run package` (zips into `dist/`).

Live checks live under `agentyllo/tests/e2e-*.php` (run with `npx wp-env run cli wp eval-file wp-content/plugins/agentyllo/tests/<file>.php`); the M7/M8 mocks (`e2e-m7-mock-provider.php` mu-plugin, `e2e-m8-mock-server.php` OpenAI-compatible server) let you exercise every AI path without real keys.

## Conventions

Namespace `Agentyllo\`, prefix `agy_` (tables, options, hooks, REST `agentyllo/v1`), text domain `agentyllo`, PHP 8.2+, `Requires at least: 6.8`. dbDelta schema is owned by `Install\Schema` only (append tables + bump `AGY_DB_VERSION`, mirror in `uninstall.php`). Every provider/AI feature must degrade to the classic floor; hard facts never come from generation (`FactGuard`). Model ids and prices only from the registry snapshot, never hardcoded.

## Secrets

- `tools/registry/keys/` (Ed25519 private key) — git-ignored; keep it in a password manager / CI secret.
- Never commit API keys; the e2e scripts only use obviously fake keys.
