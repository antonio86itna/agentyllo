=== Agentyllo Local AI ===
Contributors: agentyllo
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free companion for Agentyllo: consented, checksum-verified installer for llama.cpp engines and open-license GGUF models, plus a supervised local llama-server daemon that powers Agentyllo's free AI modes.

== Description ==

This plugin is distributed by agentyllo.com and is intentionally NOT on WordPress.org: it downloads and executes binaries (only from the Ed25519-signed Agentyllo registry, only after your explicit consent, only after SHA-256 verification). Agentyllo core stays fully compliant with the WordPress.org guidelines and works without this companion (classic agents, BYO endpoint, cloud keys).

What it does:

* Verified catalog: llama.cpp `llama-server` builds for your platform and Apache-2.0/MIT GGUF models (Qwen, Phi…) with size, license and checksum from the signed registry. Gated weights (Llama, Gemma) are never offered.
* Managed daemon: starts `llama-server` on 127.0.0.1, keeps it warm while visitors chat, stops it after an idle TTL, and exposes it to Agentyllo through the `agy_local_endpoint_url` filter.
* Optional `--embeddings` so the same daemon serves dense retrieval vectors.
* Manual mode: point it at a `llama-server` binary and GGUF file you installed yourself.

Requirements: Linux/macOS host, `proc_open` enabled, ~2 GB RAM for 3B-class models (Agentyllo's hosting scan tells you which tier your server supports). Windows hosts: use Agentyllo's BYO endpoint (Ollama/LM Studio) instead.

== Changelog ==

= 0.1.0 =
* First release: supervisor, consented installer, settings tab, Local AI page.
