<?php
/**
 * Token Registry admin view.
 *
 * Variables in scope:
 *  - array $rows   List of registry rows (associative).
 *  - string $status Current filter status.
 *  - string $notice Optional notice text.
 *
 * @package GoldtWebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$goldtwmcp_base_url = admin_url( 'admin.php?page=' . \GoldtWebMCP\Admin\Token_Registry_Admin::PAGE_SLUG );

$goldtwmcp_status_labels = array(
	'active'  => __( 'Active', 'goldt-webmcp-bridge' ),
	'revoked' => __( 'Revoked', 'goldt-webmcp-bridge' ),
	'all'     => __( 'All', 'goldt-webmcp-bridge' ),
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'AI Connect — Token Registry', 'goldt-webmcp-bridge' ); ?></h1>

	<p>
		<?php
		esc_html_e(
			'Inventory of every access token issued by the OAuth server. Only the first 16 characters (token prefix) are stored so this list is safe to expose to administrators. Revoking a token here will also block any further bearer-auth lookups.',
			'goldt-webmcp-bridge'
		);
		?>
	</p>

	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<ul class="subsubsub">
		<?php
		$goldtwmcp_first = true;
		foreach ( $goldtwmcp_status_labels as $goldtwmcp_key => $goldtwmcp_label ) :
			$goldtwmcp_is_current = ( $status === $goldtwmcp_key );
			?>
			<li>
				<?php
				if ( ! $goldtwmcp_first ) :
					?>
					|<?php endif; ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'status', $goldtwmcp_key, $goldtwmcp_base_url ) ); ?>"
					class="<?php echo $goldtwmcp_is_current ? 'current' : ''; ?>"
				>
					<?php echo esc_html( $goldtwmcp_label ); ?>
				</a>
			</li>
			<?php
			$goldtwmcp_first = false;
		endforeach;
		?>
	</ul>
	<br class="clear">

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Prefix', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'User', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Client', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Scope', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Source', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Issued', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Expires', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Revoked', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'IP', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'goldt-webmcp-bridge' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="11" style="text-align: center; padding: 20px;">
						<?php esc_html_e( 'No tokens found for this filter.', 'goldt-webmcp-bridge' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $rows as $goldtwmcp_row ) : ?>
					<?php
					$goldtwmcp_row_user       = get_userdata( (int) $goldtwmcp_row['user_id'] );
					$goldtwmcp_row_revoked_by = ! empty( $goldtwmcp_row['revoked_by'] ) ? get_userdata( (int) $goldtwmcp_row['revoked_by'] ) : null;
					$goldtwmcp_is_revoked     = null !== $goldtwmcp_row['revoked_at'];
					?>
					<tr>
						<td><code><?php echo esc_html( $goldtwmcp_row['token_prefix'] ); ?>…</code></td>
					<td>
						<?php
						if ( $goldtwmcp_row_user ) {
							echo esc_html( $goldtwmcp_row_user->user_login );
						} else {
							echo '#' . esc_html( (string) $goldtwmcp_row['user_id'] );
						}
						?>
					</td>
						<td><?php echo esc_html( (string) $goldtwmcp_row['client_id'] ); ?></td>
						<td><?php echo esc_html( (string) $goldtwmcp_row['scope'] ); ?></td>
						<td><?php echo esc_html( (string) $goldtwmcp_row['source'] ); ?></td>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $goldtwmcp_row['issued_at'] ) ); ?></td>
					<td>
						<?php
						$goldtwmcp_exp = (int) $goldtwmcp_row['expires_at'];
						if ( $goldtwmcp_exp < time() ) {
							echo '<span style="color: #999;">' . esc_html( gmdate( 'Y-m-d H:i', $goldtwmcp_exp ) ) . ' ' . esc_html__( '(expired)', 'goldt-webmcp-bridge' ) . '</span>';
						} else {
							echo esc_html( gmdate( 'Y-m-d H:i', $goldtwmcp_exp ) );
						}
						?>
					</td>
						<td>
							<?php
							if ( null === $goldtwmcp_row['last_used_at'] ) {
								echo '<span style="color: #999;">' . esc_html__( '—', 'goldt-webmcp-bridge' ) . '</span>';
							} else {
								echo esc_html( gmdate( 'Y-m-d H:i', (int) $goldtwmcp_row['last_used_at'] ) );
							}
							?>
						</td>
					<td>
						<?php
						if ( $goldtwmcp_is_revoked ) {
							echo esc_html( gmdate( 'Y-m-d H:i', (int) $goldtwmcp_row['revoked_at'] ) );
							if ( $goldtwmcp_row_revoked_by ) {
								echo '<br><small>' . esc_html__( 'by', 'goldt-webmcp-bridge' ) . ' ' . esc_html( $goldtwmcp_row_revoked_by->user_login ) . '</small>';
							}
						} else {
							echo '<span style="color: #999;">—</span>';
						}
						?>
					</td>
						<td><?php echo esc_html( (string) ( $goldtwmcp_row['ip_address'] ?? '' ) ); ?></td>
					<td>
						<?php if ( ! $goldtwmcp_is_revoked ) : ?>
							<form method="post" style="display: inline;">
								<?php wp_nonce_field( 'goldtwmcp_token_registry_revoke' ); ?>
								<input
									type="hidden"
									name="goldtwmcp_revoke_registry_id"
									value="<?php echo esc_attr( (string) $goldtwmcp_row['id'] ); ?>"
								>
								<button
									type="submit"
									class="button button-small"
									onclick="return confirm('<?php echo esc_js( __( 'Revoke this token? AI agents using it will be disconnected.', 'goldt-webmcp-bridge' ) ); ?>');"
								>
									<?php esc_html_e( 'Revoke', 'goldt-webmcp-bridge' ); ?>
								</button>
							</form>
						<?php else : ?>
							<span style="color: #999;"><?php esc_html_e( 'Revoked', 'goldt-webmcp-bridge' ); ?></span>
						<?php endif; ?>
					</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<h2 style="margin-top: 32px;"><?php esc_html_e( 'REST API', 'goldt-webmcp-bridge' ); ?></h2>
	<p><?php esc_html_e( 'Admins (manage_options) can manage tokens via these endpoints:', 'goldt-webmcp-bridge' ); ?></p>
	<ul style="list-style: disc; margin-left: 20px;">
		<li><code>GET /wp-json/goldt-mcp/v1/admin/tokens?status=active</code></li>
		<li><code>DELETE /wp-json/goldt-mcp/v1/admin/tokens/&lt;id&gt;</code></li>
	</ul>
</div>
