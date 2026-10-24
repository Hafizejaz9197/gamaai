=== Gama AI ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later

AI-assisted editing for Avada websites.

== Description ==

Phase 1 build. Reads Avada Builder pages and provides a draft, publish and
rollback workflow through REST routes under /wp-json/gama-ai/v1, and applies
typed edit operations to a page's draft. With a Gama AI service URL and
site token set, a request in plain words is sent to the service, which asks
Claude for edit operations; they are written to the page's draft only. There
is no editing interface yet. All routes require a logged-in user with
the matching WordPress capability.

== Changelog ==

= 0.4.0 =
* AI edits: POST /gama-ai/v1/pages/{id}/ai-edit sends the page model, the settings of the element types it may use and the request to the Gama AI service, and writes the returned operations to the draft.
* Service connection: Settings → Gama AI, and GET/POST /gama-ai/v1/settings (administrators). The site token is stored but never shown or returned.

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
