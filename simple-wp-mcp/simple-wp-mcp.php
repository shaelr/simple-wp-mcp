<?php
/**
 * Plugin Name: Simple WP MCP
 * Plugin URI: https://github.com/
 * Description: Minimal, self-hosted MCP (Model Context Protocol) server so Claude.ai's custom connector can manage this site's posts, pages, custom post types, taxonomies, menus, media, and site settings directly. Single long-lived secret in the URL — no OAuth, no expiring tokens, no IP pinning.
 * Version: 1.2.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Simple WP MCP
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-wp-mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SIMPLE_WP_MCP_VERSION', '1.2.0' );
define( 'SIMPLE_WP_MCP_OPTION_TOKEN', 'simple_wp_mcp_token' );
define( 'SIMPLE_WP_MCP_OPTION_USER', 'simple_wp_mcp_act_as_user' );
define( 'SIMPLE_WP_MCP_NAMESPACE', 'simple-wp-mcp/v1' );

/* -----------------------------------------------------------------------
 * Activation / uninstall
 * --------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'simple_wp_mcp_activate' );
function simple_wp_mcp_activate() {
	if ( ! get_option( SIMPLE_WP_MCP_OPTION_TOKEN ) ) {
		simple_wp_mcp_generate_token();
	}
	if ( ! get_option( SIMPLE_WP_MCP_OPTION_USER ) ) {
		$current_user_id = get_current_user_id();
		if ( $current_user_id ) {
			update_option( SIMPLE_WP_MCP_OPTION_USER, $current_user_id, false );
		}
	}
}

register_uninstall_hook( __FILE__, 'simple_wp_mcp_uninstall' );
function simple_wp_mcp_uninstall() {
	delete_option( SIMPLE_WP_MCP_OPTION_TOKEN );
	delete_option( SIMPLE_WP_MCP_OPTION_USER );
}

/**
 * Generate a fresh cryptographically random 32-byte token, base64url-encoded
 * with no padding, and persist it (autoload disabled).
 */
function simple_wp_mcp_generate_token() {
	$bytes = random_bytes( 32 );
	$token = rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	update_option( SIMPLE_WP_MCP_OPTION_TOKEN, $token, false );
	return $token;
}

function simple_wp_mcp_get_connect_url() {
	$token = get_option( SIMPLE_WP_MCP_OPTION_TOKEN );
	return rest_url( SIMPLE_WP_MCP_NAMESPACE . '/' . rawurlencode( (string) $token ) );
}

/* -----------------------------------------------------------------------
 * Admin settings page
 * --------------------------------------------------------------------- */

add_action( 'admin_menu', 'simple_wp_mcp_admin_menu' );
function simple_wp_mcp_admin_menu() {
	add_options_page(
		'Simple WP MCP',
		'Simple WP MCP',
		'manage_options',
		'simple-wp-mcp',
		'simple_wp_mcp_render_settings_page'
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'simple_wp_mcp_plugin_action_links' );
function simple_wp_mcp_plugin_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=simple-wp-mcp' ) ) . '">Settings</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

function simple_wp_mcp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';

	if ( isset( $_POST['simple_wp_mcp_regenerate'] ) && check_admin_referer( 'simple_wp_mcp_regenerate_action', 'simple_wp_mcp_nonce' ) ) {
		simple_wp_mcp_generate_token();
		$notice = '<div class="notice notice-success"><p>Token regenerated. The previous connect URL no longer works.</p></div>';
	}

	if ( isset( $_POST['simple_wp_mcp_save_user'] ) && check_admin_referer( 'simple_wp_mcp_save_user_action', 'simple_wp_mcp_user_nonce' ) ) {
		$user_id = isset( $_POST['simple_wp_mcp_user_id'] ) ? absint( $_POST['simple_wp_mcp_user_id'] ) : 0;
		if ( $user_id && user_can( $user_id, 'edit_posts' ) ) {
			update_option( SIMPLE_WP_MCP_OPTION_USER, $user_id, false );
			$notice = '<div class="notice notice-success"><p>&#8220;Act as user&#8221; updated.</p></div>';
		}
	}

	include plugin_dir_path( __FILE__ ) . 'admin-page.php';
}

/* -----------------------------------------------------------------------
 * REST route registration + auth
 * --------------------------------------------------------------------- */

add_action( 'rest_api_init', 'simple_wp_mcp_register_routes' );
function simple_wp_mcp_register_routes() {
	register_rest_route(
		SIMPLE_WP_MCP_NAMESPACE,
		'/(?P<secret>[^/]+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'simple_wp_mcp_handle_get',
				'permission_callback' => 'simple_wp_mcp_permission_check',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'simple_wp_mcp_handle_post',
				'permission_callback' => 'simple_wp_mcp_permission_check',
			),
		)
	);
}

/**
 * Timing-safe secret check. On mismatch, send a plain 404 and stop — the
 * route should look like it doesn't exist to anyone without the secret.
 */
function simple_wp_mcp_permission_check( $request ) {
	$secret = (string) $request->get_param( 'secret' );
	$token  = (string) get_option( SIMPLE_WP_MCP_OPTION_TOKEN );

	if ( '' === $token || ! hash_equals( $token, $secret ) ) {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo '404 Not Found';
		exit;
	}

	return true;
}

/* -----------------------------------------------------------------------
 * GET: human-readable verification page
 * --------------------------------------------------------------------- */

function simple_wp_mcp_handle_get( $request ) {
	$url = simple_wp_mcp_get_connect_url();

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );

	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Simple WP MCP</title></head><body>';
	echo '<p>Simple WP MCP server is up and running &mdash; paste this full URL into your MCP client:</p>';
	echo '<p><code>' . esc_html( $url ) . '</code></p>';
	echo '</body></html>';
	exit;
}

/* -----------------------------------------------------------------------
 * POST: MCP JSON-RPC 2.0 endpoint (stateless Streamable HTTP)
 * --------------------------------------------------------------------- */

function simple_wp_mcp_handle_post( WP_REST_Request $request ) {
	$act_as_user_id = (int) get_option( SIMPLE_WP_MCP_OPTION_USER );
	if ( $act_as_user_id ) {
		wp_set_current_user( $act_as_user_id );
	}

	$data = json_decode( $request->get_body(), true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
		return new WP_REST_Response( simple_wp_mcp_error_response( null, -32700, 'Parse error' ), 200 );
	}

	if ( simple_wp_mcp_is_json_list( $data ) ) {
		return new WP_REST_Response( simple_wp_mcp_error_response( null, -32600, 'Batch requests are not supported' ), 200 );
	}

	return new WP_REST_Response( simple_wp_mcp_dispatch_rpc( $data ), 200 );
}

/**
 * True if a json_decode(..., true) result came from a JSON array ("[...]")
 * rather than a JSON object ("{...}").
 */
function simple_wp_mcp_is_json_list( $data ) {
	if ( array() === $data ) {
		return false;
	}
	return array_keys( $data ) === range( 0, count( $data ) - 1 );
}

