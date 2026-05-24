<?php
/**
 * My AI Tokens — single-revoke confirmation view.
 *
 * Variables in scope (set by My_Tokens_Page::render_page()):
 *  - array $row        The registry row to revoke.
 *  - int   $user_id    Current user ID.
 *
 * @package GoldtWebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$goldtwmcp_base_url = admin_url( 'users.php?page=' . \GoldtWebMCP\User\My_Tokens_Page::PAGE_SLUG );
?>
<div class="wrap">
	<h1>🔑 <?php esc_html_e( 'Revoke AI Token', 'goldt-webmcp-bridge' ); ?></h1>

	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'Are you sure you want to revoke this token?', 'goldt-webmcp-bridge' ); ?></strong>
			<?php esc_html_e( 'The AI agent using it will be immediately disconnected and will need to re-authorize.', 'goldt-webmcp-bridge' ); ?>
		</p>
	</div>

	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Token prefix', 'goldt-webmcp-bridge' ); ?></th>
			<td><code><?php echo esc_html( $row['token_prefix'] ); ?>…</code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Client', 'goldt-webmcp-bridge' ); ?></th>
			<td><?php echo esc_html( (string) $row['client_id'] ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Source', 'goldt-webmcp-bridge' ); ?></th>
			<td><?php echo esc_html( (string) $row['source'] ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Issued', 'goldt-webmcp-bridge' ); ?></th>
			<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $row['issued_at'] ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Last used', 'goldt-webmcp-bridge' ); ?></th>
			<td>
				<?php
				if ( empty( $row['last_used_at'] ) ) {
					esc_html_e( 'Never', 'goldt-webmcp-bridge' );
				} else {
					echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $row['last_used_at'] ) );
				}
				?>
			</td>
		</tr>
		<?php if ( ! empty( $row['last_used_ip'] ) ) : ?>
		<tr>
			<th><?php esc_html_e( 'Last IP', 'goldt-webmcp-bridge' ); ?></th>
			<td><?php echo esc_html( (string) $row['last_used_ip'] ); ?></td>
		</tr>
		<?php endif; ?>
	</table>

	<form method="post" action="<?php echo esc_url( $goldtwmcp_base_url ); ?>">
		<?php wp_nonce_field( 'goldtwmcp_my_tokens_revoke_' . get_current_user_id() ); ?>
		<input type="hidden" name="goldtwmcp_revoke_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
		<input type="hidden" name="goldtwmcp_confirm_revoke" value="1">

		<?php
		submit_button( __( 'Yes, revoke this token', 'goldt-webmcp-bridge' ), 'delete', 'submit', false );
		echo ' ';
		?>
		<a href="<?php echo esc_url( $goldtwmcp_base_url ); ?>" class="button">
			<?php esc_html_e( 'Cancel', 'goldt-webmcp-bridge' ); ?>
		</a>
	</form>
</div>
