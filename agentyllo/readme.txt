=== Agentyllo ===
Contributors: agentyllo, totaliweb
Tags: ai, chatbot, assistant, woocommerce, support
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart assistant chatbot for WordPress: classic no-AI agents, an automatic knowledge base, optional local AI or your own OpenAI/Anthropic keys.

== Description ==

Agentyllo adds a smart assistant to your site that answers visitors from your own content — pages, posts, custom post types, menus, site settings, WooCommerce products and Elementor content — and keeps that knowledge base fresh automatically.

**Works with zero AI.** Classic agents (pure PHP) understand the question, search your content, extract the answer, link the right pages and products with real thumbnails, and stay strictly on topic. They run on any hosting, behind any page cache, with no configuration.

**AI when you want it.** Add natural prose on top of the classic engine with a free local model you run yourself (llama.cpp / Ollama / LM Studio — or the free *Agentyllo Local AI* companion from agentyllo.com), or with your own OpenAI / Anthropic API key. Five operating modes: classic · free AI · paid AI · classic + free AI · classic + paid AI. AI answers are streamed token by token, always grounded in your content, and hard facts (prices, stock, contacts, links) are always taken verbatim from your site data — never generated.

**Highlights**

* Automatic knowledge base with delta sync and nightly reconciliation; disabling a source removes it from answers immediately.
* Multi-agent ecosystem (17 classic agents) with memories, health checks, quarantine and a learning loop that turns unanswered questions into suggestions.
* Modern, accessible chat widget (WCAG 2.2, keyboard, screen readers, mobile fullscreen, dark mode) with thinking states, formatted answers, internal links, product cards with live prices.
* Backend copilot on every Agentyllo page: manage the knowledge base, settings and memories by command — every change is proposed first and applied only when you confirm; import TXT/Markdown/CSV with a reviewable preview.
* GDPR: optional pre-chat registration, consent evidence, retention, PII redaction, data export/erasure (also via WordPress privacy tools).
* EU AI Act ready: assistant disclosure badge, machine-readable marking of AI-generated messages, audit trail per message, generated transparency page.
* Statistics: conversations, resolution and deflection, top intents, knowledge gaps, latency per tier, tokens and estimated cost — 7/30/90-day ranges, all included.
* Fully translatable; replies in the site language, optionally in the visitor's language with AI.

The core plugin never downloads or executes code from the network. Local model *installation* (binaries and weights) is a separate, consented companion plugin distributed by agentyllo.com — Agentyllo itself only connects to a local engine you already run.

**Addons.** Agentyllo is complete as it is — nothing in this plugin is locked or limited. Optional addons (separate plugins from agentyllo.com, each requiring Agentyllo) add extra capabilities such as document import, advanced analytics, white-labeling, lead capture, live handoff and more. See the Addons page inside the plugin.

== Source Code & Build ==

The complete, human-readable source code — including the un-minified JavaScript/TypeScript for the admin app and the chat widget, and the build configuration — is publicly available at:

https://github.com/antonio86itna/agentyllo

The minified files in `assets/build/` are produced from `src-js/` with `npm install && npm run build` (@wordpress/scripts / webpack). PHP development dependencies are managed with Composer (`composer install` for the test suite and static analysis). The bundled `lib/action-scheduler/` is Action Scheduler 4.1.0, unmodified: https://github.com/woocommerce/action-scheduler

== External Services ==

Agentyllo makes no external request in its default configuration. Every integration below is optional and only active when you configure it:

* **OpenAI API (api.openai.com)** — only when you enter your own OpenAI API key and select an AI operating mode. Visitor messages (optionally with personal data masked), a few relevant knowledge-base excerpts and your assistant instructions are sent to OpenAI to generate the answer or to compute embeddings; requests are sent with storage disabled. Terms: https://openai.com/policies/terms-of-use — Privacy: https://openai.com/policies/privacy-policy
* **Anthropic API (api.anthropic.com)** — only when you enter your own Anthropic API key and select an AI operating mode. Same data as above is sent to Anthropic to generate the answer. Terms: https://www.anthropic.com/legal/consumer-terms — Privacy: https://www.anthropic.com/legal/privacy
* **Your own local AI endpoint** — only when you enter the URL of an OpenAI-compatible server you operate (llama-server, Ollama, LM Studio…). Data stays on that server.
* **Agentyllo model registry (registry.agentyllo.com)** — OFF by default. Only when you press "Sync now" or explicitly enable weekly auto-sync in AI Models, the plugin fetches a small, Ed25519-signed JSON manifest containing model identifiers, prices and prompt-pack versions. It contains data only, never code; the request carries no site data. Terms: https://www.agentyllo.com/terms — Privacy: https://www.agentyllo.com/privacy
* **agentyllo.com** — a "Powered by Agentyllo" footer link exists but is OFF by default; it appears only if you explicitly enable it in Settings → Widget (no data is transmitted).

== Installation ==

1. Upload the plugin and activate it.
2. Agentyllo indexes your content in the background (Knowledge Base page shows progress).
3. The chat widget appears on your site right away in classic mode. Optionally configure an AI tier in Agentyllo → AI Models.
4. Review Privacy & Legal (gate, retention, transparency page) for your jurisdiction.