function simple_wp_mcp_dispatch_rpc( $data ) {
	$id     = array_key_exists( 'id', $data ) ? $data['id'] : null;
	$method = isset( $data['method'] ) && is_string( $data['method'] ) ? $data['method'] : '';
	$params = isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array();

	if ( ! isset( $data['jsonrpc'] ) || '2.0' !== $data['jsonrpc'] || '' === $method ) {
		return simple_wp_mcp_error_response( $id, -32600, 'Invalid Request' );
	}

	switch ( $method ) {
		case 'initialize':
			$supported       = array( '2025-06-18', '2025-03-26', '2024-11-05' );
			$client_version  = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : null;
			$protocol_version = in_array( $client_version, $supported, true ) ? $client_version : '2025-06-18';

			return simple_wp_mcp_result_response(
				$id,
				array(
					'protocolVersion' => $protocol_version,
					'serverInfo'      => array(
						'name'    => 'simple-wp-mcp',
						'version' => SIMPLE_WP_MCP_VERSION,
					),
					'capabilities'    => array(
						'tools' => new stdClass(),
					),
				)
			);

		case 'notifications/initialized':
		case 'ping':
			return simple_wp_mcp_result_response( $id, new stdClass() );

		case 'tools/list':
			return simple_wp_mcp_result_response( $id, array( 'tools' => simple_wp_mcp_get_tool_definitions() ) );

		case 'tools/call':
			return simple_wp_mcp_handle_tool_call( $id, $params );

		default:
			return simple_wp_mcp_error_response( $id, -32601, 'Method not found: ' . $method );
	}
}

function simple_wp_mcp_result_response( $id, $result ) {
	return array(
		'jsonrpc' => '2.0',
		'id'      => $id,
		'result'  => $result,
	);
}

function simple_wp_mcp_error_response( $id, $code, $message ) {
	return array(
		'jsonrpc' => '2.0',
		'id'      => $id,
		'error'   => array(
			'code'    => $code,
			'message' => $message,
		),
	);
}

/* -----------------------------------------------------------------------
 * Tool definitions
 * --------------------------------------------------------------------- */

