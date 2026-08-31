<?php
/**
 * Plugin update checker for Goldnat WordPress Pro plugins.
 *
 * WordPress only auto-checks plugins hosted on wp.org. Our Pro plugins ship
 * from pm.gold-t.co.il/store/, so we hook into WP's update transient
 * ourselves. Bridge core owns the checker so we don't duplicate it in every
 * Pro plugin — each Pro plugin just calls `Goldnat_Plugin_Updater::register()`
 * during plugins_loaded and gets update notices for free.
 *
 * Two hooks power the flow:
 *   pre_set_site_transient_update_plugins → inject entries when a newer
 *     version is available, so WP admin shows "Update available".
 *   plugins_api → return the plugin_information payload when the user
 *     clicks "View version details" in wp-admin.
 *
 * @package GoldtWebMCP\Core
 */

namespace GoldtWebMCP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin_Updater {

	/**
	 * Registered Pro plugins keyed by basename (elementor/elementor.php).
	 *
	 * @var array<string, array>
	 */
	private static $registered = array();

	/**
	 * Default metadata endpoint. Override with GOLDTWMCP_UPDATE_ENDPOINT const.
	 */
	const DEFAULT_ENDPOINT = 'https://pm.gold-t.co.il/api/update-metadata.php';

	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Register a plugin for update checks.
	 *
	 * @param string      $plugin_file Absolute path to the main plugin file.
	 * @param string      $slug        PM slug (e.g. 'elementor-ai-connect').
	 * @param string      $version     Current installed version.
	 * @param string|null $license_key Optional — passed to endpoint as ?license=
	 *                                 so PM can gate download_url on paid users.
	 * @return void
	 */
	public static function register( $plugin_file, $slug, $version, $license_key = null ) {
		$basename = plugin_basename( $plugin_file );
		self::$registered[ $basename ] = array(
			'file'        => $plugin_file,
			'slug'        => $slug,
			'version'     => $version,
			'license_key' => $license_key,
		);

		// Only wire hooks on the FIRST registration — otherwise every Pro
		// plugin re-adds the same filters.
		if ( count( self::$registered ) === 1 ) {
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_updates' ) );
			add_filter( 'plugins_api', array( __CLASS__, 'inject_plugin_info' ), 10, 3 );
			add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_extracted_dir' ), 10, 4 );
		}
	}

	/**
	 * Hook: WP asks us if there are updates. We add entries for any of our
	 * registered plugins whose remote version is newer than the local one.
	 *
	 * @param object $transient WP transient object (has ->response, ->no_update).
	 * @return object
	 */
	public static function inject_updates( $transient ) {
		if ( ! isset( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		foreach ( self::$registered as $basename => $reg ) {
			$remote = self::fetch_remote( $reg['slug'], $reg['license_key'] );
			if ( ! $remote ) continue;

			$remote_version = $remote['version'] ?? '';
			if ( $remote_version === '' ) continue;

			$obj = (object) array(
				'id'           => $basename,
				'slug'         => dirname( $basename ),
				'plugin'       => $basename,
				'new_version'  => $remote_version,
				'url'          => $remote['homepage'] ?? '',
				'package'      => $remote['download_url'] ?? '',
				'icons'        => isset( $remote['icons'] ) ? (array) $remote['icons'] : array(),
				'banners'      => isset( $remote['banners'] ) ? (array) $remote['banners'] : array(),
				'tested'       => $remote['tested'] ?? '',
				'requires_php' => $remote['requires_php'] ?? '',
				'compatibility'=> new \stdClass(),
			);

			if ( version_compare( $reg['version'], $remote_version, '<' ) ) {
				$transient->response[ $basename ]  = $obj;
				unset( $transient->no_update[ $basename ] );
			} else {
				$transient->no_update[ $basename ] = $obj;
				unset( $transient->response[ $basename ] );
			}
		}
		return $transient;
	}

	/**
	 * Hook: user clicked "View version details" — return full plugin_information.
	 *
	 * @param false|object|array $result Passthrough from earlier filters (usually false).
	 * @param string             $action WP action (only handle 'plugin_information').
	 * @param object             $args   Args from the caller (has ->slug).
	 * @return false|object
	 */
	public static function inject_plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) return $result;
		if ( ! isset( $args->slug ) ) return $result;

		foreach ( self::$registered as $basename => $reg ) {
			if ( dirname( $basename ) !== $args->slug ) continue;
			$remote = self::fetch_remote( $reg['slug'], $reg['license_key'] );
			if ( ! $remote ) return $result;

			return (object) array(
				'name'          => $remote['name'] ?? $args->slug,
				'slug'          => $args->slug,
				'version'       => $remote['version'] ?? '',
				'author'        => $remote['author'] ?? 'chgold',
				'homepage'      => $remote['homepage'] ?? '',
				'requires'      => $remote['requires'] ?? '',
				'tested'        => $remote['tested'] ?? '',
				'requires_php'  => $remote['requires_php'] ?? '',
				'last_updated'  => $remote['last_updated'] ?? '',
				'sections'      => isset( $remote['sections'] ) ? (array) $remote['sections'] : array(),
				'download_link' => $remote['download_url'] ?? '',
			);
		}
		return $result;
	}

	/**
	 * WP's upgrader extracts the ZIP into a temp folder named after the ZIP
	 * (e.g. elementor-ai-connect-1.3.1). Rename back to the original plugin
	 * folder (elementor-ai-connect) so activation state is preserved instead
	 * of duplicated as a new plugin.
	 */
	public static function rename_extracted_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) ) return $source;

		$target_dir = dirname( plugin_basename( $hook_extra['plugin'] ) );
		if ( ! isset( self::$registered[ $hook_extra['plugin'] ] ) ) return $source;

		$new_source = trailingslashit( $remote_source ) . $target_dir . '/';
		if ( $wp_filesystem && $source !== $new_source && $wp_filesystem->move( $source, $new_source ) ) {
			return $new_source;
		}
		return $source;
	}

	/**
	 * Fetch metadata JSON from PM. Cached in a transient per-slug for CACHE_TTL.
	 * Fail-silent: returns null on any error so WP admin doesn't break.
	 *
	 * @param string      $slug        PM plugin slug.
	 * @param string|null $license_key Sent as query param for gated downloads.
	 * @return array|null
	 */
	private static function fetch_remote( $slug, $license_key ) {
		$cache_key = 'goldtwmcp_update_meta_' . md5( $slug );
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return $cached;
		}

		$endpoint = defined( 'GOLDTWMCP_UPDATE_ENDPOINT' )
			? GOLDTWMCP_UPDATE_ENDPOINT
			: self::DEFAULT_ENDPOINT;
		$url = add_query_arg(
			array_filter( array(
				'slug'    => $slug,
				'license' => $license_key,
				'domain'  => strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) ),
			) ),
			$endpoint
		);

		$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) ) return null;
		if ( wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;

		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) return null;

		set_site_transient( $cache_key, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Force a re-check on demand (e.g. from a "Check for updates now" button).
	 *
	 * @param string $slug Optional — clear one slug's cache. Empty = all.
	 * @return void
	 */
	public static function clear_cache( $slug = '' ) {
		if ( $slug !== '' ) {
			delete_site_transient( 'goldtwmcp_update_meta_' . md5( $slug ) );
			return;
		}
		foreach ( self::$registered as $reg ) {
			delete_site_transient( 'goldtwmcp_update_meta_' . md5( $reg['slug'] ) );
		}
		delete_site_transient( 'update_plugins' );
	}
}
