=== Gama AI ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later

AI-assisted editing for Avada websites.

== Description ==

Phase 1 build. Reads Avada Builder pages and provides a draft, publish and
rollback workflow through REST routes under /wp-json/gama-ai/v1, and applies
typed edit operations to a page's draft. There is no AI and no editing
interface yet. All routes require a logged-in user with
the matching WordPress capability.

== Changelog ==

= 0.3.0 =
* Edit compiler: replace_text, set_attribute, insert_element, remove_element and move_element, checked against Avada's element settings (POST /gama-ai/v1/pages/{id}/edits). Edits go to the page's draft only.
* The page model shows the open draft when there is one.

= 0.2.0 =
* Collects Avada's element settings and defaults as Avada builds them (GET /gama-ai/v1/registry).
* The page model leaves out settings that are at Avada's default.

= 0.1.0 =
* Avada detection and version check.
* Avada layout parser with byte-exact round trip and a compact page model.
* Draft copies, publish with snapshot, and rollback.