function simple_wp_mcp_get_tool_definitions() {
	$id_schema     = array(
		'type'        => 'integer',
		'description' => 'The numeric post ID.',
	);
	$status_filter = array(
		'type'        => 'string',
		'description' => 'Status to filter by (publish, draft, pending, private, future, trash, any). Defaults to all non-trashed statuses.',
	);
	$search_schema = array(
		'type'        => 'string',
		'description' => 'Search term matched against title and content.',
	);
	$per_page_schema = array(
		'type'        => 'integer',
		'description' => 'Number of results to return (default 20, max 100).',
	);
	$title_schema = array(
		'type'        => 'string',
		'description' => 'Title.',
	);
	$content_schema = array(
		'type'        => 'string',
		'description' => 'Content (HTML allowed).',
	);
	$create_status_schema = array(
		'type'        => 'string',
		'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'description' => 'Defaults to draft unless explicitly set to "publish".',
	);
	$update_status_schema = array(
		'type'        => 'string',
		'enum'        => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
		'description' => 'New status.',
	);
	$force_schema = array(
		'type'        => 'boolean',
		'description' => 'If true, permanently delete instead of moving to trash. Defaults to false.',
	);
	$post_type_schema = array(
		'type'        => 'string',
		'description' => 'Post type slug (e.g. "product" for a WooCommerce product). Defaults to "post". Use list_post_types to see what\'s registered on this site. Cannot be "page" or "attachment" — use the dedicated page/media tools for those.',
	);

	return array(
		array(
			'name'        => 'list_posts',
			'description' => 'List posts, optionally filtered by status, search term, or post_type (for custom post types).',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'status'    => $status_filter,
					'search'    => $search_schema,
					'per_page'  => $per_page_schema,
					'post_type' => $post_type_schema,
				),
			),
		),
		array(
			'name'        => 'get_post',
			'description' => 'Get a single post by ID, including full content. Works for any post type except page/attachment.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => $id_schema ),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'create_post',
			'description' => 'Create a new post. Defaults to draft status unless status is explicitly set to "publish". Pass post_type to create a custom post type entry (e.g. a WooCommerce product) instead of a regular post.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'title'     => $title_schema,
					'content'   => $content_schema,
					'status'    => $create_status_schema,
					'post_type' => $post_type_schema,
				),
				'required'   => array( 'title' ),
			),
		),
		array(
			'name'        => 'update_post',
			'description' => 'Update an existing post\'s title, content, and/or status. Works for any post type except page/attachment.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'      => $id_schema,
					'title'   => $title_schema,
					'content' => $content_schema,
					'status'  => $update_status_schema,
				),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'delete_post',
			'description' => 'Delete a post (any post type except page/attachment). Moves to trash by default; pass force=true to permanently delete.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'    => $id_schema,
					'force' => $force_schema,
				),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'list_pages',
			'description' => 'List pages, optionally filtered by status or search term.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'status'   => $status_filter,
					'search'   => $search_schema,
					'per_page' => $per_page_schema,
				),
			),
		),
		array(
			'name'        => 'get_page',
			'description' => 'Get a single page by ID, including full content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => $id_schema ),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'create_page',
			'description' => 'Create a new page. Defaults to draft status unless status is explicitly set to "publish".',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'title'   => $title_schema,
					'content' => $content_schema,
					'status'  => $create_status_schema,
				),
				'required'   => array( 'title' ),
			),
		),
		array(
			'name'        => 'update_page',
			'description' => 'Update an existing page\'s title, content, and/or status.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'      => $id_schema,
					'title'   => $title_schema,
					'content' => $content_schema,
					'status'  => $update_status_schema,
				),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'delete_page',
			'description' => 'Delete a page. Moves to trash by default; pass force=true to permanently delete.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'    => $id_schema,
					'force' => $force_schema,
				),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'list_categories',
			'description' => 'List all post categories.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		),
		array(
			'name'        => 'list_tags',
			'description' => 'List all post tags.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		),
		array(
			'name'        => 'upload_media',
			'description' => 'Upload a media file from a source URL or base64-encoded data, and insert it as an attachment.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'source_url' => array(
						'type'        => 'string',
						'description' => 'A publicly reachable URL to sideload the file from. Provide either this or "data".',
					),
					'data'       => array(
						'type'        => 'string',
						'description' => 'Base64-encoded file contents. Provide either this or "source_url".',
					),
					'filename'   => array(
						'type'        => 'string',
						'description' => 'Filename to use (required when using "data").',
					),
				),
			),
		),
		array(
			'name'        => 'list_media',
			'description' => 'List media library items, optionally filtered by MIME type or search term.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'mime_type' => array(
						'type'        => 'string',
						'description' => 'Filter by MIME type or prefix, e.g. "image" or "image/jpeg".',
					),
					'search'    => $search_schema,
					'per_page'  => $per_page_schema,
				),
			),
		),
		array(
			'name'        => 'get_media',
			'description' => 'Get a single media library item by ID.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => $id_schema ),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'delete_media',
			'description' => 'Permanently delete a media library item and its file.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => $id_schema ),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'list_plugins',
			'description' => 'List installed plugins with version and active/inactive status. Requires the "act as" user to be an administrator.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		),
		array(
			'name'        => 'activate_plugin',
			'description' => 'Activate an already-installed plugin. Does not install new plugins. Requires the "act as" user to be an administrator.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'file' => array(
						'type'        => 'string',
						'description' => 'Plugin file path relative to the plugins directory, e.g. "akismet/akismet.php" — from list_plugins.',
					),
				),
				'required'   => array( 'file' ),
			),
		),
		array(
			'name'        => 'deactivate_plugin',
			'description' => 'Deactivate an installed plugin. Requires the "act as" user to be an administrator.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'file' => array(
						'type'        => 'string',
						'description' => 'Plugin file path relative to the plugins directory, e.g. "akismet/akismet.php" — from list_plugins.',
					),
				),
				'required'   => array( 'file' ),
			),
		),
		array(
			'name'        => 'set_featured_image',
			'description' => 'Set (or clear, with media_id 0) the featured image of a post or page.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => $id_schema,
					'media_id' => array(
						'type'        => 'integer',
						'description' => 'Media attachment ID to use as the featured image. Pass 0 to remove the featured image.',
					),
				),
				'required'   => array( 'id', 'media_id' ),
			),
		),
		array(
			'name'        => 'get_post_meta',
			'description' => 'Get a post/page\'s custom fields (post meta). Omit key to get all non-internal meta, e.g. ACF field values.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'  => $id_schema,
					'key' => array(
						'type'        => 'string',
						'description' => 'Specific meta key to fetch. Omit to fetch all custom fields.',
					),
				),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'update_post_meta',
			'description' => 'Set a custom field (post meta / ACF field) on a post or page.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'    => $id_schema,
					'key'   => array(
						'type'        => 'string',
						'description' => 'Meta key.',
					),
					'value' => array(
						'description' => 'Meta value. Any JSON type — strings, numbers, booleans, arrays, or objects.',
					),
				),
				'required'   => array( 'id', 'key', 'value' ),
			),
		),
		array(
			'name'        => 'delete_post_meta',
			'description' => 'Delete a custom field (post meta) from a post or page.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'  => $id_schema,
					'key' => array(
						'type'        => 'string',
						'description' => 'Meta key to delete.',
					),
				),
				'required'   => array( 'id', 'key' ),
			),
		),
		array(
			'name'        => 'list_post_types',
			'description' => 'List public post types registered on this site (post, page, and any custom post types like WooCommerce products).',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		),
		array(
			'name'        => 'list_taxonomies',
			'description' => 'List public taxonomies registered on this site (category, post_tag, and any custom taxonomies).',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		),
		array(
			'name'        => 'list_terms',
			'description' => 'List all terms in an arbitrary taxonomy (use list_categories/list_tags for the common built-in ones).',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => 'Taxonomy slug, e.g. "product_cat". Use list_taxonomies to see available values.',
					),
				),
				'required'   => array( 'taxonomy' ),
			),
		),
		array(
			'name'        => 'set_terms',
			'description' => 'Assign categories, tags, or any other taxonomy\'s terms to a post. Terms not already existing (by name) are created. Replaces the post\'s current terms in that taxonomy.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'id'       => $id_schema,
					'taxonomy' => array(
						'type'        => 'string',
						'description' => 'Taxonomy slug, e.g. "category", "post_tag", or "product_cat".',
					),
					'terms'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => array( 'string', 'integer' ) ),
						'description' => 'Term names or IDs to assign.',
					),
				),
				'required'   => array( 'id', 'taxonomy', 'terms' ),
			),
		),
		array(
			'name'        => 'list_menus',
			'description' => 'List navigation menus.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		),
		array(
			'name'        => 'get_menu',
			'description' => 'Get a navigation menu\'s items.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => $id_schema ),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'create_menu',
			'description' => 'Create a new, empty navigation menu.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'Menu name.',
					),
				),
				'required'   => array( 'name' ),
			),
		),
		array(
			'name'        => 'add_menu_item',
			'description' => 'Add an item to a navigation menu, linking either to a post/page (post_id) or a custom URL (url + title).',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'menu_id'   => array(
						'type'        => 'integer',
						'description' => 'Menu ID from list_menus/create_menu.',
					),
					'post_id'   => array(
						'type'        => 'integer',
						'description' => 'ID of a post/page/CPT entry to link to. Provide this or url.',
					),
					'url'       => array(
						'type'        => 'string',
						'description' => 'Custom URL to link to (requires title). Provide this or post_id.',
					),
					'title'     => array(
						'type'        => 'string',
						'description' => 'Menu item label. Required for a custom URL; optional (defaults to the target\'s title) for post_id.',
					),
					'parent_id' => array(
						'type'        => 'integer',
						'description' => 'ID of another menu item to nest this one under, for a dropdown submenu.',
					),
					'position'  => array(
						'type'        => 'integer',
						'description' => 'Order position within the menu.',
					),
				),
				'required'   => array( 'menu_id' ),
			),
		),
		array(
			'name'        => 'remove_menu_item',
			'description' => 'Remove a single item from a navigation menu.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'item_id' => array(
						'type'        => 'integer',
						'description' => 'Menu item ID (from get_menu), not the menu ID.',
					),
				),
				'required'   => array( 'item_id' ),
			),
		),
		array(
			'name'        => 'list_menu_locations',
			'description' => 'List the navigation menu locations this theme supports (e.g. "primary", "footer") and which menu (if any) is assigned to each.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		),
		array(
			'name'        => 'assign_menu_location',
			'description' => 'Assign a menu to a theme location so it actually appears on the site (e.g. in the header). Omit or pass menu_id 0 to unassign.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'location' => array(
						'type'        => 'string',
						'description' => 'Theme location slug from list_menu_locations.',
					),
					'menu_id'  => array(
						'type'        => 'integer',
						'description' => 'Menu ID to assign. Pass 0 to unassign the location.',
					),
				),
				'required'   => array( 'location' ),
			),
		),
		array(
			'name'        => 'get_site_settings',
			'description' => 'Get site-wide settings: title, tagline, homepage assignment, timezone, date/time formats, active theme, etc.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
		),
		array(
			'name'        => 'update_site_settings',
			'description' => 'Update site-wide settings. Only recognized fields are applied; unrecognized ones are ignored. Does not support changing the site URL, admin email, active theme, plugins, or permalink structure.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'title'           => array(
						'type'        => 'string',
						'description' => 'Site title.',
					),
					'tagline'         => array(
						'type'        => 'string',
						'description' => 'Site tagline.',
					),
					'timezone_string' => array(
						'type'        => 'string',
						'description' => 'IANA timezone, e.g. "America/New_York".',
					),
					'date_format'     => array(
						'type'        => 'string',
						'description' => 'PHP date() format string.',
					),
					'time_format'     => array(
						'type'        => 'string',
						'description' => 'PHP date() format string.',
					),
					'start_of_week'   => array(
						'type'        => 'integer',
						'description' => '0 (Sunday) through 6 (Saturday).',
					),
					'show_on_front'   => array(
						'type'        => 'string',
						'enum'        => array( 'posts', 'page' ),
						'description' => 'Whether the homepage shows the latest posts or a static page.',
					),
					'page_on_front'   => array(
						'type'        => 'integer',
						'description' => 'Page ID to use as the static homepage (requires show_on_front="page").',
					),
					'page_for_posts'  => array(
						'type'        => 'integer',
						'description' => 'Page ID to use as the posts listing page (requires show_on_front="page").',
					),
				),
			),
		),
	);
}

