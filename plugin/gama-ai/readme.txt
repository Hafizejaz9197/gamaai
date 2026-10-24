=== Gama AI ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

AI-assisted editing for Avada websites.

== Description ==

Phase 1 build. Reads Avada Builder pages and provides a draft, publish and
rollback workflow through REST routes under /wp-json/gama-ai/v1. There is no
AI and no editing interface yet. All routes require a logged-in user with
the matching WordPress capability.

== Changelog ==

= 0.1.0 =
* Avada detection and version check.
* Avada layout parser with byte-exact round trip and a compact page model.
* Draft copies, publish with snapshot, and rollback.
