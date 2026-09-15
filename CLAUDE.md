# Simple WP MCP

A minimal, self-hosted WordPress plugin that exposes an MCP (Model Context
Protocol) server over HTTP, so Claude.ai's web-based custom connector can
manage a WordPress site's content directly — posts, pages, categories, tags,
and media.

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

All implemented directly against core WP functions (`wp_insert_post`,
`wp_update_post`, `get_posts`, `wp_delete_post`, `wp_trash_post`,
`get_terms`, `media_sideload_image`/`wp_insert_attachment`) — never the REST
API or HTTP calls back into the site itself.

- `list_posts` / `get_post` / `create_post` / `update_post` / `delete_post`
- `list_pages` / `get_page` / `create_page` / `update_page` / `delete_page`
- `list_categories`, `list_tags`
- `upload_media` (source URL or base64 data + filename)

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

- No `outputSchema`/`structuredContent` in tool results (MCP 2025-06-18
  feature) — the JSON-encoded text content is sufficient and avoids
  ambiguity about output shape without declared schemas.
- No capability enforcement beyond what core WP functions do natively —
  once someone has the secret, they have full site control via the "act as"
  user. This is intentional given the `ha-mcp`-style trust model.
- No batch JSON-RPC request support (returns a clear `-32600` error instead
  of attempting to process an array of requests).