== Frequently Asked Questions ==

= Does it need an API key? =
No. Classic mode answers from your content without any AI service. Keys are only needed for the paid AI modes.

= Will it invent prices or contact details? =
No. Facts come from your site data (live WooCommerce prices, your contact settings). AI answers are verified: any price, phone or email that is not present in your content is rejected and the classic answer is shown instead.

= Does it work with page caching / CDN? =
Yes. The widget is static markup plus a cacheable configuration request; sessions are cookieless tokens; chat responses are never cached.

= Is it GDPR / EU AI Act compliant? =
It ships the tools: consent evidence, retention, PII redaction, DSAR export/erase, AI disclosure, audit trail and a transparency page generator. Compliance also depends on your configuration and policies.

== Screenshots ==

1. The chat widget answering from your own content — exact facts, real links, no API key required.
2. Dashboard: hosting capability scan and the AI tier your server can run.
3. The backend copilot proposing a change — nothing runs until you confirm.
4. Knowledge base entries, indexed and kept fresh automatically.
5. Statistics: deflection, coverage and the questions your site could not answer yet.
6. AI Models: free local engines or your own OpenAI / Anthropic keys, with a monthly cost cap.
7. Conversations with full transcripts and per-message audit trail.
8. Privacy & Legal: consent, retention, DSAR and the EU AI Act transparency page.

== Changelog ==

= 0.3.0 =
* New brand identity: a friendly octopus mascot and a navy + teal palette across the plugin, the chat widget and the WordPress.org assets. "One intelligence. Many agents."
* The chat widget launcher and header now show the mascot; the admin menu icon and wordmark are refreshed. Colours are WCAG-checked (white on navy, teal accents on dark surfaces).
* No functional changes — your settings, knowledge base and data are untouched. A custom widget primary colour you set is still respected.

= 0.2.1 =
* Change: streaming now runs entirely through the WordPress HTTP API - wp_remote_post() with a write callback attached via the core http_api_curl hook (no direct curl calls in the plugin); verified live with progressive SSE delivery.
* Change: the unused bundled registry signature file (stable.json.sig) is no longer shipped; signature verification only ever applied to remote syncs and is unchanged.

= 0.2.0 =
* Add: Addons page — a catalog of optional extensions (each a separate plugin that requires Agentyllo); the free plugin remains complete, nothing is locked.
* Change: the 90-day statistics range is available to everyone (no feature gating anywhere in the plugin).
* Change: the model-registry sync is strictly opt-in — nothing is fetched unless you press "Sync now" or explicitly enable weekly auto-sync; requests no longer carry the plugin version.
* Change: the "Powered by Agentyllo" widget link is OFF by default and only appears if the site owner enables it in Settings → Widget.
* Change: private uploads no longer write an .htaccess file; protection relies on unguessable random filenames, index placeholders and a 72-hour retention.
* Change: internal prefix renamed from agy_ to agyl_ (options, hooks, tables, REST headers) to meet the 4-character prefix guideline. Breaking for pre-0.2.0 test installs: deactivate and reinstall.
* Dev: translation files are no longer shipped in the distributed zip (translate.wordpress.org will serve them); sources stay in the repository.

= 0.1.4 =
* Add: complete Italian (it_IT) translation - 712 strings covering the admin app (script translations included), the chat widget, the copilot and the transparency page.
* Dev: PHPStan level 6 passes with zero errors (configuration ships in the repository); small robustness fixes from the findings.
* Dev: final Plugin Check pass on the packaged build; development configs are excluded from the distributed zip.

= 0.1.3 =
* Fix: navigation questions ("take me to the contact page", "where can I find X?") now answer with a direct link card to the page whose title matches, instead of quoting an unrelated page or refusing as off-topic. Page-title matches also count as in-scope for the out-of-scope guard.

= 0.1.2 =
* Fix: the admin Copilot drawer now actually closes - a CSS rule kept it permanently visible regardless of the toggle.
* Fix: the floating Copilot button moves out of the drawer's way while it is open, so it can no longer cover the Send button; a close button was also added to the drawer header.
* Improvement: admin pages now adapt to the drawer - content reflows beside it on wide screens, the drawer overlays on narrow screens and goes full-screen on phones, with smooth (reduced-motion-aware) transitions.

= 0.1.1 =
* Fix: hosting capability scan no longer runs its network self-test inline on the request path — the Dashboard rendered an error on single-worker hosts (WordPress Playground, Studio/wp-now); a shallow report renders instantly and the deep scan runs in the background.
* Fix: capability report is now cached on Playground/Studio too (the anti-WP-CLI guard checked PHP_SAPI, which those environments report as "cli").
* Fix: FULLTEXT search is disabled up front on SQLite databases (sqlite-database-integration, Playground, Studio) instead of failing on the first query; keyword search (BM25) is unaffected.

= 0.1.0 =
* First public version: classic agents, automatic knowledge base (posts, pages, CPTs, menus, site settings, WooCommerce, Elementor), accessible streaming widget, GDPR + AI Act tooling, statistics, cloud AI (OpenAI/Anthropic) and local AI (BYO endpoint) with fact-guarded answers, backend copilot with confirmations, TXT/MD/CSV import.
