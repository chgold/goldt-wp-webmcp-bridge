<?php
/**
 * My AI Tokens — list view.
 *
 * Variables in scope (set by My_Tokens_Page::render_page()):
 *  - array  $rows          Token registry rows for the current user.
 *  - string $status        Active filter.
 *  - string $notice        Optional notice message.
 *  - string $notice_type   'success' or 'error'.
 *  - int    $user_id       Current user ID.
 *
 * @package GoldtWebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$goldtwmcp_base_url = admin_url( 'users.php?page=' . \GoldtWebMCP\User\My_Tokens_Page::PAGE_SLUG );

$goldtwmcp_status_labels = array(
	'active'    => __( 'Active', 'goldt-webmcp-bridge' ),
	'unused'    => __( 'Unused', 'goldt-webmcp-bridge' ),
	'inactive'  => __( 'Inactive', 'goldt-webmcp-bridge' ),
	'renewable' => __( 'Renewable', 'goldt-webmcp-bridge' ),
	'expired'   => __( 'Expired', 'goldt-webmcp-bridge' ),
	'revoked'   => __( 'Revoked', 'goldt-webmcp-bridge' ),
	'all'       => __( 'All', 'goldt-webmcp-bridge' ),
);

$goldtwmcp_badge_kses = \GoldtWebMCP\Admin\Token_Registry_Admin::badge_kses_rules();
?>
<div class="wrap">
	<h1>🔑 <?php esc_html_e( 'My AI Tokens', 'goldt-webmcp-bridge' ); ?></h1>

	<p>
		<?php esc_html_e( 'These are the access tokens issued to AI agents on your behalf. Revoking a token immediately disconnects the agent — it will need to re-authorize to regain access.', 'goldt-webmcp-bridge' ); ?>
	</p>

	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( isset( $notice_type ) ? $notice_type : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $notice ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Filter tabs -->
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
					class="<?php echo esc_attr( $goldtwmcp_is_current ? 'current' : '' ); ?>"
				><?php echo esc_html( $goldtwmcp_label ); ?></a>
			</li>
			<?php $goldtwmcp_first = false; ?>
		<?php endforeach; ?>
	</ul>
	<br class="clear">

	<!-- Revoke-all button -->
	<?php if ( ! empty( $rows ) && 'revoked' !== $status ) : ?>
		<p>
			<a
				href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'goldtwmcp_revoke_all' => '1',
							'status'               => $status,
						),
						$goldtwmcp_base_url
					)
				);
				?>
						"
				class="button button-secondary"
			>
				<?php
				if ( 'all' === $status ) {
					esc_html_e( 'Revoke all my tokens', 'goldt-webmcp-bridge' );
				} else {
					/* translators: %s: filter label */
					printf( esc_html__( 'Revoke all %s tokens', 'goldt-webmcp-bridge' ), esc_html( strtolower( $goldtwmcp_status_labels[ $status ] ?? $status ) ) );
				}
				?>
			</a>
		</p>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:130px;"><?php esc_html_e( 'Token prefix', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Client', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Source', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Issued', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Last IP', 'goldt-webmcp-bridge' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Last agent', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'State', 'goldt-webmcp-bridge' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'goldt-webmcp-bridge' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="9" style="text-align:center; padding:20px;">
						<?php esc_html_e( 'No tokens found for this filter.', 'goldt-webmcp-bridge' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $rows as $goldtwmcp_row ) : ?>
					<?php
					$goldtwmcp_is_revoked = ! empty( $goldtwmcp_row['revoked_at'] );
					$goldtwmcp_ua_short   = ! empty( $goldtwmcp_row['last_used_ua'] )
						? substr( (string) $goldtwmcp_row['last_used_ua'], 0, 35 ) . ( strlen( (string) $goldtwmcp_row['last_used_ua'] ) > 35 ? '…' : '' )
						: '';
					?>
					<tr>
						<td><code><?php echo esc_html( $goldtwmcp_row['token_prefix'] ); ?>…</code></td>
						<td><?php echo esc_html( (string) $goldtwmcp_row['client_id'] ); ?></td>
						<td><?php echo esc_html( (string) $goldtwmcp_row['source'] ); ?></td>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $goldtwmcp_row['issued_at'] ) ); ?></td>
						<td>
							<?php
							if ( empty( $goldtwmcp_row['last_used_at'] ) ) {
								echo '<span style="color:#999;">' . esc_html__( 'Never', 'goldt-webmcp-bridge' ) . '</span>';
							} else {
								echo esc_html( gmdate( 'Y-m-d H:i', (int) $goldtwmcp_row['last_used_at'] ) );
							}
							?>
						</td>
						<td><?php echo esc_html( (string) ( $goldtwmcp_row['last_used_ip'] ?? '' ) ); ?></td>
						<td>
							<?php if ( $goldtwmcp_ua_short ) : ?>
								<span title="<?php echo esc_attr( (string) $goldtwmcp_row['last_used_ua'] ); ?>">
									<?php echo esc_html( $goldtwmcp_ua_short ); ?>
								</span>
							<?php else : ?>
								<span style="color:#999;">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							echo wp_kses(
								\GoldtWebMCP\Admin\Token_Registry_Admin::state_badge( $goldtwmcp_row ),
								$goldtwmcp_badge_kses
							);
							?>
						</td>
						<td>
							<?php if ( ! $goldtwmcp_is_revoked ) : ?>
								<a
									href="<?php echo esc_url( add_query_arg( 'goldtwmcp_revoke_id', (int) $goldtwmcp_row['id'], $goldtwmcp_base_url ) ); ?>"
									class="button button-small"
								><?php esc_html_e( 'Revoke', 'goldt-webmcp-bridge' ); ?></a>
							<?php else : ?>
								<span style="color:#999;"><?php esc_html_e( 'Revoked', 'goldt-webmcp-bridge' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
