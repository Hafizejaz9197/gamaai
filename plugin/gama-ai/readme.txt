=== Gama AI ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.8.2
License: GPLv2 or later

AI-assisted editing for Avada websites.

== Description ==

Phase 1 build. Reads Avada Builder pages and provides a draft, publish and
rollback workflow through REST routes under /wp-json/gama-ai/v1, and applies
typed edit operations to a page's draft. With a Gama AI service URL and
site token set, a request in plain words is sent to the service, which asks
Claude for edit operations; they are written to the page's draft only.

Editors use the Gama AI bar on the page itself (the "Gama AI" button, or the
item in the toolbar): describe the change, preview the draft, then publish,
discard, or undo a publish. All routes require a logged-in user with the
matching WordPress capability.

== Changelog ==

= 0.8.2 =
* Image elements set by URL get the media library id of that image (image_id "ID|full"), because Avada shows an image element without an id as its bare URL text. URLs that are not in the media library are refused instead of breaking the page.

= 0.8.1 =
* Image elements: setting image_id ("87|full") now also sets the picture to that media library image, as choosing it in Avada Builder does. An id that is not an image in the media library is refused. Fixes broken images in one step.
* The service reads setting changes that weaker AI models write in other shapes (such as {"attribute": "image_id"}) instead of failing.

= 0.8.0 =
* Pin tasks: "+ Pin" in the Gama AI panel, then click any heading, text, image, button, column or section on the page. "Save as task" keeps the request with its pin; the AI works on it only when you press Fix (or "Fix all open"). Tasks list (This page / All) with Open, Ready to review and Done; publishing the draft marks its tasks Done, discarding reopens them. Numbered pins show on the page.
* While an editor views a page, each Avada element in the HTML carries data-gama-id so a click finds the exact element. Nothing is saved; visitors never get these marks.

= 0.7.0 =
* Attach a picture to a request: "Add image" in the Gama AI panel, or paste or drop a screenshot into the box. The picture is shrunk to 1280 px in the browser, sent to the AI with the request, and not saved anywhere. A request with a picture always goes to the AI.

= 0.6.3 =
* An element put straight into a container (or a column into a container) goes into its row and column, as in Avada Builder. A missing row or full-width column is added; when there are several, the request is refused with a question instead of a guess.

= 0.6.2 =
* AI requests may use any Avada element the request names ("image", "gallery", "video", "divider", "separator" and so on), not only titles, text and buttons. Only that element's needed settings are sent.
* Requests about images include up to 12 recent images from the media library; the AI sets the image URL and its media library id ("ID|full"), so the image stays editable in Avada Builder.
* Image elements only accept http(s) URLs as their image.

= 0.6.1 =
* New sections are never empty: a new container gets a row and a full-width column, as in Avada Builder.
* insert_element takes a `ref`, so the AI can build a container with its row, columns and content in one request. Requests that add something now include the layout element types and column widths.
* "Add a section" (also "with 2 columns", "at the top", "below the second section", "above this") runs without the AI.

= 0.6.0 =
* Fewer AI tokens per edit. Requests send only the settings the instruction names (a container alone has about 180 settings) and, when a section is picked or named, only that container.
* Delete, duplicate, hide and show with a clear target ("delete the second container", "hide this section on mobile") run without the AI and cost no tokens. When the target is unclear, the bar asks which one instead of guessing.
* "Pick a section" in the command bar: click a section on the page to work on only that part.
* "Undo last change" in the draft, step by step (up to 10 changes).
* Rate limits: the bar says how long to wait and offers Try again after the wait. Nothing is sent again automatically.
* New operation duplicate_element. GET /gama-ai/v1/pages/{id}/ai-context shows what a request would send and its size, without sending it. POST /gama-ai/v1/drafts/{id}/undo.

= 0.5.1 =
* The command bar explains a timeout (502/504) instead of showing "Something went wrong".

= 0.5.0 =
* Command bar on pages for users who can edit them: describe a change, see what the AI did and what it cost, then preview, publish, discard or undo. It also shows on the draft preview.
* POST /gama-ai/v1/drafts/{id}/discard throws a draft away. Publish now returns the page URL.

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
