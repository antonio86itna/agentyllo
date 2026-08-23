# WordPress.org submission — reviewer notes

Text to paste into the "Notes for the reviewer" field (or the reply email)
when submitting `agentyllo-x.y.z.zip`. Keep in sync with readme.txt
(== External Services ==) when anything changes.

---

Hello, and thank you for reviewing Agentyllo. A few notes up front on the
areas that usually deserve a closer look:

**1. No remote code — ever.**
The plugin periodically fetches a small JSON manifest from
`https://registry.agentyllo.com/v1/stable.json` (weekly, or on the explicit
"Sync now" button). It contains DATA ONLY: AI model ids, prices and prompt
version numbers. It is verified against a Ed25519 signature with a public
key pinned in the plugin (`src/Infra/Crypto/Ed25519Verifier.php`), with a
sequence number to prevent rollback. A bundled snapshot
(`assets/registry/stable.json`) is used when the endpoint is unreachable, so
the plugin is fully functional offline. Nothing executable is ever
downloaded, and the sync can be disabled in AI Models settings.

**2. No application binaries are downloaded or executed.**
The WordPress.org build connects to local AI engines the user ALREADY runs
(llama.cpp llama-server, Ollama, LM Studio — a user-supplied localhost URL).
The one-click installer for engines/models lives in a separate companion
plugin ("Agentyllo Local AI") distributed from our own site, not on
WordPress.org. The capability detector only checks `function_exists`
availability for `proc_open` — it never executes external processes.

**3. Third-party AI services are opt-in and off by default.**
The plugin ships in "classic" mode: pure-PHP agents answer from the site's
own content with no AI calls at all. OpenAI (`api.openai.com`) and Anthropic
(`api.anthropic.com`) are contacted ONLY after the site owner enters their
own API key and switches the operating mode. What is sent (the visitor
message plus relevant excerpts of the site's public content), when, and
under which terms is documented in readme.txt → External Services, with
links to each provider's terms and privacy policy. API keys are stored
encrypted (libsodium secretbox) with a random key dedicated to this
purpose — never derived from WP salts — and are never printed back in full.

**4. The admin "copilot" does not execute arbitrary code.**
It only runs a fixed registry of predefined, schema-validated actions
(`src/Copilot/ActionRegistry.php`, `CoreActions.php`): add/edit KB entries,
change whitelisted non-secret settings, run stats queries, re-crawl.
Every action is sanitized against a JSON-schema-style argument spec, gated
by capabilities, and destructive ones require an explicit human click that
carries a single-use HMAC confirmation token (10-minute TTL, bound to user,
action and arguments). Everything is written to an audit log.

**5. Direct database queries.**
The plugin owns 22 custom tables (prefix `agy_`) for its knowledge base,
conversations, stats and audit data — content that does not fit posts/meta
(inverted search index, vectors, sliding-window rate events, append-only
audit log). All queries go through `$wpdb->prepare()`; table names are
`$wpdb->prefix` plus literal constants. The repository-level
`phpcs:disable` headers each carry a justification. The IN()-clause
placeholder lists are built from `count()` of the values passed to
`prepare()`.

**6. Hook prefix.**
All hooks, options, tables and REST routes use the `agy_` / `agentyllo/v1`
prefix consistently (readme's Stable slug is `agentyllo`). `agy_` is our
registered short prefix; it is used nowhere else on the directory.

**7. Bundled library.**
`lib/action-scheduler/` is Action Scheduler 4.1.0, bundled verbatim
(standard practice, same as WooCommerce ecosystem plugins). It is excluded
from our coding-standards tooling but unmodified.

**8. Privacy & EU AI Act.**
Visitor chat is cookieless (HMAC session tokens). IP addresses are stored
only as salted monthly-rotated hashes (or not at all). Data export/erasure
integrates with the WordPress core personal-data tools, plus an admin UI.
The widget always shows an "Automated assistant" / "AI Assistant"
disclosure (locked on when an AI mode is active), and the plugin can
generate a transparency page describing the system, per EU AI Act Art. 50.

**9. Uninstall.**
Uninstall behaviour is user-controlled (Settings → Advanced): keep data,
remove settings, or remove everything (all `agy_` tables, options and
uploaded files, via WP_Filesystem).

Test hints: the plugin works with zero configuration on a fresh site —
activate, let the crawler index (or open Knowledge Base → Rebuild index),
then ask the front-end widget about any page content. A one-click demo
blueprint is available at
https://www.agentyllo.com/downloads/playground-link.html

Source repository: https://github.com/antonio86itna/agentyllo
(PHPStan level 6 clean; PHPUnit suite; CI on PHP 8.2/8.3/8.4).

Thank you!
