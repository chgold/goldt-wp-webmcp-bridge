<?php
/**
 * My AI Tokens — bulk revoke confirmation view.
 *
 * Variables in scope:
 *  - string $filter_status  The status filter being revoked (e.g. 'active', 'all').
 *  - int    $user_id        Current user ID.
 *
 * @package GoldtWebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$goldtwmcp_base_url    = admin_url( 'users.php?page=' . \GoldtWebMCP\User\My_Tokens_Page::PAGE_SLUG );
$goldtwmcp_safe_status = in_array( $filter_status, \GoldtWebMCP\User\My_Tokens_Page::VALID_STATUSES, true ) ? $filter_status : 'all';

// Preview count.
$goldtwmcp_rows  = \GoldtWebMCP\OAuth\Token_Registry::list(
	array(
		'status'  => $goldtwmcp_safe_status,
		'user_id' => $user_id,
		'limit'   => 500,
	)
);
$goldtwmcp_count = count( $goldtwmcp_rows );
?>
<div class="wrap">
	<h1>🔑 <?php esc_html_e( 'Revoke All Tokens', 'goldt-webmcp-bridge' ); ?></h1>

	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( '⚠️ This action cannot be undone.', 'goldt-webmcp-bridge' ); ?></strong>
		</p>
		<p>
			<?php
			/* translators: %d: number of tokens that will be revoked */
			$goldtwmcp_msg = _n(
				'You are about to revoke %d token. All AI agents using these tokens will be immediately disconnected.',
				'You are about to revoke %d tokens. All AI agents using these tokens will be immediately disconnected.',
				$goldtwmcp_count,
				'goldt-webmcp-bridge'
			);
			printf( esc_html( $goldtwmcp_msg ), (int) $goldtwmcp_count );
			?>
		</p>
	</div>

	<form method="post" action="<?php echo esc_url( $goldtwmcp_base_url ); ?>">
		<?php wp_nonce_field( 'goldtwmcp_my_tokens_revoke_all_' . get_current_user_id() ); ?>
		<input type="hidden" name="goldtwmcp_filter_status" value="<?php echo esc_attr( $goldtwmcp_safe_status ); ?>">
		<input type="hidden" name="goldtwmcp_confirm_revoke_all" value="1">

		<?php
		submit_button( __( 'Yes, revoke all matching tokens', 'goldt-webmcp-bridge' ), 'delete', 'submit', false );
		echo ' ';
		?>
		<a href="<?php echo esc_url( add_query_arg( 'status', $goldtwmcp_safe_status, $goldtwmcp_base_url ) ); ?>" class="button">
			<?php esc_html_e( 'Cancel', 'goldt-webmcp-bridge' ); ?>
		</a>
	</form>
</div>
