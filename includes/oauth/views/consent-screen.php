<?php
/**
 * OAuth consent screen view.
 *
 * @package GoldtWebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Authorization Request', 'goldt-webmcp-bridge' ); ?></title>
	<?php wp_head(); ?>
</head>
<body>
	<div class="consent-container">
		<div class="consent-header">
			<h1><?php esc_html_e( 'Authorization Request', 'goldt-webmcp-bridge' ); ?></h1>
			<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		</div>

		<div class="client-info">
			<div class="client-name"><?php echo esc_html( $client->client_name ); ?></div>
			<p style="color: #646970; font-size: 14px;">
				<?php esc_html_e( 'is requesting access to your account', 'goldt-webmcp-bridge' ); ?>
			</p>
		</div>

		<div class="scopes-section">
			<h2><?php esc_html_e( 'This will allow the application to:', 'goldt-webmcp-bridge' ); ?></h2>
			<ul class="scope-list">
				<?php foreach ( $scopes as $goldtwmcp_scope ) : ?>
					<li class="scope-item">
						<svg class="scope-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
						</svg>
						<span class="scope-label"><?php echo esc_html( goldtwmcp_get_scope_label( $goldtwmcp_scope ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'goldtwmcp_oauth_consent' ); ?>
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>">
			<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
			<input type="hidden" name="response_type" value="<?php echo esc_attr( $response_type ); ?>">
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>">
			<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>">

			<div class="actions">
				<button type="submit" name="goldtwmcp_oauth_deny" class="btn btn-deny">
					<?php esc_html_e( 'Deny', 'goldt-webmcp-bridge' ); ?>
				</button>
				<button type="submit" name="goldtwmcp_oauth_approve" class="btn btn-approve">
					<?php esc_html_e( 'Approve', 'goldt-webmcp-bridge' ); ?>
				</button>
			</div>
		</form>

		<div class="warning">
			<p><?php esc_html_e( 'Only approve if you trust this application. It will have access to your account data based on the permissions above.', 'goldt-webmcp-bridge' ); ?></p>
		</div>
	</div>
	<script>
(function () {
	'use strict';
	// Only show banner in a normal browser tab (not popup/iframe) and not already in new-tab mode
	if (window.opener !== null || window.location.hash.indexOf('nt=1') !== -1) {
		return;
	}

	var container = document.querySelector('.consent-container');
	if (!container) { return; }

	var banner = document.createElement('div');
	banner.id = 'goldtwmcp-nt-banner';
	banner.style.cssText = 'background:#f0f7ff;border:1px solid #2271b1;border-radius:6px;padding:12px 16px;margin:0 0 18px;font-size:14px;color:#1d2327;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;';
	banner.innerHTML =
		'<span>💡 <strong>Tip:</strong> Open the authorization page in a new tab to keep your AI chat session open.</span>' +
		'<span style="display:flex;gap:8px;flex-shrink:0;">' +
		'<button id="goldtwmcp-nt-open" style="background:#2271b1;color:#fff;border:none;border-radius:4px;padding:6px 14px;cursor:pointer;font-size:13px;font-weight:600;">Open in New Tab</button>' +
		'<button id="goldtwmcp-nt-skip" style="background:none;border:1px solid #c3c4c7;border-radius:4px;padding:6px 12px;cursor:pointer;font-size:13px;color:#646970;">Continue here</button>' +
		'</span>';

	container.insertBefore(banner, container.firstChild);

	document.getElementById('goldtwmcp-nt-skip').addEventListener('click', function () {
		banner.parentNode.removeChild(banner);
	});

	document.getElementById('goldtwmcp-nt-open').addEventListener('click', function () {
		var newUrl = window.location.href.split('#')[0] + '#nt=1';
		var newWin = window.open(newUrl, '_blank');

		if (newWin) {
			banner.style.background = '#f0fff4';
			banner.style.borderColor = '#00a32a';
			banner.innerHTML = '<span>✅ <strong>Authorization page opened in a new tab.</strong> Returning to your chat…</span>';
			if (window.history.length > 1) {
				setTimeout(function () { window.history.back(); }, 1500);
			} else {
				setTimeout(function () {
					banner.innerHTML = '<span>✅ Authorization opened in a new tab. You can close this tab and return to your AI agent.</span>';
				}, 1500);
			}
		} else {
			banner.style.background = '#fff8e5';
			banner.style.borderColor = '#dba617';
			banner.innerHTML =
				'<span>⚠️ Unable to open new tab (popup blocked). Right-click the authorization link in your chat and select "Open in new tab", or continue here.</span>' +
				'<button id="goldtwmcp-nt-skip2" style="background:none;border:1px solid #c3c4c7;border-radius:4px;padding:6px 12px;cursor:pointer;font-size:13px;color:#646970;flex-shrink:0;">Continue here</button>';
			document.getElementById('goldtwmcp-nt-skip2').addEventListener('click', function () {
				banner.parentNode.removeChild(banner);
			});
		}
	});
}());
</script>
</body>
</html>
<?php
/**
 * Get the display label for a given OAuth scope.
 *
 * @param string $goldtwmcp_scope_name Scope identifier.
 * @return string
 */
function goldtwmcp_get_scope_label( $goldtwmcp_scope_name ) {
	$goldtwmcp_labels = array(
		'read'         => __( 'Read your posts and content', 'goldt-webmcp-bridge' ),
		'write'        => __( 'Create and modify posts', 'goldt-webmcp-bridge' ),
		'delete'       => __( 'Delete posts', 'goldt-webmcp-bridge' ),
		'manage_users' => __( 'Manage users', 'goldt-webmcp-bridge' ),
	);
	return isset( $goldtwmcp_labels[ $goldtwmcp_scope_name ] ) ? $goldtwmcp_labels[ $goldtwmcp_scope_name ] : ucfirst( $goldtwmcp_scope_name );
}
?>