/* -----------------------------------------------------------------------
 * Tool call dispatch
 * --------------------------------------------------------------------- */

function simple_wp_mcp_handle_tool_call( $id, $params ) {
	$name = isset( $params['name'] ) ? $params['name'] : '';
	$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

	if ( '' === $name ) {
		return simple_wp_mcp_error_response( $id, -32602, 'Missing required param: name' );
	}

	$tools = array(
		'list_posts'           => 'simple_wp_mcp_tool_list_posts',
		'get_post'             => 'simple_wp_mcp_tool_get_post',
		'create_post'          => 'simple_wp_mcp_tool_create_post',
		'update_post'          => 'simple_wp_mcp_tool_update_post',
		'delete_post'          => 'simple_wp_mcp_tool_delete_post',
		'list_pages'           => 'simple_wp_mcp_tool_list_pages',
		'get_page'             => 'simple_wp_mcp_tool_get_page',
		'create_page'          => 'simple_wp_mcp_tool_create_page',
		'update_page'          => 'simple_wp_mcp_tool_update_page',
		'delete_page'          => 'simple_wp_mcp_tool_delete_page',
		'set_featured_image'   => 'simple_wp_mcp_tool_set_featured_image',
		'get_post_meta'        => 'simple_wp_mcp_tool_get_post_meta',
		'update_post_meta'     => 'simple_wp_mcp_tool_update_post_meta',
		'delete_post_meta'     => 'simple_wp_mcp_tool_delete_post_meta',
		'list_post_types'      => 'simple_wp_mcp_tool_list_post_types',
		'list_taxonomies'      => 'simple_wp_mcp_tool_list_taxonomies',
		'list_categories'      => 'simple_wp_mcp_tool_list_categories',
		'list_tags'            => 'simple_wp_mcp_tool_list_tags',
		'list_terms'           => 'simple_wp_mcp_tool_list_terms',
		'set_terms'            => 'simple_wp_mcp_tool_set_terms',
		'list_menus'           => 'simple_wp_mcp_tool_list_menus',
		'get_menu'             => 'simple_wp_mcp_tool_get_menu',
		'create_menu'          => 'simple_wp_mcp_tool_create_menu',
		'add_menu_item'        => 'simple_wp_mcp_tool_add_menu_item',
		'remove_menu_item'     => 'simple_wp_mcp_tool_remove_menu_item',
		'list_menu_locations'  => 'simple_wp_mcp_tool_list_menu_locations',
		'assign_menu_location' => 'simple_wp_mcp_tool_assign_menu_location',
		'get_site_settings'    => 'simple_wp_mcp_tool_get_site_settings',
		'update_site_settings' => 'simple_wp_mcp_tool_update_site_settings',
		'list_media'           => 'simple_wp_mcp_tool_list_media',
		'get_media'            => 'simple_wp_mcp_tool_get_media',
		'delete_media'         => 'simple_wp_mcp_tool_delete_media',
		'list_plugins'         => 'simple_wp_mcp_tool_list_plugins',
		'activate_plugin'      => 'simple_wp_mcp_tool_activate_plugin',
		'deactivate_plugin'    => 'simple_wp_mcp_tool_deactivate_plugin',
		'upload_media'         => 'simple_wp_mcp_tool_upload_media',
	);

	if ( ! isset( $tools[ $name ] ) ) {
		return simple_wp_mcp_result_response(
			$id,
			array(
				'content' => array( array( 'type' => 'text', 'text' => 'Unknown tool: ' . $name ) ),
				'isError' => true,
			)
		);
	}

	try {
		$result = call_user_func( $tools[ $name ], $args );
		return simple_wp_mcp_result_response(
			$id,
			array(
				'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( $result ) ) ),
				'isError' => false,
			)
		);
	} catch ( Exception $e ) {
		return simple_wp_mcp_result_response(
			$id,
			array(
				'content' => array( array( 'type' => 'text', 'text' => $e->getMessage() ) ),
				'isError' => true,
			)
		);
	}
}

/* -----------------------------------------------------------------------
 * Tool implementations
 * --------------------------------------------------------------------- */

/**
 * Post types with dedicated tools (page) or that aren't real content
 * (attachment, nav menu items, revisions, block-editor internals). The
 * generic post_* tools refuse to touch these — use the matching dedicated
 * tool instead.
 */
function simple_wp_mcp_reserved_post_types() {
	return array(
		'page',
		'attachment',
		'nav_menu_item',
		'revision',
		'customize_changeset',
		'custom_css',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
	);
}

/**
 * Validate a post_type argument for the generic post_* tools: must be a
 * registered, public post type, and not one with its own dedicated tools.
 */
function simple_wp_mcp_validate_generic_post_type( $post_type ) {
	$post_type = sanitize_key( $post_type );

	if ( in_array( $post_type, simple_wp_mcp_reserved_post_types(), true ) ) {
		throw new Exception( 'post_type "' . $post_type . '" has its own dedicated tools — use those instead.' );
	}

	$public_types = get_post_types( array( 'public' => true ), 'names' );
	if ( ! in_array( $post_type, $public_types, true ) ) {
		throw new Exception( 'Unknown or non-public post_type: ' . $post_type . '. Use list_post_types to see available types.' );
	}

	return $post_type;
}

function simple_wp_mcp_format_post( $post ) {
	$thumbnail_id = get_post_thumbnail_id( $post );

	return array(
		'id'                 => $post->ID,
		'title'              => $post->post_title,
		'status'             => $post->post_status,
		'type'               => $post->post_type,
		'link'               => get_permalink( $post ),
		'date'               => $post->post_date,
		'modified'           => $post->post_modified,
		'excerpt'            => wp_strip_all_tags( get_the_excerpt( $post ) ),
		'author'             => (int) $post->post_author,
		'featured_media'     => $thumbnail_id ? (int) $thumbnail_id : null,
		'featured_media_url' => $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : null,
	);
}

