# WordPress.org submission — reviewer notes

Text to paste when replying to the review thread. Keep in sync with
readme.txt (== External Services ==, == Source Code & Build ==).

---

Hello, and thank you for the detailed review. All reported issues are
addressed in the updated zip (0.2.0). Point-by-point:

**Ownership / name.** "Agentyllo" is an invented word with no other use or
registration; we own and operate agentyllo.com (the Plugin URI). To make
ownership verifiable: [CHOOSE ONE — (a) this account's email is now
<name>@agentyllo.com / (b) please transfer this submission to the account
"<username>", registered with an @agentyllo.com email]. We have also added
the DNS TXT record `wordpressorg-totaliweb-verification` at the root of
agentyllo.com. We would like to KEEP the slug `agentyllo`.

**Trialware (Guideline 5).** The 30-day analytics restriction is removed —
all statistics ranges (7/30/90 days) are available to everyone and nothing
in the plugin is locked, limited or license-checked. Optional extras are
separate plugins distributed from agentyllo.com; the plugin only lists them
on its own admin "Addons" page (admin-facing, per the guideline
clarifications).

**Phoning home (Guidelines 7/9).** The registry sync is now strictly
opt-in: nothing is fetched unless the admin presses "Sync now" or
explicitly enables weekly auto-sync (default OFF). The request carries no
site data and no plugin version; the endpoint serves a static, Ed25519-
signed JSON manifest (model ids and prices — data, never code), documented
in External Services.

**Credit links (Guideline 10).** The "Powered by Agentyllo" link is now
OFF by default and renders only if the site owner enables the dedicated
checkbox in Settings → Widget.

**Prefixes.** All declarations, globals and stored data now use the
4-character prefix `agyl_` (constants `AGYL_`), namespace `Agentyllo\`,
REST namespace `agentyllo/v1`.

**Writing to uploads.** The plugin no longer writes any .htaccess file.
Private DSAR exports rely on wp_upload_dir()-resolved paths, index.php
placeholders, 24-character random filenames and automatic deletion after
72 hours.

**Public source.** The full source (un-minified TypeScript for
assets/build/*.js, build config, tests) is public:
https://github.com/antonio86itna/agentyllo — documented in readme
"Source Code & Build" together with the build steps
(`npm install && npm run build`). lib/action-scheduler is Action Scheduler
4.1.0, unmodified (it self-arbitrates between multiple loaded copies).

**Translation files** are no longer shipped in the zip.

**Contributors** now lists both accounts.

**WordPress AI Client.** The direct provider integrations exist because
the plugin streams answers token-by-token (SSE) into the visitor chat and
enforces a per-request cost/latency budget with mid-stream fact checking —
capabilities the core AI Client does not expose yet. Providers only
activate with the owner's own API key (default is a no-AI classic mode),
and the plugin honours the wp-config/environment key convention used by
the AI Client. We plan to adopt the AI Client for non-streaming tasks as
it matures.

Thank you again — happy to clarify anything.
