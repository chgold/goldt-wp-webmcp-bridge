<?php
/**
 * OAuth database schema manager class file.
 *
 * @package GoldtWebMCP
 */

namespace GoldtWebMCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Database Schema Manager
 */
class Database {

	/**
	 * Get current database version
	 */
	public static function get_version() {
		return get_option( 'goldtwmcp_oauth_db_version', '0.0.0' );
	}

	/**
	 * Set database version.
	 *
	 * @param string $version Version string to save.
	 * @return void
	 */
	public static function set_version( $version ) {
		update_option( 'goldtwmcp_oauth_db_version', $version );
	}

	/**
	 * Create OAuth tables
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_clients = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}goldtwmcp_oauth_clients (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id VARCHAR(80) NOT NULL,
            client_name VARCHAR(255) NOT NULL,
            client_type VARCHAR(20) NOT NULL DEFAULT 'public',
            redirect_uris TEXT DEFAULT NULL,
            allowed_scopes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) $charset_collate;";

		$sql_codes = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}goldtwmcp_oauth_codes (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(128) NOT NULL,
            client_id VARCHAR(80) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            redirect_uri VARCHAR(500) DEFAULT NULL,
            code_challenge VARCHAR(128) DEFAULT NULL,
            code_challenge_method VARCHAR(10) DEFAULT NULL,
            scopes TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY expires_at (expires_at),
            KEY client_id (client_id),
            KEY user_id (user_id)
        ) $charset_collate;";

		$sql_tokens = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}goldtwmcp_oauth_tokens (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(255) NOT NULL,
            refresh_token VARCHAR(255) DEFAULT NULL,
            client_id VARCHAR(80) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            scopes TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            refresh_token_expires_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            UNIQUE KEY refresh_token (refresh_token),
            KEY user_id (user_id),
            KEY client_id (client_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";

		$sql_token_registry = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aiconnect_token_registry (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token_prefix VARCHAR(16) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            client_id VARCHAR(80) NOT NULL,
            scope VARCHAR(255) NOT NULL,
            issued_at BIGINT(20) UNSIGNED NOT NULL,
            expires_at BIGINT(20) UNSIGNED NOT NULL,
            last_used_at BIGINT(20) UNSIGNED DEFAULT NULL,
            revoked_at BIGINT(20) UNSIGNED DEFAULT NULL,
            revoked_by BIGINT(20) UNSIGNED DEFAULT NULL,
            source ENUM('generator','oauth','refresh') NOT NULL DEFAULT 'oauth',
            ip_address VARCHAR(45) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY token_prefix (token_prefix),
            KEY user_id (user_id),
            KEY revoked_at (revoked_at)
        ) $charset_collate;";

		dbDelta( $sql_clients );
		dbDelta( $sql_codes );
		dbDelta( $sql_tokens );
		dbDelta( $sql_token_registry );

		self::insert_default_clients();
		self::set_version( '1.4.0' );
	}

	/**
	 * Check if all required OAuth tables exist
	 *
	 * @return bool True if all tables exist, false otherwise
	 */
	public static function tables_exist() {
		global $wpdb;

		$required_tables = array(
			$wpdb->prefix . 'goldtwmcp_oauth_clients',
			$wpdb->prefix . 'goldtwmcp_oauth_codes',
			$wpdb->prefix . 'goldtwmcp_oauth_tokens',
			$wpdb->prefix . 'aiconnect_token_registry',
		);

		foreach ( $required_tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking table existence
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Run database upgrades if needed
	 */
	public static function maybe_upgrade() {
		$current_version = self::get_version();

		if ( version_compare( $current_version, '1.1.0', '<' ) ) {
			self::upgrade_to_1_1_0();
		}

		if ( version_compare( $current_version, '1.2.0', '<' ) ) {
			self::upgrade_to_1_2_0();
		}

		if ( version_compare( $current_version, '1.3.0', '<' ) ) {
			self::upgrade_to_1_3_0();
		}

		if ( version_compare( $current_version, '1.4.0', '<' ) ) {
			self::upgrade_to_1_4_0();
		}

		if ( version_compare( $current_version, '2.0.0', '<' ) ) {
			self::upgrade_to_2_0_0();
		}

		if ( version_compare( $current_version, '2.0.1', '<' ) ) {
			self::upgrade_to_2_0_1();
		}

		if ( version_compare( $current_version, '2.0.2', '<' ) ) {
			self::upgrade_to_2_0_2();
		}

		if ( version_compare( $current_version, '2.0.3', '<' ) ) {
			self::upgrade_to_2_0_3();
		}

		if ( version_compare( $current_version, '2.0.4', '<' ) ) {
			self::upgrade_to_2_0_4();
		}

		if ( version_compare( $current_version, '2.0.5', '<' ) ) {
			self::upgrade_to_2_0_5();
		}
	}

	/**
	 * Upgrade to version 2.0.5 — rename OAuth client_id webmcp-master to goldnat-master.
	 *
	 * The 2.0.4 migration renamed the display name only. This one renames the
	 * identifier itself so the plugin exposes no "webmcp" string to AI agents,
	 * neither in the manifest nor in the OAuth authorize/token flows.
	 *
	 * Data touched:
	 *   1. oauth_clients               — the 'webmcp-master' row becomes 'goldnat-master'.
	 *   2. oauth_tokens                — every issued/refreshed token that referenced
	 *                                    the old client_id is re-pointed at the new one.
	 *   3. aiconnect_token_registry    — same re-point in the audit registry.
	 *
	 * Ordering matters. We insert the new 'goldnat-master' row BEFORE moving
	 * tokens so bearer-auth requests in flight always find a valid client.
	 * The old 'webmcp-master' row is deleted LAST, only after every token has
	 * been migrated away from it.
	 *
	 *   1. INSERT-IF-MISSING new client 'goldnat-master' (uses existing row's
	 *      data if the 2.0.4 migration already renamed the display name).
	 *   2. UPDATE oauth_tokens SET client_id='goldnat-master' WHERE
	 *      client_id='webmcp-master'.
	 *   3. UPDATE aiconnect_token_registry SET client_id='goldnat-master'
	 *      WHERE client_id='webmcp-master'.
	 *   4. DELETE FROM oauth_clients WHERE client_id='webmcp-master'.
	 *
	 * User impact: existing agents keep working — their tokens continue to
	 * validate because the client they reference (now 'goldnat-master')
	 * exists. Only agents that hard-coded the legacy client_id in NEW
	 * authorize requests get 'invalid_client' — they need to reconnect
	 * using 'goldnat-master'. Per the release note this is accepted
	 * behaviour; the alternative (permanent alias) leaks the legacy
	 * identifier forever.
	 *
	 * Idempotent:
	 *   - Step 1 no-ops when 'goldnat-master' already exists.
	 *   - Step 2/3 no-op when no rows still reference 'webmcp-master'.
	 *   - Step 4 no-ops when 'webmcp-master' row is gone.
	 * Re-running the migration on a fully-migrated site is safe.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_0_5() {
		global $wpdb;

		$clients_table  = $wpdb->prefix . 'goldtwmcp_oauth_clients';
		$tokens_table   = $wpdb->prefix . 'goldtwmcp_oauth_tokens';
		$registry_table = $wpdb->prefix . 'aiconnect_token_registry';

		// Step 1: ensure 'goldnat-master' exists in oauth_clients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
		$new_exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$clients_table} WHERE client_id = %s", 'goldnat-master' )
		);

		if ( ! $new_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
			$old = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$clients_table} WHERE client_id = %s", 'webmcp-master' ),
				ARRAY_A
			);

			if ( $old ) {
				// Reuse the existing row's config (scopes, redirect_uris, etc.),
				// just rebrand the identifier and display name.
				unset( $old['id'] );
				$old['client_id']   = 'goldnat-master';
				$old['client_name'] = 'Goldnat Master';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time migration.
				$wpdb->insert( $clients_table, $old );
			} else {
				// Neither identifier present — seed a fresh master client.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time migration.
				$wpdb->insert(
					$clients_table,
					array(
						'client_id'      => 'goldnat-master',
						'client_name'    => 'Goldnat Master',
						'client_type'    => 'public',
						'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
						'allowed_scopes' => wp_json_encode( array( 'read', 'write', 'delete', 'manage_users' ) ),
					)
				);
			}
		}

		// Step 2: migrate access + refresh tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
		$wpdb->update(
			$tokens_table,
			array( 'client_id' => 'goldnat-master' ),
			array( 'client_id' => 'webmcp-master' )
		);

		// Step 3: migrate audit registry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
		$wpdb->update(
			$registry_table,
			array( 'client_id' => 'goldnat-master' ),
			array( 'client_id' => 'webmcp-master' )
		);

		// Step 4: remove the legacy client row (now orphaned — every token
		// that referenced it has been repointed above).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
		$wpdb->delete(
			$clients_table,
			array( 'client_id' => 'webmcp-master' )
		);

		self::set_version( '2.0.5' );
	}

	/**
	 * Upgrade to version 2.0.4 — rebrand OAuth client display name.
	 *
	 * The 'webmcp-master' OAuth client was seeded (in 1.4.0 and again in
	 * insert_default_clients()) with client_name 'WebMCP Master'. That name
	 * is shown on the consent screen every time a user authorises this
	 * client, so it needed to move to the current 'Goldnat AI Connect /
	 * Servio Protocol' branding.
	 *
	 * The client_id itself stays 'webmcp-master' — it's referenced by
	 * existing OAuth authorization codes, access tokens, and refresh
	 * tokens, so renaming it would invalidate every active integration.
	 * Only the human-readable display name changes.
	 *
	 * Idempotent: the WHERE clause matches only rows still holding the
	 * legacy display name, so re-running the migration (or running it on
	 * a site an admin already patched by hand) is a no-op.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_0_4() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time OAuth client rename on upgrade.
		$wpdb->update(
			"{$wpdb->prefix}goldtwmcp_oauth_clients",
			array( 'client_name' => 'Goldnat Master' ),
			array(
				'client_id'   => 'webmcp-master',
				'client_name' => 'WebMCP Master',
			)
		);

		self::set_version( '2.0.4' );
	}

	/**
	 * Upgrade to version 1.4.0 - Seed the master default OAuth client.
	 *
	 * Historical: this used to seed client_id 'webmcp-master' with client_name
	 * 'WebMCP Master'. Sites that arrived at this upgrade path AFTER the
	 * rebrand pipeline (2.0.4 renamed the display name, 2.0.5 renamed the
	 * client_id itself) get the current name directly, so the identifier
	 * created here now is 'goldnat-master' / 'Goldnat Master'.
	 *
	 * Idempotent: only inserts when neither identifier is present, so sites
	 * that already have either 'goldnat-master' (post-migration) or
	 * 'webmcp-master' (pre-migration) are not touched. The 2.0.5 migration
	 * will consolidate them regardless.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_4_0() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth setup, runs once on upgrade.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id IN ( %s, %s )",
				'goldnat-master',
				'webmcp-master'
			)
		);

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth setup, inserting master client.
			$wpdb->insert(
				"{$wpdb->prefix}goldtwmcp_oauth_clients",
				array(
					'client_id'      => 'goldnat-master',
					'client_name'    => 'Goldnat Master',
					'client_type'    => 'public',
					'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
					'allowed_scopes' => wp_json_encode( array( 'read', 'write', 'delete', 'manage_users' ) ),
				)
			);
		}

		self::set_version( '1.4.0' );
	}

	/**
	 * Upgrade to version 1.3.0 - Add token registry sidecar table.
	 *
	 * Idempotent: uses dbDelta + IF NOT EXISTS so it is safe to run when an
	 * earlier partial install already created the table.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_3_0() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_token_registry = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aiconnect_token_registry (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token_prefix VARCHAR(16) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            client_id VARCHAR(80) NOT NULL,
            scope VARCHAR(255) NOT NULL,
            issued_at BIGINT(20) UNSIGNED NOT NULL,
            expires_at BIGINT(20) UNSIGNED NOT NULL,
            last_used_at BIGINT(20) UNSIGNED DEFAULT NULL,
            revoked_at BIGINT(20) UNSIGNED DEFAULT NULL,
            revoked_by BIGINT(20) UNSIGNED DEFAULT NULL,
            source ENUM('generator','oauth','refresh') NOT NULL DEFAULT 'oauth',
            ip_address VARCHAR(45) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY token_prefix (token_prefix),
            KEY user_id (user_id),
            KEY revoked_at (revoked_at)
        ) $charset_collate;";

		dbDelta( $sql_token_registry );

		self::ensure_token_registry_columns();

		self::set_version( '1.3.0' );
	}

	/**
	 * ALTER missing columns into the token registry table.
	 *
	 * Defensive: handles the case where a prior partial install created the
	 * table with a subset of the columns. dbDelta should normally cover this
	 * but the ENUM column in particular can be missed across MySQL/MariaDB
	 * variants, so we double-check explicitly.
	 *
	 * @return void
	 */
	private static function ensure_token_registry_columns() {
		global $wpdb;
		$table = $wpdb->prefix . 'aiconnect_token_registry';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; schema introspection.
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table built from $wpdb->prefix only.
		if ( ! is_array( $columns ) ) {
			return;
		}

		$names = array();
		foreach ( $columns as $col ) {
			if ( isset( $col['Field'] ) ) {
				$names[ $col['Field'] ] = true;
			}
		}

		$additions = array(
			'token_prefix' => 'ADD COLUMN token_prefix VARCHAR(16) NOT NULL AFTER id',
			'user_id'      => 'ADD COLUMN user_id BIGINT(20) UNSIGNED NOT NULL AFTER token_prefix',
			'client_id'    => 'ADD COLUMN client_id VARCHAR(80) NOT NULL AFTER user_id',
			'scope'        => 'ADD COLUMN scope VARCHAR(255) NOT NULL AFTER client_id',
			'issued_at'    => 'ADD COLUMN issued_at BIGINT(20) UNSIGNED NOT NULL AFTER scope',
			'expires_at'   => 'ADD COLUMN expires_at BIGINT(20) UNSIGNED NOT NULL AFTER issued_at',
			'last_used_at' => 'ADD COLUMN last_used_at BIGINT(20) UNSIGNED DEFAULT NULL AFTER expires_at',
			'revoked_at'   => 'ADD COLUMN revoked_at BIGINT(20) UNSIGNED DEFAULT NULL AFTER last_used_at',
			'revoked_by'   => 'ADD COLUMN revoked_by BIGINT(20) UNSIGNED DEFAULT NULL AFTER revoked_at',
			'source'       => "ADD COLUMN source ENUM('generator','oauth','refresh') NOT NULL DEFAULT 'oauth' AFTER revoked_by",
			'ip_address'   => 'ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL AFTER source',
		);

		foreach ( $additions as $column => $sql_fragment ) {
			if ( ! isset( $names[ $column ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema repair; fragment is a constant whitelist value.
				$wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ' . $sql_fragment ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table from $wpdb->prefix; $sql_fragment is a whitelisted constant string.
			}
		}
	}

	/**
	 * Upgrade to version 1.1.0 - Add refresh token support
	 */
	private static function upgrade_to_1_1_0() {
		global $wpdb;

		// Check if columns already exist.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema upgrade check
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}goldtwmcp_oauth_tokens LIKE 'refresh_token'" );

		if ( empty( $columns ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query(
				"ALTER TABLE {$wpdb->prefix}goldtwmcp_oauth_tokens
				ADD COLUMN refresh_token VARCHAR(255) DEFAULT NULL AFTER token,
				ADD COLUMN refresh_token_expires_at DATETIME DEFAULT NULL AFTER expires_at,
				ADD UNIQUE KEY refresh_token (refresh_token)"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		self::set_version( '1.1.0' );
	}

	/**
	 * Upgrade to version 1.2.0 - Add popular AI clients
	 */
	private static function upgrade_to_1_2_0() {
		global $wpdb;

		$new_clients = array(
			array(
				'client_id'      => 'grok',
				'client_name'    => 'Grok (xAI)',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'perplexity',
				'client_name'    => 'Perplexity AI',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'copilot',
				'client_name'    => 'Microsoft Copilot',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'meta-ai',
				'client_name'    => 'Meta AI (Facebook)',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'deepseek',
				'client_name'    => 'DeepSeek AI',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
		);

		foreach ( $new_clients as $client ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth setup, runs once on upgrade, no caching needed
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
					$client['client_id']
				)
			);

			if ( ! $exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth setup, inserting new AI clients
				$wpdb->insert(
					"{$wpdb->prefix}goldtwmcp_oauth_clients",
					$client
				);
			}
		}

		self::set_version( '1.2.0' );
	}

	/**
	 * Insert default OAuth clients (Claude, ChatGPT, etc.)
	 */
	private static function insert_default_clients() {
		global $wpdb;

		$clients = array(
			array(
				'client_id'      => 'goldnat-master',
				'client_name'    => 'Goldnat Master',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write', 'delete', 'manage_users' ) ),
			),
			array(
				'client_id'      => 'claude-ai',
				'client_name'    => 'Claude AI (Anthropic)',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'chatgpt',
				'client_name'    => 'ChatGPT (OpenAI)',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'gemini',
				'client_name'    => 'Gemini (Google)',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'grok',
				'client_name'    => 'Grok (xAI)',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'perplexity',
				'client_name'    => 'Perplexity AI',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'copilot',
				'client_name'    => 'Microsoft Copilot',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'meta-ai',
				'client_name'    => 'Meta AI (Facebook)',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
			array(
				'client_id'      => 'deepseek',
				'client_name'    => 'DeepSeek AI',
				'client_type'    => 'public',
				'redirect_uris'  => wp_json_encode( array( 'urn:ietf:wg:oauth:2.0:oob' ) ),
				'allowed_scopes' => wp_json_encode( array( 'read', 'write' ) ),
			),
		);

		foreach ( $clients as $client ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth setup, runs once on activation, no caching needed
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}goldtwmcp_oauth_clients WHERE client_id = %s",
					$client['client_id']
				)
			);

			if ( ! $exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- OAuth setup, inserting default clients
				$wpdb->insert(
					"{$wpdb->prefix}goldtwmcp_oauth_clients",
					$client
				);
			}
		}
	}

	/**
	 * Upgrade to version 2.0.0 — add forensic columns to token registry.
	 *
	 * Adds last_used_ip, last_used_ua, and refresh_expires_at.
	 * Idempotent: uses SHOW COLUMNS check before each ALTER.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_0_0() {
		global $wpdb;
		$table = $wpdb->prefix . 'aiconnect_token_registry';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; schema introspection.
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '`', ARRAY_A );
		if ( ! is_array( $columns ) ) {
			self::set_version( '2.0.0' );
			return;
		}

		$names = array();
		foreach ( $columns as $col ) {
			if ( isset( $col['Field'] ) ) {
				$names[ $col['Field'] ] = true;
			}
		}

		$new_columns = array(
			'last_used_ip'       => 'ADD COLUMN last_used_ip VARCHAR(45) NULL AFTER last_used_at',
			'last_used_ua'       => 'ADD COLUMN last_used_ua VARCHAR(255) NULL AFTER last_used_ip',
			'refresh_expires_at' => 'ADD COLUMN refresh_expires_at BIGINT(20) UNSIGNED NULL AFTER expires_at',
		);

		foreach ( $new_columns as $column => $sql_fragment ) {
			if ( ! isset( $names[ $column ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema upgrade; fragment is a whitelisted constant string.
				$wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ' . $sql_fragment );
			}
		}

		// Add last_used_at index if it doesn't exist.
		$indexes = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			'SHOW INDEX FROM `' . esc_sql( $table ) . '` WHERE Key_name = \'last_used_at\'',
			ARRAY_A
		);
		if ( empty( $indexes ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ADD INDEX last_used_at (last_used_at)' );
		}

		self::set_version( '2.0.0' );
	}

	/**
	 * Upgrade to version 2.0.1 — backfill refresh_expires_at from oauth_tokens.
	 *
	 * Joins the registry against oauth_tokens on token prefix to populate
	 * refresh_expires_at where it is currently NULL.
	 * Idempotent: WHERE clause limits to NULL rows only.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_0_1() {
		global $wpdb;

		$registry   = $wpdb->prefix . 'aiconnect_token_registry';
		$oauth_toks = $wpdb->prefix . 'goldtwmcp_oauth_tokens';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			"UPDATE `{$registry}` r
			 INNER JOIN `{$oauth_toks}` t ON LEFT(t.token, 16) = r.token_prefix
			 SET r.refresh_expires_at = UNIX_TIMESTAMP(t.refresh_token_expires_at)
			 WHERE r.refresh_expires_at IS NULL
			   AND t.refresh_token_expires_at IS NOT NULL"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		self::set_version( '2.0.1' );
	}

	/**
	 * Upgrade to version 2.0.2 — backfill registry rows for pre-existing oauth tokens.
	 *
	 * Any oauth_token that has no corresponding registry row (e.g. issued before
	 * the registry existed) gets a synthetic row so it appears in the UI.
	 * Idempotent: uses LEFT JOIN / IS NULL to find missing rows only.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_0_2() {
		global $wpdb;

		$registry   = $wpdb->prefix . 'aiconnect_token_registry';
		$oauth_toks = $wpdb->prefix . 'goldtwmcp_oauth_tokens';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			"INSERT INTO `{$registry}` (token_prefix, user_id, client_id, scope, issued_at, expires_at, refresh_expires_at, source, revoked_at, revoked_by)
			 SELECT
			   LEFT(t.token, 16),
			   t.user_id,
			   t.client_id,
			   '',
			   UNIX_TIMESTAMP(t.created_at),
			   UNIX_TIMESTAMP(t.expires_at),
			   UNIX_TIMESTAMP(t.refresh_token_expires_at),
			   'oauth',
			   CASE WHEN t.revoked_at IS NOT NULL THEN UNIX_TIMESTAMP(t.revoked_at) ELSE NULL END,
			   CASE WHEN t.revoked_at IS NOT NULL THEN 0 ELSE NULL END
			 FROM `{$oauth_toks}` t
			 LEFT JOIN `{$registry}` r ON LEFT(t.token, 16) = r.token_prefix
			 WHERE r.id IS NULL
			   AND UNIX_TIMESTAMP(t.created_at) > 0"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		self::set_version( '2.0.2' );
	}

	/**
	 * Upgrade to version 2.0.3 — auto-revoke legacy backfilled tokens.
	 *
	 * Tokens inserted by 2.0.2 (never used, no IP signature) are considered
	 * forgotten/leaked and are revoked as a security baseline. The cascade into
	 * oauth_tokens is handled by the registry revoke path.
	 *
	 * Identification of backfilled rows:
	 *   revoked_at IS NULL AND last_used_at IS NULL AND last_used_ip IS NULL
	 *   AND last_used_ua IS NULL AND source = 'oauth' AND revoked_by IS NULL
	 *   AND ip_address IS NULL
	 *
	 * Idempotent: only touches rows matching the backfill signature.
	 *
	 * @return void
	 */
	private static function upgrade_to_2_0_3() {
		global $wpdb;

		$registry   = $wpdb->prefix . 'aiconnect_token_registry';
		$oauth_toks = $wpdb->prefix . 'goldtwmcp_oauth_tokens';
		$now        = time();
		$now_mysql  = gmdate( 'Y-m-d H:i:s', $now );

		// Collect prefixes of legacy rows so we can cascade into oauth_tokens.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$prefixes = $wpdb->get_col(
			"SELECT token_prefix FROM `{$registry}`
			 WHERE revoked_at IS NULL
			   AND last_used_at IS NULL
			   AND last_used_ip IS NULL
			   AND last_used_ua IS NULL
			   AND ip_address IS NULL
			   AND source = 'oauth'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! empty( $prefixes ) ) {
			// Revoke in registry.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$registry}`
					 SET revoked_at = %d, revoked_by = 0
					 WHERE revoked_at IS NULL
					   AND last_used_at IS NULL
					   AND last_used_ip IS NULL
					   AND last_used_ua IS NULL
					   AND ip_address IS NULL
					   AND source = 'oauth'",
					$now
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Cascade into oauth_tokens.
			$safe_prefixes = array_filter(
				$prefixes,
				function ( $p ) {
					return is_string( $p ) && strlen( $p ) >= 4 && strlen( $p ) <= 32;
				}
			);

			if ( ! empty( $safe_prefixes ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $safe_prefixes ), '%s' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$oauth_toks}`
						 SET revoked_at = %s
						 WHERE revoked_at IS NULL
						   AND LEFT(token, 16) IN ({$placeholders})",
						array_merge( array( $now_mysql ), $safe_prefixes )
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}

		self::set_version( '2.0.3' );
	}

	/**
	 * Drop all OAuth tables
	 */
	public static function drop_tables() {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- OAuth cleanup on uninstall
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiconnect_token_registry" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- OAuth cleanup on uninstall
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}goldtwmcp_oauth_tokens" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- OAuth cleanup on uninstall
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}goldtwmcp_oauth_codes" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- OAuth cleanup on uninstall
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}goldtwmcp_oauth_clients" );

		delete_option( 'goldtwmcp_oauth_db_version' );
	}

	/**
	 * Clean expired codes and tokens
	 */
	public static function cleanup() {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth cleanup cron job
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}goldtwmcp_oauth_codes 
             WHERE expires_at < %s",
				current_time( 'mysql' )
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth cleanup cron job
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}goldtwmcp_oauth_tokens 
             WHERE expires_at < %s AND revoked_at IS NULL",
				current_time( 'mysql' )
			)
		);
	}
}