function simple_wp_mcp_format_post_full( $post ) {
	$data              = simple_wp_mcp_format_post( $post );
	$data['content']   = $post->post_content;
	return $data;
}

function simple_wp_mcp_list_content( $args, $post_type ) {
	$status   = ( ! empty( $args['status'] ) ) ? $args['status'] : array( 'publish', 'draft', 'pending', 'private', 'future' );
	$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;

	$query_args = array(
		'post_type'      => $post_type,
		'post_status'    => $status,
		'posts_per_page' => $per_page,
	);

	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = sanitize_text_field( $args['search'] );
	}

	return array_map( 'simple_wp_mcp_format_post', get_posts( $query_args ) );
}

/**
 * Fetch a single post. Pass a specific $post_type ('page') for the strict,
 * dedicated tools, or null to accept any post type except the reserved
 * ones (used by the generic post_* tools, which work across custom post
 * types since an ID alone doesn't tell you the type in advance).
 */
function simple_wp_mcp_get_content( $args, $post_type ) {
	$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
	$post = $id ? get_post( $id ) : null;

	if ( ! $post ) {
		throw new Exception( 'Post not found: ' . $id );
	}

	simple_wp_mcp_check_content_type( $post, $post_type );

	return simple_wp_mcp_format_post_full( $post );
}

function simple_wp_mcp_check_content_type( $post, $post_type ) {
	if ( null !== $post_type ) {
		if ( $post->post_type !== $post_type ) {
			throw new Exception( ucfirst( $post_type ) . ' not found: ' . $post->ID );
		}
		return;
	}

	if ( in_array( $post->post_type, simple_wp_mcp_reserved_post_types(), true ) ) {
		throw new Exception( 'ID ' . $post->ID . ' is a ' . $post->post_type . ', not a post — use the matching dedicated tool instead (get_page/update_page/delete_page for pages, the media tools for attachments).' );
	}
}

function simple_wp_mcp_create_content( $args, $post_type ) {
	if ( empty( $args['title'] ) ) {
		throw new Exception( 'title is required' );
	}

	$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
	$status            = ( isset( $args['status'] ) && in_array( $args['status'], $allowed_statuses, true ) ) ? $args['status'] : 'draft';

	$postarr = array(
		'post_type'    => $post_type,
		'post_title'   => sanitize_text_field( $args['title'] ),
		'post_content' => isset( $args['content'] ) ? wp_kses_post( $args['content'] ) : '',
		'post_status'  => $status,
	);

	$id = wp_insert_post( $postarr, true );

	if ( is_wp_error( $id ) ) {
		throw new Exception( $id->get_error_message() );
	}

	return simple_wp_mcp_format_post_full( get_post( $id ) );
}

function simple_wp_mcp_update_content( $args, $post_type ) {
	$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
	$post = $id ? get_post( $id ) : null;

	if ( ! $post ) {
		throw new Exception( 'Post not found: ' . $id );
	}

	simple_wp_mcp_check_content_type( $post, $post_type );

	$new_status = null;
	if ( isset( $args['status'] ) ) {
		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		if ( in_array( $args['status'], $allowed_statuses, true ) ) {
			$new_status = $args['status'];
		}
	}

	// Trashing/untrashing goes through the dedicated APIs so WordPress
	// records (and restores) the pre-trash status correctly, the same way
	// delete_post/delete_page already do via wp_delete_post().
	if ( 'trash' === $new_status && 'trash' !== $post->post_status ) {
		wp_trash_post( $id );
	} elseif ( null !== $new_status && 'trash' !== $new_status && 'trash' === $post->post_status ) {
		wp_untrash_post( $id );
	}

	$postarr = array( 'ID' => $id );

	if ( isset( $args['title'] ) ) {
		$postarr['post_title'] = sanitize_text_field( $args['title'] );
	}
	if ( isset( $args['content'] ) ) {
		$postarr['post_content'] = wp_kses_post( $args['content'] );
	}
	if ( null !== $new_status && 'trash' !== $new_status ) {
		$postarr['post_status'] = $new_status;
	}

	if ( count( $postarr ) > 1 ) {
		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			throw new Exception( $result->get_error_message() );
		}
	}

	return simple_wp_mcp_format_post_full( get_post( $id ) );
}

function simple_wp_mcp_delete_content( $args, $post_type ) {
	$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
	$post = $id ? get_post( $id ) : null;

	if ( ! $post ) {
		throw new Exception( 'Post not found: ' . $id );
	}

	simple_wp_mcp_check_content_type( $post, $post_type );

	$force  = ! empty( $args['force'] );
	$result = wp_delete_post( $id, $force );

	if ( ! $result ) {
		throw new Exception( 'Failed to delete ' . $post->post_type . ': ' . $id );
	}

	return array(
		'id'      => $id,
		'deleted' => true,
		'force'   => $force,
	);
}

function simple_wp_mcp_tool_list_posts( $args ) {
	$post_type = ! empty( $args['post_type'] ) ? simple_wp_mcp_validate_generic_post_type( $args['post_type'] ) : 'post';
	return simple_wp_mcp_list_content( $args, $post_type );
}
function simple_wp_mcp_tool_get_post( $args ) {
	return simple_wp_mcp_get_content( $args, null );
}
function simple_wp_mcp_tool_create_post( $args ) {
	$post_type = ! empty( $args['post_type'] ) ? simple_wp_mcp_validate_generic_post_type( $args['post_type'] ) : 'post';
	return simple_wp_mcp_create_content( $args, $post_type );
}
function simple_wp_mcp_tool_update_post( $args ) {
	return simple_wp_mcp_update_content( $args, null );
}
function simple_wp_mcp_tool_delete_post( $args ) {
	return simple_wp_mcp_delete_content( $args, null );
}

function simple_wp_mcp_tool_list_pages( $args ) {
	return simple_wp_mcp_list_content( $args, 'page' );
}
function simple_wp_mcp_tool_get_page( $args ) {
	return simple_wp_mcp_get_content( $args, 'page' );
}
function simple_wp_mcp_tool_create_page( $args ) {
	return simple_wp_mcp_create_content( $args, 'page' );
}
function simple_wp_mcp_tool_update_page( $args ) {
	return simple_wp_mcp_update_content( $args, 'page' );
}
function simple_wp_mcp_tool_delete_page( $args ) {
	return simple_wp_mcp_delete_content( $args, 'page' );
}

function simple_wp_mcp_format_term( $term ) {
	return array(
		'id'    => $term->term_id,
		'name'  => $term->name,
		'slug'  => $term->slug,
		'count' => $term->count,
	);
}

function simple_wp_mcp_tool_list_categories( $args ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) ) {
		throw new Exception( $terms->get_error_message() );
	}
	return array_map( 'simple_wp_mcp_format_term', $terms );
}

function simple_wp_mcp_tool_list_tags( $args ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'post_tag',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) ) {
		throw new Exception( $terms->get_error_message() );
	}
	return array_map( 'simple_wp_mcp_format_term', $terms );
}

