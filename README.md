# Agentyllo — monorepo

Intelligent AI assistant chatbot for WordPress: classic PHP agents + free local AI + optional cloud models (OpenAI / Anthropic), with a self-maintaining knowledge base.

## Layout

| Path | Purpose |
|---|---|
| `agentyllo/` | The main plugin (WordPress.org distribution) |
| `agentyllo-local-ai/` | Companion plugin: consented local AI engine/model installer + `llama-server` supervisor (distributed from agentyllo.com, not WP.org) |
| `tools/` | `build-zip.py` packaging, registry Ed25519 signing (`tools/registry/sign.php`), WordPress Playground blueprint |

**Try it now** — [live demo on WordPress Playground](https://www.agentyllo.com/downloads/playground-link.html) (pristine WordPress + Agentyllo 0.1.0, no API key) · [Download 0.1.0](https://www.agentyllo.com/downloads/agentyllo-0.1.0.zip) · [agentyllo.com](https://www.agentyllo.com)

## Development

Requirements: PHP 8.2+, Composer, Node 20+, Docker (for `wp-env`).

```bash
# PHP dev dependencies (tests, linting)
cd agentyllo && composer install

# JS build (admin app + widget)
npm install
npm run build        # or: npm run start (watch)

# Local WordPress
npx wp-env start     # http://localhost:8990 (admin/password)
```

See `CONTRIBUTING.md` for the git workflow, release process and conventions. Status: milestones M1–M9 built and verified in wp-env (0.1.0 pre-release); the launch checklist is tracked in GitHub issues. Architecture conventions: namespace `Agentyllo\`, prefix `agy_`, REST namespace `agentyllo/v1`, text domain `agentyllo` (English base, fully i18n).

## Status

| Milestone | Scope | State |
|---|---|---|
| M1 | Platform: bootstrap, schema, capability detector, settings, admin shell | ✅ |
| M2 | Agent kernel: contracts, registry, bus, memory, journal, quarantine | ✅ |
| M3 | Knowledge base: adapters, chunker, index, delta sync, purge | ✅ |
| M4 | Classic chat: pipeline, hybrid retrieval, widget, scope guard | ✅ |
| M5 | Compliance: consents, retention, DSAR, AI Act surfaces | ✅ |
| M6 | Statistics + dashboard | ✅ |
| M7 | Cloud AI + streaming: OpenAI/Anthropic, signed registry, budget, fact guard | ✅ |
| M8 | Local/free AI: vectors, BYO endpoint, bounded tasks, browser toggle, companion | ✅ |
| M9 | Copilot with confirmations, file import, Help, readme, packaging | ✅ core — residuals in issues |

License: GPL-2.0-or-later.
