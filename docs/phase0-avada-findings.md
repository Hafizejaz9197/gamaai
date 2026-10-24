# Phase 0: Avada persistence findings

Checked on 2026-10-24 against the test site (Avada theme 7.15.6, Avada Builder
plugin 3.15.6, Avada Core, WordPress 7.1.3, PHP 8.5). Method: read-only review
of the installed Avada Builder source. The site had no pages yet, so nothing
here comes from saving real pages; items marked "open" still need that.

## Confirmed from source

| Question | Finding | Source file (in `wp-content/plugins/fusion-builder/`) |
| --- | --- | --- |
| How are layouts stored? | As nested WordPress shortcodes in `post_content`. Each element registers itself with `add_shortcode()` and is rendered with `do_shortcode()` on its inner content. | `inc/class-fusion-row-element.php` |
| Where do page options live? | One post-meta key, `_fusion`, holding a serialized array. Empty or `default` values are removed from the array rather than stored. | `inc/lib/inc/class-fusion-data-postmeta.php` (`ROOT = '_fusion'`) |
| How does Avada copy a page? | Its own Clone action inserts a new `draft` post with the same `post_content`, taxonomies and every meta key, except `_edit_last`, `_edit_lock` and three view-counter keys. Gama AI drafts use the same approach. | `inc/class-awb-clone-posts.php` |
| Does a programmatic save refresh styles? | Yes, if it goes through the normal save path. Avada hooks `save_post` to delete the `fusion_dynamic_css_<post_id>` transient and mark the page for CSS regeneration. A raw database write would skip this. | `inc/lib/inc/class-fusion-dynamic-css.php` |
| Is there a full cache reset? | `Fusion_Cache::reset_all_caches()` deletes compiled CSS/JS, Avada transients and common third-party page caches. Gama AI does not need it for single-page edits. | `inc/lib/inc/class-fusion-cache.php` |
| How are elements described? | `fusion_builder_map()` registers each element with `name`, `shortcode` and a `params` list. Each param has `type`, `param_name`, `value`/`default`, and optional `dependency` and `responsive` keys. This is the source for the capability registry. | `shortcodes/fusion-text.php` (and one file per element) |
| How to detect the builder version | The constant `FUSION_BUILDER_VERSION` (`3.15.6` here). | `fusion-builder.php` |
| Is there a REST API for editing pages? | No. Avada's REST namespaces on the site are `awb` and `awb/instagram`, and nothing seen edits page layouts. Gama AI uses the standard WordPress post APIs. | Site `/wp-json/` index |

## Confirmed on a real page (2026-10-24)

Test Page (ID 11): one container, one row, two 1/2 columns, a title and an image, saved in Avada Builder.

- Avada Builder writes every attribute of every element, including the defaults and empty values. This two-element page is about 13 KB of markup, and the unfiltered page model is about 11.7 KB of JSON. The page model must leave out default values before it is sent to an AI provider. That needs each element's defaults from the registry.
- Columns carry the `type` attribute twice (`type="1_2"` near the start and again later). WordPress keeps the last value. The parser keeps the raw tag, so the round trip is unaffected.
- No whitespace or newlines between tags.
- The only post meta on a page without page options is `fusion_builder_status` (`active` once the builder is used, empty before). `_fusion` appears only after page options are changed.
- A full cycle ran through the plugin's REST routes:
    1. Create a draft (ID 17), which copied `fusion_builder_status` and left the live page unchanged.
    2. Edit the draft's title text.
    3. Publish. The live page showed the new title and the draft was deleted.
    4. Roll back to snapshot 20. The live page was restored (same title text and same length), and a new snapshot 22 was recorded.

## Open

- Whether a draft preview renders identically to the published page (not yet compared visually).
- Pages with page options set (`_fusion` present), nested rows, global elements and other plugins' shortcodes.
- Where `fusion_builder_map()` keeps the registered map at runtime, so the capability registry can be generated from the live site instead of hand-written. The defining file (`inc/helpers.php`) is over the 64 KB read limit of the inspection tool.

## Design consequences

- Gama AI parses only `fusion_*` and `awb_*` shortcodes. Everything else, including other plugins' shortcodes, is kept as text, byte for byte.
- Drafts are separate posts (content and meta copied), and publishing goes through `wp_update_post()` and `add_post_meta()`, so Avada's `save_post` cache reset runs.
- Rollback snapshots store the content plus only the meta keys a publish changed, so keys other plugins add later are not touched.
- Write routes are disabled on Avada Builder versions not listed as verified in `Compatibility::VERIFIED_BUILDER_VERSIONS`.
