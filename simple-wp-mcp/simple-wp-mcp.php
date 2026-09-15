<?php
/**
 * Plugin Name: Simple WP MCP
 * Plugin URI: https://github.com/
 * Description: Minimal, self-hosted MCP (Model Context Protocol) server so Claude.ai's custom connector can manage this site's posts, pages, and media directly. Single long-lived secret in the URL — no OAuth, no expiring tokens, no IP pinning.
 * Version: 1.0.0
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

define( 'SIMPLE_WP_MCP_VERSION', '1.0.0' );
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

	return array(
		array(
			'name'        => 'list_posts',
			'description' => 'List blog posts, optionally filtered by status or search term.',
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
			'name'        => 'get_post',
			'description' => 'Get a single post by ID, including full content.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array( 'id' => $id_schema ),
				'required'   => array( 'id' ),
			),
		),
		array(
			'name'        => 'create_post',
			'description' => 'Create a new blog post. Defaults to draft status unless status is explicitly set to "publish".',
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
			'name'        => 'update_post',
			'description' => 'Update an existing post\'s title, content, and/or status.',
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
			'description' => 'Delete a post. Moves to trash by default; pass force=true to permanently delete.',
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
		'list_posts'      => 'simple_wp_mcp_tool_list_posts',
		'get_post'        => 'simple_wp_mcp_tool_get_post',
		'create_post'     => 'simple_wp_mcp_tool_create_post',
		'update_post'     => 'simple_wp_mcp_tool_update_post',
		'delete_post'     => 'simple_wp_mcp_tool_delete_post',
		'list_pages'      => 'simple_wp_mcp_tool_list_pages',
		'get_page'        => 'simple_wp_mcp_tool_get_page',
		'create_page'     => 'simple_wp_mcp_tool_create_page',
		'update_page'     => 'simple_wp_mcp_tool_update_page',
		'delete_page'     => 'simple_wp_mcp_tool_delete_page',
		'list_categories' => 'simple_wp_mcp_tool_list_categories',
		'list_tags'       => 'simple_wp_mcp_tool_list_tags',
		'upload_media'    => 'simple_wp_mcp_tool_upload_media',
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

function simple_wp_mcp_format_post( $post ) {
	return array(
		'id'       => $post->ID,
		'title'    => $post->post_title,
		'status'   => $post->post_status,
		'type'     => $post->post_type,
		'link'     => get_permalink( $post ),
		'date'     => $post->post_date,
		'modified' => $post->post_modified,
		'excerpt'  => wp_strip_all_tags( get_the_excerpt( $post ) ),
		'author'   => (int) $post->post_author,
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

function simple_wp_mcp_get_content( $args, $post_type ) {
	$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
	$post = $id ? get_post( $id ) : null;

	if ( ! $post || $post->post_type !== $post_type ) {
		throw new Exception( ucfirst( $post_type ) . ' not found: ' . $id );
	}

	return simple_wp_mcp_format_post_full( $post );
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

	if ( ! $post || $post->post_type !== $post_type ) {
		throw new Exception( ucfirst( $post_type ) . ' not found: ' . $id );
	}

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

	if ( ! $post || $post->post_type !== $post_type ) {
		throw new Exception( ucfirst( $post_type ) . ' not found: ' . $id );
	}

	$force  = ! empty( $args['force'] );
	$result = wp_delete_post( $id, $force );

	if ( ! $result ) {
		throw new Exception( 'Failed to delete ' . $post_type . ': ' . $id );
	}

	return array(
		'id'      => $id,
		'deleted' => true,
		'force'   => $force,
	);
}

function simple_wp_mcp_tool_list_posts( $args ) {
	return simple_wp_mcp_list_content( $args, 'post' );
}
function simple_wp_mcp_tool_get_post( $args ) {
	return simple_wp_mcp_get_content( $args, 'post' );
}
function simple_wp_mcp_tool_create_post( $args ) {
	return simple_wp_mcp_create_content( $args, 'post' );
}
function simple_wp_mcp_tool_update_post( $args ) {
	return simple_wp_mcp_update_content( $args, 'post' );
}
function simple_wp_mcp_tool_delete_post( $args ) {
	return simple_wp_mcp_delete_content( $args, 'post' );
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