function simple_wp_mcp_tool_upload_media( $args ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$filename = ! empty( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : '';

	if ( ! empty( $args['source_url'] ) ) {
		$url = esc_url_raw( $args['source_url'] );
		if ( ! $url ) {
			throw new Exception( 'Invalid source_url' );
		}

		$attachment_id = media_sideload_image( $url, 0, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			throw new Exception( $attachment_id->get_error_message() );
		}
	} elseif ( ! empty( $args['data'] ) ) {
		if ( '' === $filename ) {
			throw new Exception( 'filename is required when uploading base64 data' );
		}

		$decoded = base64_decode( $args['data'], true );
		if ( false === $decoded ) {
			throw new Exception( 'Invalid base64 data' );
		}

		$upload = wp_upload_bits( $filename, null, $decoded );
		if ( ! empty( $upload['error'] ) ) {
			throw new Exception( $upload['error'] );
		}

		$filetype   = wp_check_filetype( $upload['file'], null );
		$attachment = array(
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
			'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			throw new Exception( $attachment_id->get_error_message() );
		}

		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );
	} else {
		throw new Exception( 'Either source_url or data (base64) must be provided' );
	}

	return array(
		'id'  => $attachment_id,
		'url' => wp_get_attachment_url( $attachment_id ),
	);
}

/* -----------------------------------------------------------------------
 * Featured images
 * --------------------------------------------------------------------- */

function simple_wp_mcp_tool_set_featured_image( $args ) {
	$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
	$post = $id ? get_post( $id ) : null;

	if ( ! $post ) {
		throw new Exception( 'Post not found: ' . $id );
	}

	$media_id = isset( $args['media_id'] ) ? (int) $args['media_id'] : 0;

	if ( $media_id > 0 ) {
		$attachment = get_post( $media_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			throw new Exception( 'Media not found: ' . $media_id );
		}
		if ( ! set_post_thumbnail( $id, $media_id ) ) {
			throw new Exception( 'Failed to set featured image' );
		}
	} else {
		delete_post_thumbnail( $id );
	}

	return simple_wp_mcp_format_post_full( get_post( $id ) );
}

/* -----------------------------------------------------------------------
 * Custom fields (post meta)
 * --------------------------------------------------------------------- */

function simple_wp_mcp_tool_get_post_meta( $args ) {
	$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
	if ( ! $id || ! get_post( $id ) ) {
		throw new Exception( 'Post not found: ' . $id );
	}

	$key = ! empty( $args['key'] ) ? sanitize_key( $args['key'] ) : '';

	if ( '' !== $key ) {
		return array(
			'id'    => $id,
			'key'   => $key,
			'value' => get_post_meta( $id, $key, true ),
		);
	}

	$meta   = get_post_meta( $id );
	$result = array();
	foreach ( $meta as $meta_key => $values ) {
		if ( is_protected_meta( $meta_key, 'post' ) ) {
			continue;
		}
		$result[ $meta_key ] = 1 === count( $values ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
	}

	return array(
		'id'   => $id,
		'meta' => $result,
	);
}

function simple_wp_mcp_tool_update_post_meta( $args ) {
	$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
	if ( ! $id || ! get_post( $id ) ) {
		throw new Exception( 'Post not found: ' . $id );
	}
	if ( empty( $args['key'] ) ) {
		throw new Exception( 'key is required' );
	}
	if ( ! array_key_exists( 'value', $args ) ) {
		throw new Exception( 'value is required' );
	}

	$key = sanitize_key( $args['key'] );
	if ( is_protected_meta( $key, 'post' ) ) {
		throw new Exception( 'Cannot modify protected meta key "' . $key . '" — use a dedicated tool instead (e.g. set_featured_image).' );
	}

	update_post_meta( $id, $key, $args['value'] );

	return array(
		'id'    => $id,
		'key'   => $key,
		'value' => get_post_meta( $id, $key, true ),
	);
}

function simple_wp_mcp_tool_delete_post_meta( $args ) {
	$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
	if ( ! $id || ! get_post( $id ) ) {
		throw new Exception( 'Post not found: ' . $id );
	}
	if ( empty( $args['key'] ) ) {
		throw new Exception( 'key is required' );
	}

	$key = sanitize_key( $args['key'] );
	if ( is_protected_meta( $key, 'post' ) ) {
		throw new Exception( 'Cannot modify protected meta key: ' . $key );
	}

	delete_post_meta( $id, $key );

	return array(
		'id'      => $id,
		'key'     => $key,
		'deleted' => true,
	);
}

/* -----------------------------------------------------------------------
 * Post types, taxonomies, and terms (categories, tags, and custom)
 * --------------------------------------------------------------------- */

function simple_wp_mcp_tool_list_post_types( $args ) {
	$types  = get_post_types( array( 'public' => true ), 'objects' );
	$result = array();

	foreach ( $types as $type ) {
		$result[] = array(
			'name'         => $type->name,
			'label'        => $type->label,
			'hierarchical' => (bool) $type->hierarchical,
		);
	}

	return $result;
}

function simple_wp_mcp_tool_list_taxonomies( $args ) {
	$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
	$result     = array();

	foreach ( $taxonomies as $tax ) {
		$result[] = array(
			'name'         => $tax->name,
			'label'        => $tax->label,
			'hierarchical' => (bool) $tax->hierarchical,
			'object_types' => array_values( $tax->object_type ),
		);
	}

	return $result;
}

function simple_wp_mcp_tool_list_terms( $args ) {
	if ( empty( $args['taxonomy'] ) ) {
		throw new Exception( 'taxonomy is required' );
	}

	$taxonomy = sanitize_key( $args['taxonomy'] );
	if ( ! taxonomy_exists( $taxonomy ) ) {
		throw new Exception( 'Unknown taxonomy: ' . $taxonomy . '. Use list_taxonomies to see available values.' );
	}

	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) ) {
		throw new Exception( $terms->get_error_message() );
	}

	return array_map( 'simple_wp_mcp_format_term', $terms );
}

function simple_wp_mcp_tool_set_terms( $args ) {
	$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
	$post = $id ? get_post( $id ) : null;

	if ( ! $post ) {
		throw new Exception( 'Post not found: ' . $id );
	}
	if ( empty( $args['taxonomy'] ) ) {
		throw new Exception( 'taxonomy is required' );
	}

	$taxonomy = sanitize_key( $args['taxonomy'] );
	if ( ! taxonomy_exists( $taxonomy ) ) {
		throw new Exception( 'Unknown taxonomy: ' . $taxonomy . '. Use list_taxonomies to see available values.' );
	}
	if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
		throw new Exception( 'Taxonomy "' . $taxonomy . '" is not registered for post type "' . $post->post_type . '"' );
	}

	$terms  = isset( $args['terms'] ) && is_array( $args['terms'] ) ? $args['terms'] : array();
	$result = wp_set_post_terms( $id, $terms, $taxonomy, false );
	if ( is_wp_error( $result ) ) {
		throw new Exception( $result->get_error_message() );
	}

	$current = wp_get_post_terms( $id, $taxonomy );
	if ( is_wp_error( $current ) ) {
		throw new Exception( $current->get_error_message() );
	}

	return array(
		'id'       => $id,
		'taxonomy' => $taxonomy,
		'terms'    => array_map( 'simple_wp_mcp_format_term', $current ),
	);
}

