=== Agentyllo ===
Contributors: agentyllo
Tags: ai, chatbot, assistant, woocommerce, support
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.3
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
* Statistics: conversations, resolution and deflection, top intents, knowledge gaps, latency per tier, tokens and estimated cost.
* Fully translatable; replies in the site language, optionally in the visitor's language with AI.

The core plugin never downloads or executes code from the network. Local model *installation* (binaries and weights) is a separate, consented companion plugin distributed by agentyllo.com — Agentyllo itself only connects to a local engine you already run.

== External Services ==

Agentyllo makes no external request in its default configuration. Every integration below is optional and only active when you configure it:

* **OpenAI API (api.openai.com)** — only when you enter your own OpenAI API key and select an AI operating mode. Visitor messages (optionally with personal data masked), a few relevant knowledge-base excerpts and your assistant instructions are sent to OpenAI to generate the answer or to compute embeddings; requests are sent with storage disabled. Terms: https://openai.com/policies/terms-of-use — Privacy: https://openai.com/policies/privacy-policy
* **Anthropic API (api.anthropic.com)** — only when you enter your own Anthropic API key and select an AI operating mode. Same data as above is sent to Anthropic to generate the answer. Terms: https://www.anthropic.com/legal/consumer-terms — Privacy: https://www.anthropic.com/legal/privacy
* **Your own local AI endpoint** — only when you enter the URL of an OpenAI-compatible server you operate (llama-server, Ollama, LM Studio…). Data stays on that server.
* **Agentyllo model registry (registry.agentyllo.com)** — a weekly fetch (and "Sync now" button) of a small, Ed25519-signed JSON manifest containing model identifiers, prices and prompt-pack versions. It contains data only, never code; the request carries no site data beyond the plugin version. Can be disabled in AI Models. Terms: https://www.agentyllo.com/terms — Privacy: https://www.agentyllo.com/privacy
* **agentyllo.com** — the "Powered by Agentyllo" footer link in the widget points to https://www.agentyllo.com (no data is transmitted; the link can be removed with the Pro plan).

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

1. Chat widget answering from the knowledge base with links and product cards.
2. Dashboard with tier, KB freshness and agent health.
3. Knowledge Base sources and per-item picker.
4. AI Models: providers, local engine, budget and registry.
5. Copilot drawer proposing an action.
6. Statistics and knowledge gaps.
7. Privacy & Legal.
8. Generated AI transparency page.

== Changelog ==

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
