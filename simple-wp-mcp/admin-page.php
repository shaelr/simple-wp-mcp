<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$connect_url     = simple_wp_mcp_get_connect_url();
$current_act_as  = (int) get_option( SIMPLE_WP_MCP_OPTION_USER );
$eligible_users  = get_users( array( 'role__in' => array( 'administrator', 'editor' ) ) );
?>
<div class="wrap">
	<h1>Simple WP MCP</h1>

	<?php echo isset( $notice ) ? $notice : ''; ?>

	<p>This site exposes an MCP (Model Context Protocol) server so Claude.ai's custom connector can manage its posts, pages, and media directly. The URL below contains your secret token &mdash; anyone with this URL can act as the user selected below, so treat it like a password.</p>

	<h2>Connect URL</h2>
	<p>
		<input type="text" readonly="readonly" style="width:100%;max-width:700px;" onclick="this.select();" value="<?php echo esc_attr( $connect_url ); ?>" />
	</p>
	<p>Paste this full URL into Claude.ai's custom connector settings.</p>

	<form method="post">
		<?php wp_nonce_field( 'simple_wp_mcp_regenerate_action', 'simple_wp_mcp_nonce' ); ?>
		<p>
			<button
				type="submit"
				name="simple_wp_mcp_regenerate"
				value="1"
				class="button button-secondary"
				onclick="return confirm('Regenerate the token? The current connect URL will stop working immediately.');"
			>Regenerate Token</button>
		</p>
	</form>

	<h2>Act as User</h2>
	<p>All content created or edited through the MCP server is attributed to this user.</p>
	<form method="post">
		<?php wp_nonce_field( 'simple_wp_mcp_save_user_action', 'simple_wp_mcp_user_nonce' ); ?>
		<select name="simple_wp_mcp_user_id">
			<?php foreach ( $eligible_users as $user ) : ?>
				<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $current_act_as, $user->ID ); ?>>
					<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p>
			<button type="submit" name="simple_wp_mcp_save_user" value="1" class="button button-primary">Save</button>
		</p>
	</form>
</div>
