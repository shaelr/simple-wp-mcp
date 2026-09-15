# Simple WP MCP

A minimal, self-hosted WordPress plugin that exposes an MCP (Model Context
Protocol) server over HTTP, so Claude.ai's web-based custom connector can
manage a WordPress site's content directly — posts, pages, custom post types,
taxonomies, custom fields, navigation menus, media, and site settings.

## Design philosophy

Modeled on Home Assistant's `ha-mcp` project: a single long-lived,
unguessable secret baked into the URL path is the *only* credential.
Deliberately no OAuth, no expiring tokens, no IP pinning, no third-party
services. This trades off some enterprise auth rigor for radical simplicity —
appropriate for a single self-hosted site where the operator controls the
secret's distribution.

## Layout

- `simple-wp-mcp/simple-wp-mcp.php` — the entire plugin: activation/uninstall
  hooks, REST route registration + auth, the MCP JSON-RPC dispatcher, and all
  tool implementations.
- `simple-wp-mcp/admin-page.php` — the Settings → Simple WP MCP admin partial
  (connect URL display, regenerate-token button, "act as user" picker).

No build step, no Composer dependency. Install by zipping the `simple-wp-mcp/`
folder and using Plugins → Add New → Upload Plugin.

## Auth model

- On activation, a 32-byte random token is generated (`random_bytes(32)`,
  base64url-encoded, no padding) and stored in `wp_options` with
  `autoload => false`.
- The REST route is `/wp-json/simple-wp-mcp/v1/{secret}` (GET + POST), where
  `{secret}` is a literal path segment matched by regex, not a query param.
- `permission_callback` compares the path segment against the stored token
  with `hash_equals()` (timing-safe). On mismatch it sends a bare
  `404 Not Found` and `exit`s immediately — bypassing WordPress's REST error
  envelope entirely, so the route looks like it doesn't exist to anyone
  without the secret. This mirrors `ha-mcp`'s "wrong path = 404" behavior.
- GET on a valid secret returns a plain HTML confirmation page (sanity check
  in a browser). POST is the actual MCP JSON-RPC endpoint.
- There's no real logged-in WP user in this model, so every POST calls
  `wp_set_current_user()` using the "Act as user" configured in Settings
  (an admin/editor dropdown, defaulting to whoever activated the plugin).
  This makes `wp_insert_post()`, capability checks, and post-author/revision
  history behave normally with a real, meaningful author.

## Transport

Implements MCP's Streamable HTTP transport, stateless variant: one JSON-RPC
2.0 request body in, one JSON-RPC response out (`Content-Type:
application/json`). No SSE streaming. Handles `initialize` (protocol version
negotiation, `serverInfo`, `capabilities.tools = {}`), `tools/list`,
`tools/call`, `ping`/`notifications/initialized`, and returns proper
JSON-RPC error objects (`-32700` parse error, `-32600` invalid request /
batch requests unsupported, `-32601` unknown method, `-32602` invalid
params) for everything else. Tool *execution* errors are reported inside the
result as `{content: [...], isError: true}` per MCP convention, not as
top-level JSON-RPC errors.

## Tools

33 tools total, all implemented directly against core WP functions
(`wp_insert_post`, `wp_update_post`, `get_posts`, `wp_delete_post`,
`wp_trash_post`, `get_terms`, `wp_set_post_terms`, `set_post_thumbnail`,
`wp_update_nav_menu_item`, `media_sideload_image`/`wp_insert_attachment`,
etc.) — never the REST API or HTTP calls back into the site itself.

- **Posts (any post type):** `list_posts` / `get_post` / `create_post` /
  `update_post` / `delete_post` — these work on any registered public post
  type except `page` and `attachment`, which have their own tool families.
  Pass `post_type` (e.g. `"product"` for WooCommerce) to `list_posts`/
  `create_post`; `get_post`/`update_post`/`delete_post` infer the type from
  the ID and reject page/attachment IDs with a message pointing to the right
  tool. `list_post_types` discovers what's registered on the site.
- **Pages:** `list_pages` / `get_page` / `create_page` / `update_page` /
  `delete_page` — same shape as posts, strictly `post_type = page`.
- **Featured images:** `set_featured_image` (pass `media_id: 0` to clear).
  `featured_media`/`featured_media_url` are included in every post/page
  response.
- **Custom fields:** `get_post_meta` / `update_post_meta` /
  `delete_post_meta` — covers ACF and other plugins that store field data as
  post meta. Refuses to touch WordPress-protected meta keys (`is_protected_meta()`);
  use the dedicated tool instead (e.g. `set_featured_image` for `_thumbnail_id`).
- **Taxonomies & terms:** `list_taxonomies`, `list_categories`, `list_tags`,
  `list_terms` (arbitrary taxonomy, e.g. `product_cat`), `set_terms`
  (assigns/creates terms on a post in any taxonomy registered for its type).
- **Navigation menus:** `list_menus`, `get_menu`, `create_menu`,
  `add_menu_item` (post/page link or custom URL), `remove_menu_item`,
  `list_menu_locations`, `assign_menu_location` — the last is what actually
  makes a menu appear on the site (theme locations like `"primary"`);
  without it, a menu exists but shows nowhere.
- **Site settings:** `get_site_settings` / `update_site_settings` — title,
  tagline, homepage assignment (`show_on_front`/`page_on_front`/
  `page_for_posts`), timezone, date/time formats, start of week. Read-only:
  site URL, admin email, active theme, permalinks (see Known non-goals).
- **Media:** `upload_media` (source URL or base64 + filename), `list_media`,
  `get_media`, `delete_media` (always permanent — WordPress core only trashes
  attachments when `MEDIA_TRASH` is defined true, which most sites don't
  set).

Safety defaults worth knowing:
- `create_post`/`create_page` default to `draft` unless `status` is
  explicitly `"publish"`.
- `delete_post`/`delete_page` trash by default; pass `force: true` to
  permanently delete.
- `update_post`/`update_page` with `status: "trash"` (or moving something
  *out* of trash) goes through `wp_trash_post()`/`wp_untrash_post()` rather
  than writing `post_status` directly via `wp_update_post()` — that keeps
  WordPress's own trash/restore bookkeeping intact.

## Known non-goals

Deliberately excluded, even though the auth model would technically allow
them — flag if you want these added:
- **Plugin/theme installation or activation.** Installing a plugin from a
  URL is effectively remote code execution on the server; too dangerous for
  a bearer-token-only auth model with no expiry or audit trail beyond the
  single "act as" user.
- **User account management** (creating/deleting users, changing roles or
  passwords). Same trust-boundary concern — the secret already grants full
  content control via the "act as" user; account management is a separate,
  higher-stakes capability.
- **Site URL, admin email, active theme, and permalink structure changes**
  via `update_site_settings`. These can break the entire site (wrong
  `siteurl`/`home` locks you out of wp-admin; a bad permalink structure
  404s everything) or hijack password-reset email; not worth the blast
  radius for a "finish my website" tool.
- No `outputSchema`/`structuredContent` in tool results (MCP 2025-06-18
  feature) — the JSON-encoded text content is sufficient and avoids
  ambiguity about output shape without declared schemas.
- No capability enforcement beyond what core WP functions do natively —
  once someone has the secret, they have full content control via the "act
  as" user. This is intentional given the `ha-mcp`-style trust model.
- No batch JSON-RPC request support (returns a clear `-32600` error instead
  of attempting to process an array of requests).
