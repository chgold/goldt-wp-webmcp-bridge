<?php
/**
 * OAuth out-of-band authorization code view.
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
	<title><?php esc_html_e( 'Authorization Successful', 'goldt-webmcp-bridge' ); ?></title>
	<?php wp_head(); ?>
</head>
<body>
	<div class="success-container">
		<svg class="success-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>

		<h1><?php esc_html_e( 'Authorization Successful', 'goldt-webmcp-bridge' ); ?></h1>
		<p class="instruction">
			<?php esc_html_e( 'Copy this authorization code and paste it back to the AI application:', 'goldt-webmcp-bridge' ); ?>
		</p>

		<div class="code-container">
			<div class="code-label"><?php esc_html_e( 'Authorization Code', 'goldt-webmcp-bridge' ); ?></div>
			<div class="code-value" id="authCode"><?php echo esc_html( $code ); ?></div>
		</div>

		<button class="copy-btn" onclick="copyCode()">
			<?php esc_html_e( 'Copy Code', 'goldt-webmcp-bridge' ); ?>
		</button>
		<div class="copy-success" id="copySuccess">
			✓ <?php esc_html_e( 'Code copied to clipboard!', 'goldt-webmcp-bridge' ); ?>
		</div>

		<div class="warning">
			<p><strong><?php esc_html_e( 'Important:', 'goldt-webmcp-bridge' ); ?></strong> <?php esc_html_e( 'This code expires in 10 minutes and can only be used once.', 'goldt-webmcp-bridge' ); ?></p>
		</div>
	</div>
	<script>
	function copyCode() {
		var code = document.getElementById( 'authCode' ).innerText;
		var btn  = document.querySelector( '.copy-btn' );
		var msg  = document.getElementById( 'copySuccess' );

		function showSuccess() {
			if ( btn ) { btn.disabled = true; }
			if ( msg ) { msg.style.display = 'block'; }
			setTimeout( function () {
				if ( btn ) { btn.disabled = false; }
				if ( msg ) { msg.style.display = 'none'; }
			}, 3000 );
		}

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( code ).then( showSuccess ).catch( fallback );
		} else {
			fallback();
		}

		function fallback() {
			var ta = document.createElement( 'textarea' );
			ta.value = code;
			ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
			document.body.appendChild( ta );
			ta.focus();
			ta.select();
			try { document.execCommand( 'copy' ); showSuccess(); } catch ( e ) {}
			document.body.removeChild( ta );
		}
	}
	</script>
</body>
</html>
