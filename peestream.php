<?php
/**
 * Plugin Name: PeeStream Video Player & DooPlay Integration
 * Plugin URI: https://peestream.com
 * Description: Official PeeStream WordPress plugin with native DooPlay theme player integration, remote URL importer, auto-migrator for competitor hosters, and webhook auto-publish receiver.
 * Version: 1.0.3
 * Author: PeeStream Team
 * Author URI: https://peestream.com
 * License: GPLv2 or later
 * Text Domain: peestream-wp-dooplay
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEESTREAM_VERSION', '1.0.3' );
define( 'PEESTREAM_PLUGIN_FILE', __FILE__ );
define( 'PEESTREAM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PEESTREAM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

final class PeeStream_Plugin {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	private function load_dependencies() {
		require_once PEESTREAM_PLUGIN_DIR . 'includes/class-peestream-admin.php';
		require_once PEESTREAM_PLUGIN_DIR . 'includes/class-peestream-metabox.php';
		require_once PEESTREAM_PLUGIN_DIR . 'includes/class-peestream-migrator.php';
		require_once PEESTREAM_PLUGIN_DIR . 'includes/class-peestream-webhook.php';
		require_once PEESTREAM_PLUGIN_DIR . 'includes/class-peestream-shortcode.php';
	}

	private function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'check_db_upgrade' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron_scheduled' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );
		
		// Initialize REST API Webhook receiver and Universal Shortcode
		new PeeStream_Webhook();
		new PeeStream_Shortcode();

		// Register cron worker
		add_action( 'peestream_cron_process_queue', array( 'PeeStream_Migrator', 'cron_check_queue' ) );

		if ( is_admin() ) {
			new PeeStream_Admin();
			new PeeStream_Metabox();
			new PeeStream_Migrator();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'peestream-wp-dooplay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public static function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['peestream_two_minutes'] ) ) {
			$schedules['peestream_two_minutes'] = array(
				'interval' => 120,
				'display'  => __( 'Every Two Minutes (PeeStream)', 'peestream-wp-dooplay' ),
			);
		}
		return $schedules;
	}

	const DB_SCHEMA_VERSION = 105;

	public static function ensure_cron_scheduled() {
		if ( ! wp_next_scheduled( 'peestream_cron_process_queue' ) ) {
			wp_schedule_event( time(), 'peestream_two_minutes', 'peestream_cron_process_queue' );
		}
	}

	public static function check_db_upgrade() {
		$installed_schema = (int) get_option( 'peestream_schema_version', 0 );
		if ( $installed_schema < self::DB_SCHEMA_VERSION ) {
			$schema_ok = self::create_tables( $installed_schema );
			if ( $schema_ok ) {
				update_option( 'peestream_schema_version', self::DB_SCHEMA_VERSION );
				update_option( 'peestream_db_version', PEESTREAM_VERSION );
			}
		}
		self::ensure_cron_scheduled();
	}

	public static function create_tables( $from_schema = 0 ) {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		}

		// 1. Webhook audit log table (dbDelta compliant: no IF NOT EXISTS, two spaces after PRIMARY KEY)
		$table_webhooks = $wpdb->prefix . 'peestream_webhooks';
		$sql_webhooks = "CREATE TABLE {$table_webhooks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_code varchar(64) NOT NULL,
			event varchar(32) NOT NULL DEFAULT 'video_ready',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip varchar(45) NOT NULL DEFAULT '',
			received_at datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'processed',
			PRIMARY KEY  (id),
			UNIQUE KEY uq_fc_event (file_code, event),
			KEY idx_ip_time (ip, received_at)
		) {$charset_collate};";
		dbDelta( $sql_webhooks );

		// 2. Migration queue table (stores mode, server name, language, quality, url_hash, owner_token, and retry tracking)
		$table_queue = $wpdb->prefix . 'peestream_migration_queue';
		$sql_queue = "CREATE TABLE {$table_queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			original_url varchar(500) NOT NULL,
			url_hash varchar(64) NOT NULL DEFAULT '',
			filecode varchar(64) NOT NULL DEFAULT '',
			mode varchar(20) NOT NULL DEFAULT 'replace',
			server_name varchar(100) NOT NULL DEFAULT 'PeeStream HD',
			language varchar(50) NOT NULL DEFAULT 'English',
			quality varchar(20) NOT NULL DEFAULT '1080p',
			state varchar(32) NOT NULL DEFAULT 'pending',
			retry_count int(11) NOT NULL DEFAULT 0,
			error_msg varchar(255) NOT NULL DEFAULT '',
			original_repeatable longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			owner_token varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY uq_post_urlhash (post_id, url_hash),
			KEY idx_post_id (post_id),
			KEY idx_status (status)
		) {$charset_collate};";
		dbDelta( $sql_queue );

		// 3. Pre-migration backup table (stores mode, fallback status, and rollback snapshots)
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';
		$sql_backups = "CREATE TABLE {$table_backups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			original_repeatable longtext,
			migrated_url varchar(500) NOT NULL DEFAULT '',
			new_code varchar(64) NOT NULL DEFAULT '',
			mode varchar(20) NOT NULL DEFAULT 'unknown',
			backup_time datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'completed',
			PRIMARY KEY  (id),
			KEY idx_post_id (post_id)
		) {$charset_collate};";
		dbDelta( $sql_backups );

		// 4. Archive table for safely preserving historical duplicates
		$table_archive = $wpdb->prefix . 'peestream_migration_queue_archive';
		$sql_archive = "CREATE TABLE {$table_archive} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			original_queue_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL,
			original_url varchar(500) NOT NULL,
			url_hash varchar(64) NOT NULL DEFAULT '',
			filecode varchar(64) NOT NULL DEFAULT '',
			mode varchar(20) NOT NULL DEFAULT 'replace',
			server_name varchar(100) NOT NULL DEFAULT 'PeeStream HD',
			language varchar(50) NOT NULL DEFAULT 'English',
			quality varchar(20) NOT NULL DEFAULT '1080p',
			state varchar(32) NOT NULL DEFAULT 'pending',
			retry_count int(11) NOT NULL DEFAULT 0,
			error_msg varchar(255) NOT NULL DEFAULT '',
			original_repeatable longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			archived_at datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			owner_token varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY idx_post_id (post_id)
		) {$charset_collate};";
		dbDelta( $sql_archive );

		// Strict verification of table creation
		$has_webhooks = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_webhooks}'" ) === $table_webhooks );
		$has_queue    = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_queue}'" ) === $table_queue );
		$has_backups  = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_backups}'" ) === $table_backups );
		$has_archive  = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_archive}'" ) === $table_archive );

		if ( ! $has_webhooks || ! $has_queue || ! $has_backups || ! $has_archive ) {
			return false;
		}

		// Column migrations on queue table
		$has_url_hash = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_queue}` LIKE 'url_hash'" );
		if ( empty( $has_url_hash ) ) {
			$wpdb->query( "ALTER TABLE `{$table_queue}` ADD COLUMN `url_hash` varchar(64) NOT NULL DEFAULT '' AFTER `original_url`" );
		}
		$has_owner_token = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_queue}` LIKE 'owner_token'" );
		if ( empty( $has_owner_token ) ) {
			$wpdb->query( "ALTER TABLE `{$table_queue}` ADD COLUMN `owner_token` varchar(64) NOT NULL DEFAULT '' AFTER `status`" );
		}

		// Ensure all existing rows have valid 64-char SHA-256 url_hash
		$wpdb->query( "UPDATE `{$table_queue}` SET `url_hash` = SHA2(`original_url`, 256) WHERE `url_hash` = '' OR `url_hash` IS NULL" );

		// Ensure full 256-bit binary-safe UNIQUE KEY uq_post_urlhash (archive duplicates safely first)
		$idx_urlhash = $wpdb->get_results( "SHOW INDEX FROM `{$table_queue}` WHERE Key_name = 'uq_post_urlhash'" );
		if ( empty( $idx_urlhash ) ) {
			// 1. Identify exact duplicate queue row IDs to be archived
			$dup_ids = $wpdb->get_col(
				"SELECT DISTINCT q1.id
				 FROM `{$table_queue}` q1
				 INNER JOIN `{$table_queue}` q2
				 WHERE q1.id < q2.id AND q1.post_id = q2.post_id 
				   AND ( (q1.url_hash != '' AND q1.url_hash = q2.url_hash) OR q1.original_url = q2.original_url )"
			);

			if ( ! empty( $dup_ids ) ) {
				$expected_count = count( $dup_ids );
				$id_placeholders = implode( ',', array_map( 'intval', $dup_ids ) );
				$now_utc = current_time( 'mysql', true );

				// 2. Archive duplicates preserving original_queue_id
				$ins_archive = $wpdb->query(
					"INSERT IGNORE INTO `{$table_archive}` 
					 (original_queue_id, post_id, original_url, url_hash, filecode, mode, server_name, language, quality, state, retry_count, error_msg, original_repeatable, created_at, updated_at, archived_at, status, owner_token)
					 SELECT q.id, q.post_id, q.original_url, q.url_hash, q.filecode, q.mode, q.server_name, q.language, q.quality, q.state, q.retry_count, q.error_msg, q.original_repeatable, q.created_at, q.updated_at, '{$now_utc}', q.status, q.owner_token
					 FROM `{$table_queue}` q
					 WHERE q.id IN ({$id_placeholders})"
				);

				if ( false === $ins_archive ) {
					// Archiving failed: ABORT migration immediately! Do NOT delete any rows!
					return false;
				}

				// 3. Strictly verify that all duplicate rows are now safely stored in the archive table
				$archived_ids = $wpdb->get_col(
					"SELECT DISTINCT original_queue_id FROM `{$table_archive}` WHERE original_queue_id IN ({$id_placeholders})"
				);
				$archived_count = is_array( $archived_ids ) ? count( $archived_ids ) : 0;

				if ( $archived_count !== $expected_count ) {
					// Archive count mismatch: ABORT migration! Original rows remain untouched!
					return false;
				}

				// 4. Verification confirmed: Safely delete only the archived duplicate rows from active queue
				$del_res = $wpdb->query(
					"DELETE FROM `{$table_queue}` WHERE id IN ({$id_placeholders})"
				);

				if ( false === $del_res ) {
					return false;
				}
			}

			// Drop legacy prefix-truncated index if present
			$wpdb->query( "ALTER TABLE `{$table_queue}` DROP INDEX uq_post_orig" );
			$res_idx = $wpdb->query( "ALTER TABLE `{$table_queue}` ADD UNIQUE KEY uq_post_urlhash (post_id, url_hash)" );
			if ( false === $res_idx ) {
				return false;
			}
		}

		// Schema migration: ensure mode column exists in backups table and has DEFAULT 'unknown'
		$has_mode_col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_backups}` LIKE 'mode'" );
		if ( empty( $has_mode_col ) ) {
			$res_alter = $wpdb->query( "ALTER TABLE `{$table_backups}` ADD COLUMN `mode` varchar(20) NOT NULL DEFAULT 'unknown' AFTER `new_code`" );
			if ( false === $res_alter ) {
				return false;
			}
		} else {
			// Enforce default 'unknown' so MySQL doesn't assign automatic 'replace'
			$res_mod = $wpdb->query( "ALTER TABLE `{$table_backups}` MODIFY COLUMN `mode` varchar(20) NOT NULL DEFAULT 'unknown'" );
			if ( false === $res_mod ) {
				return false;
			}
		}

		// Sanitize legacy backup records:
		// If migrating from earlier schemas (<103) where mode might have been set to 'replace' automatically,
		// or if mode is empty/null, reset to 'unknown' unless verified.
		$wpdb->query( "UPDATE `{$table_backups}` SET `mode` = 'unknown' WHERE `mode` = '' OR `mode` IS NULL" );
		if ( $from_schema < 103 && $from_schema > 0 ) {
			$wpdb->query( "UPDATE `{$table_backups}` SET `mode` = 'unknown' WHERE `mode` = 'replace' AND `backup_time` < '" . current_time( 'mysql', true ) . "'" );
		}

		// Strict verification of all mandatory columns and UNIQUE indexes across all tables
		$expected_queue_cols = array( 'id', 'post_id', 'original_url', 'url_hash', 'filecode', 'mode', 'server_name', 'language', 'quality', 'state', 'retry_count', 'error_msg', 'original_repeatable', 'created_at', 'updated_at', 'status', 'owner_token' );
		$actual_queue_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_queue}`" );
		foreach ( $expected_queue_cols as $c ) {
			if ( ! in_array( $c, (array) $actual_queue_cols, true ) ) {
				return false;
			}
		}

		$expected_webhooks_cols = array( 'id', 'file_code', 'event', 'post_id', 'ip', 'received_at', 'status' );
		$actual_webhooks_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_webhooks}`" );
		foreach ( $expected_webhooks_cols as $c ) {
			if ( ! in_array( $c, (array) $actual_webhooks_cols, true ) ) {
				return false;
			}
		}

		$expected_backups_cols = array( 'id', 'post_id', 'original_repeatable', 'migrated_url', 'new_code', 'mode', 'backup_time', 'status' );
		$actual_backups_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_backups}`" );
		foreach ( $expected_backups_cols as $c ) {
			if ( ! in_array( $c, (array) $actual_backups_cols, true ) ) {
				return false;
			}
		}

		$expected_archive_cols = array( 'id', 'original_queue_id', 'post_id', 'original_url', 'url_hash', 'filecode', 'mode', 'server_name', 'language', 'quality', 'state', 'retry_count', 'error_msg', 'original_repeatable', 'created_at', 'updated_at', 'archived_at', 'status', 'owner_token' );
		$actual_archive_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_archive}`" );
		foreach ( $expected_archive_cols as $c ) {
			if ( ! in_array( $c, (array) $actual_archive_cols, true ) ) {
				return false;
			}
		}

		$wh_idx = $wpdb->get_results( "SHOW INDEX FROM `{$table_webhooks}` WHERE Key_name = 'uq_fc_event'" );
		if ( empty( $wh_idx ) ) {
			return false;
		}
		$q_idx = $wpdb->get_results( "SHOW INDEX FROM `{$table_queue}` WHERE Key_name = 'uq_post_urlhash'" );
		if ( empty( $q_idx ) ) {
			return false;
		}

		return true;
	}

	public static function activate() {
		$default_options = array(
			'api_key'             => '',
			'player_domain'       => 'https://peeplayer.com',
			'api_domain'          => 'https://peestream.com',
			'default_folder_id'   => '0',
			'default_server_name' => 'PeeStream Player',
			'default_language'    => 'English',
			'default_quality'     => '1080p',
			'webhook_secret'      => wp_generate_password( 24, false ),
			'auto_create_posts'   => '0', // Default OFF per security review P0
			'default_post_type'   => 'movies',
		);

		if ( false === get_option( 'peestream_settings' ) ) {
			add_option( 'peestream_settings', $default_options );
		}

		$schema_ok = self::create_tables( 0 );
		if ( $schema_ok ) {
			update_option( 'peestream_schema_version', self::DB_SCHEMA_VERSION );
			update_option( 'peestream_db_version', PEESTREAM_VERSION );
		}

		// Schedule automated background queue processing cron
		if ( ! wp_next_scheduled( 'peestream_cron_process_queue' ) ) {
			wp_schedule_event( time(), 'peestream_two_minutes', 'peestream_cron_process_queue' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'peestream_cron_process_queue' );
		delete_transient( 'peestream_queue_cron_lock' );
		delete_transient( 'peestream_api_account_info' );
	}
}

register_activation_hook( __FILE__, array( 'PeeStream_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PeeStream_Plugin', 'deactivate' ) );

function peestream_run() {
	return PeeStream_Plugin::get_instance();
}

if ( ! function_exists( 'peestream_url_hash' ) ) {
	function peestream_url_hash( $url ) {
		return hash( 'sha256', trim( (string) $url ) );
	}
}

peestream_run();