/* -----------------------------------------------------------------------
 * Navigation menus
 * --------------------------------------------------------------------- */

function simple_wp_mcp_format_menu( $menu ) {
	return array(
		'id'         => $menu->term_id,
		'name'       => $menu->name,
		'slug'       => $menu->slug,
		'item_count' => (int) $menu->count,
	);
}

function simple_wp_mcp_format_menu_item( $item ) {
	return array(
		'id'          => $item->ID,
		'title'       => $item->title,
		'url'         => $item->url,
		'parent'      => (int) $item->menu_item_parent,
		'position'    => (int) $item->menu_order,
		'object_type' => $item->object,
		'object_id'   => (int) $item->object_id,
		'type'        => $item->type,
	);
}

function simple_wp_mcp_tool_list_menus( $args ) {
	return array_map( 'simple_wp_mcp_format_menu', wp_get_nav_menus() );
}

function simple_wp_mcp_tool_get_menu( $args ) {
	$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
	if ( ! $id || ! wp_get_nav_menu_object( $id ) ) {
		throw new Exception( 'Menu not found: ' . $id );
	}

	$items = wp_get_nav_menu_items( $id );
	if ( false === $items ) {
		$items = array();
	}

	return array(
		'id'    => $id,
		'items' => array_map( 'simple_wp_mcp_format_menu_item', $items ),
	);
}

function simple_wp_mcp_tool_create_menu( $args ) {
	if ( empty( $args['name'] ) ) {
		throw new Exception( 'name is required' );
	}

	$id = wp_create_nav_menu( sanitize_text_field( $args['name'] ) );
	if ( is_wp_error( $id ) ) {
		throw new Exception( $id->get_error_message() );
	}

	return simple_wp_mcp_format_menu( wp_get_nav_menu_object( $id ) );
}

function simple_wp_mcp_tool_add_menu_item( $args ) {
	$menu_id = isset( $args['menu_id'] ) ? (int) $args['menu_id'] : 0;
	if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
		throw new Exception( 'Menu not found: ' . $menu_id );
	}

	$item_args = array(
		'menu-item-status'     => 'publish',
		'menu-item-parent-id'  => isset( $args['parent_id'] ) ? (int) $args['parent_id'] : 0,
	);

	if ( ! empty( $args['position'] ) ) {
		$item_args['menu-item-position'] = (int) $args['position'];
	}

	if ( ! empty( $args['post_id'] ) ) {
		$target = get_post( (int) $args['post_id'] );
		if ( ! $target ) {
			throw new Exception( 'Post not found: ' . $args['post_id'] );
		}
		$item_args['menu-item-object-id'] = $target->ID;
		$item_args['menu-item-object']    = $target->post_type;
		$item_args['menu-item-type']      = 'post_type';
		$item_args['menu-item-title']     = ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
	} elseif ( ! empty( $args['url'] ) ) {
		if ( empty( $args['title'] ) ) {
			throw new Exception( 'title is required for a custom URL menu item' );
		}
		$item_args['menu-item-type']  = 'custom';
		$item_args['menu-item-title'] = sanitize_text_field( $args['title'] );
		$item_args['menu-item-url']   = esc_url_raw( $args['url'] );
	} else {
		throw new Exception( 'Either post_id or url is required' );
	}

	$item_id = wp_update_nav_menu_item( $menu_id, 0, $item_args );
	if ( is_wp_error( $item_id ) ) {
		throw new Exception( $item_id->get_error_message() );
	}

	return simple_wp_mcp_format_menu_item( wp_setup_nav_menu_item( get_post( $item_id ) ) );
}

function simple_wp_mcp_tool_remove_menu_item( $args ) {
	$item_id = isset( $args['item_id'] ) ? (int) $args['item_id'] : 0;
	$item    = $item_id ? get_post( $item_id ) : null;

	if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
		throw new Exception( 'Menu item not found: ' . $item_id );
	}

	if ( ! wp_delete_post( $item_id, true ) ) {
		throw new Exception( 'Failed to remove menu item: ' . $item_id );
	}

	return array(
		'id'      => $item_id,
		'deleted' => true,
	);
}

function simple_wp_mcp_tool_list_menu_locations( $args ) {
	$registered = get_registered_nav_menus();
	$assigned   = get_nav_menu_locations();
	$result     = array();

	foreach ( $registered as $location => $description ) {
		$result[] = array(
			'location'    => $location,
			'description' => $description,
			'menu_id'     => isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : null,
		);
	}

	return $result;
}

function simple_wp_mcp_tool_assign_menu_location( $args ) {
	if ( empty( $args['location'] ) ) {
		throw new Exception( 'location is required' );
	}

	$location   = sanitize_key( $args['location'] );
	$registered = get_registered_nav_menus();
	if ( ! isset( $registered[ $location ] ) ) {
		throw new Exception( 'Unknown menu location: ' . $location . '. Use list_menu_locations to see valid values for this theme.' );
	}

	$menu_id   = isset( $args['menu_id'] ) ? (int) $args['menu_id'] : 0;
	$locations = get_theme_mod( 'nav_menu_locations', array() );

	if ( $menu_id > 0 ) {
		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			throw new Exception( 'Menu not found: ' . $menu_id );
		}
		$locations[ $location ] = $menu_id;
	} else {
		unset( $locations[ $location ] );
	}

	set_theme_mod( 'nav_menu_locations', $locations );

	return array(
		'location' => $location,
		'menu_id'  => $menu_id > 0 ? $menu_id : null,
	);
}

/* -----------------------------------------------------------------------
 * Site settings
 * --------------------------------------------------------------------- */

function simple_wp_mcp_tool_get_site_settings( $args ) {
	$page_on_front  = (int) get_option( 'page_on_front' );
	$page_for_posts = (int) get_option( 'page_for_posts' );

	return array(
		'title'               => get_bloginfo( 'name' ),
		'tagline'             => get_bloginfo( 'description' ),
		'url'                 => home_url(),
		'admin_email'         => get_option( 'admin_email' ),
		'timezone_string'     => get_option( 'timezone_string' ),
		'date_format'         => get_option( 'date_format' ),
		'time_format'         => get_option( 'time_format' ),
		'start_of_week'       => (int) get_option( 'start_of_week' ),
		'show_on_front'       => get_option( 'show_on_front' ),
		'page_on_front'       => $page_on_front ? $page_on_front : null,
		'page_for_posts'      => $page_for_posts ? $page_for_posts : null,
		'permalink_structure' => get_option( 'permalink_structure' ),
		'active_theme'        => get_option( 'stylesheet' ),
	);
}

