# Edit operations

The AI never writes Avada shortcodes. It reads the page model and sends a
list of typed operations. The plugin checks every operation against the
Avada element registry of the running Avada Builder version, applies them to
the page's draft, and returns the draft's new model. The live page changes
only when the draft is published.

```
GET  /wp-json/gama-ai/v1/pages/{id}/model     page model (the draft's, if a draft is open)
POST /wp-json/gama-ai/v1/pages/{id}/edits     { "operations": [ ... ] }
POST /wp-json/gama-ai/v1/pages/{id}/ai-edit   { "instruction": "Make the heading shorter" }
```

`ai-edit` sends the page model, the settings of the element types the edit
may use (types on the page plus title, text and button) and the request to
the Gama AI service (`POST /api/v1/site/edit-requests`). The service reserves
tokens against the account's budget, asks Claude for operations in the
schema below, records the real usage, and returns `summary`, `operations`
and `usage`. The plugin then applies the operations exactly as `.../edits`
does. If an operation fails the plugin's checks, the route returns 422 with
the AI's answer, because the call was already paid for.

`POST .../edits` creates the draft when there is none and returns
`draft_id`, `preview_url` and `sections` (the new model). On an invalid
operation it returns 400 with the failing operation's index in
`data.operation`, and nothing is written.

## Operations

| op | Fields | What it does |
| --- | --- | --- |
| `replace_text` | `target`, `text` | Replaces an element's text. HTML is allowed and cleaned with `wp_kses_post`. Only elements whose `element_content` is `tinymce`, `textarea` or `textfield` (titles, text blocks, buttons and similar) have text. |
| `set_attribute` | `target`, `name`, `value` | Sets one setting. The name must be one of the element's Avada settings. For choice settings the value must be one of the allowed values (comma separated for multi-choice). Other values are cleaned by setting type (URLs with `esc_url_raw`, plain text with `sanitize_text_field`). |
| `insert_element` | `parent`, `type`, optional `index`, `attrs`, `text` | Adds an element. `parent` is `""` for the page root. Containers go in the root, rows in containers, columns in rows, nested rows in columns, nested columns in nested rows, and every other element in a column. Settings that are left out use Avada's defaults. Without `index` the element is added last. |
| `remove_element` | `target` | Removes an element and everything in it. |
| `move_element` | `target`, `index` | Moves an element to another position in the same parent. This covers `move_section` from the architecture plan. |
| `duplicate_element` | `target` | Copies an element with everything in it and places the copy right after it. `id` and `menu_anchor` settings are cleared in the copy, because HTML ids must be unique. |

`build_section` (a full new section from a design brief) is Phase 4.

## Rules

- `target` and `parent` are the path ids from the page model (`"0.1.2"`).
  They always refer to the page as the model showed it, so removing or
  inserting an element earlier in the list does not shift later ids.
  An element removed earlier in the list cannot be edited.
- `insert_element` may carry a `ref` name (letters, digits, `-`, `_`, starting
  with a letter). Later operations in the same list use it as `parent` or
  `target`, so a new section (container, row, columns, content) is built in one
  list. A new container or row left without children gets a row and a
  full-width column (`type="1_1"`), as Avada Builder adds them.
- `index` counts the parent's elements at the time the operation runs.
- At most 50 operations per request; all of them apply, or none.
- Square brackets in text are written as HTML entities, so text can never
  open a shortcode.
- Edits need the registry for the running Avada Builder version
  (`GET /registry` shows `"current": true`). Without it the route returns
  409.

## Example

```json
{
  "operations": [
    { "op": "replace_text", "target": "0.0.0.0", "text": "Welcome to Sabahat" },
    { "op": "set_attribute", "target": "0.0.0.0", "name": "size", "value": "1" },
    { "op": "insert_element", "parent": "0.0.0", "index": 1, "type": "fusion_text", "text": "<p>Short intro.</p>" }
  ]
}
```


## Layout levels

An element inserted one or two layout levels too high (content into a container or row, a column into a container) goes into the only row or column there, or into a new row and full-width column when there is none. Several rows or columns there is an error.

## Images

`fusion_imageframe` keeps its image in `element_content` (setting type `upload`). `replace_text` on it only accepts an `http(s)` URL. Setting `image_id` to `"<attachment id>|full"` (with `set_attribute` or in `insert_element` attrs) also sets `element_content` to that attachment's URL; an id that is not an image in the media library is an error. The other way round, an image set by URL (`replace_text`, or `insert_element` text) gets the `image_id` of that media library image; Avada shows an image element without an id as bare URL text, so a URL that is not in the media library is an error. AI requests that mention images carry `media`: up to 12 recent media library images (`id`, `url`, `title`).

## Attached pictures

`POST /pages/{id}/ai-edit` takes an optional `image`: a `data:image/(png|jpeg|webp|gif);base64,…` URL of at most 2,000,000 characters (the command bar sends a JPEG of at most 1280 px). It is passed to the service and the AI to explain the request, counted as up to 1,600 input tokens in the estimate, and never stored; the ledger keeps only its size. The AI still answers with the operations above only.

## Tasks

A task is a request kept for later, pinned to the page or to one element (`POST /pages/{id}/tasks` with `instruction` and optional `target`). The pin stores the element's id, type and the first 60 characters of its text, so when ids move it is found again by type and text; if that fits no element or several, Fix refuses instead of guessing. Nothing goes to the AI until `POST /tasks/{id}/fix`, which runs the same path as `ai-edit` into the draft and sets the task to `review`. Publishing the draft moves `review` tasks to `done`; discarding moves them back to `open`. `GET /tasks[?page_id=]`, `POST /tasks/{id}` (`status` open or done) and `DELETE /tasks/{id}` manage the list.