function simple_wp_mcp_tool_update_site_settings( $args ) {
	$updated = array();

	if ( isset( $args['title'] ) ) {
		update_option( 'blogname', sanitize_text_field( $args['title'] ) );
		$updated[] = 'title';
	}
	if ( isset( $args['tagline'] ) ) {
		update_option( 'blogdescription', sanitize_text_field( $args['tagline'] ) );
		$updated[] = 'tagline';
	}
	if ( isset( $args['timezone_string'] ) ) {
		update_option( 'timezone_string', sanitize_text_field( $args['timezone_string'] ) );
		$updated[] = 'timezone_string';
	}
	if ( isset( $args['date_format'] ) ) {
		update_option( 'date_format', sanitize_text_field( $args['date_format'] ) );
		$updated[] = 'date_format';
	}
	if ( isset( $args['time_format'] ) ) {
		update_option( 'time_format', sanitize_text_field( $args['time_format'] ) );
		$updated[] = 'time_format';
	}
	if ( isset( $args['start_of_week'] ) ) {
		update_option( 'start_of_week', (int) $args['start_of_week'] );
		$updated[] = 'start_of_week';
	}
	if ( isset( $args['show_on_front'] ) && in_array( $args['show_on_front'], array( 'posts', 'page' ), true ) ) {
		update_option( 'show_on_front', $args['show_on_front'] );
		$updated[] = 'show_on_front';
	}
	if ( isset( $args['page_on_front'] ) ) {
		$page = get_post( (int) $args['page_on_front'] );
		if ( ! $page || 'page' !== $page->post_type ) {
			throw new Exception( 'page_on_front must be the ID of an existing page' );
		}
		update_option( 'page_on_front', $page->ID );
		$updated[] = 'page_on_front';
	}
	if ( isset( $args['page_for_posts'] ) ) {
		$page = get_post( (int) $args['page_for_posts'] );
		if ( ! $page || 'page' !== $page->post_type ) {
			throw new Exception( 'page_for_posts must be the ID of an existing page' );
		}
		update_option( 'page_for_posts', $page->ID );
		$updated[] = 'page_for_posts';
	}

	return array_merge( array( 'updated' => $updated ), simple_wp_mcp_tool_get_site_settings( array() ) );
}

/* -----------------------------------------------------------------------
 * Media library (list / get / delete — upload_media handles creation)
 * --------------------------------------------------------------------- */

function simple_wp_mcp_format_media( $post ) {
	return array(
		'id'        => $post->ID,
		'title'     => $post->post_title,
		'url'       => wp_get_attachment_url( $post->ID ),
		'mime_type' => $post->post_mime_type,
		'date'      => $post->post_date,
		'alt'       => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
	);
}

function simple_wp_mcp_tool_list_media( $args ) {
	$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;

	$query_args = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $per_page,
	);

	if ( ! empty( $args['mime_type'] ) ) {
		$query_args['post_mime_type'] = sanitize_text_field( $args['mime_type'] );
	}
	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = sanitize_text_field( $args['search'] );
	}

	return array_map( 'simple_wp_mcp_format_media', get_posts( $query_args ) );
}

function simple_wp_mcp_tool_get_media( $args ) {
	$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
	$post = $id ? get_post( $id ) : null;

	if ( ! $post || 'attachment' !== $post->post_type ) {
		throw new Exception( 'Media not found: ' . $id );
	}

	return simple_wp_mcp_format_media( $post );
}

function simple_wp_mcp_tool_delete_media( $args ) {
	$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
	$post = $id ? get_post( $id ) : null;

	if ( ! $post || 'attachment' !== $post->post_type ) {
		throw new Exception( 'Media not found: ' . $id );
	}

	if ( ! wp_delete_attachment( $id, true ) ) {
		throw new Exception( 'Failed to delete media: ' . $id );
	}

	return array(
		'id'      => $id,
		'deleted' => true,
	);
}

/* -----------------------------------------------------------------------
 * Plugin management (list / activate / deactivate only — never install)
 * --------------------------------------------------------------------- */

/**
 * Deliberately no install/delete tools here: installing a plugin runs
 * arbitrary, attacker-suppliable PHP on the server, which is a categorically
 * different risk than toggling code that's already on disk. See CLAUDE.md.
 */
function simple_wp_mcp_require_plugin_capability() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		throw new Exception( 'The "act as" user (see Settings → Simple WP MCP) does not have permission to manage plugins — this requires an administrator.' );
	}
}

function simple_wp_mcp_format_plugin( $plugin_file, $plugin_data ) {
	return array(
		'file'           => $plugin_file,
		'name'           => $plugin_data['Name'],
		'version'        => $plugin_data['Version'],
		'description'    => wp_strip_all_tags( $plugin_data['Description'] ),
		'author'         => wp_strip_all_tags( $plugin_data['Author'] ),
		'active'         => is_plugin_active( $plugin_file ),
		'network_active' => is_plugin_active_for_network( $plugin_file ),
	);
}

function simple_wp_mcp_tool_list_plugins( $args ) {
	simple_wp_mcp_require_plugin_capability();
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$result = array();
	foreach ( get_plugins() as $file => $data ) {
		$result[] = simple_wp_mcp_format_plugin( $file, $data );
	}

	return $result;
}

function simple_wp_mcp_tool_activate_plugin( $args ) {
	simple_wp_mcp_require_plugin_capability();

	if ( empty( $args['file'] ) ) {
		throw new Exception( 'file is required, e.g. "akismet/akismet.php" — see list_plugins.' );
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$file      = $args['file'];
	$installed = get_plugins();
	if ( ! isset( $installed[ $file ] ) ) {
		throw new Exception( 'Plugin not found: ' . $file . '. Use list_plugins to see installed plugins.' );
	}

	$result = activate_plugin( $file );
	if ( is_wp_error( $result ) ) {
		throw new Exception( $result->get_error_message() );
	}

	return simple_wp_mcp_format_plugin( $file, $installed[ $file ] );
}

function simple_wp_mcp_tool_deactivate_plugin( $args ) {
	simple_wp_mcp_require_plugin_capability();

	if ( empty( $args['file'] ) ) {
		throw new Exception( 'file is required, e.g. "akismet/akismet.php" — see list_plugins.' );
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$file = $args['file'];
	if ( plugin_basename( __FILE__ ) === $file ) {
		throw new Exception( 'Refusing to deactivate Simple WP MCP itself — that would immediately disconnect this MCP session.' );
	}

	$installed = get_plugins();
	if ( ! isset( $installed[ $file ] ) ) {
		throw new Exception( 'Plugin not found: ' . $file . '. Use list_plugins to see installed plugins.' );
	}

	deactivate_plugins( $file );

	return simple_wp_mcp_format_plugin( $file, $installed[ $file ] );
}
