<?php
/**
 * Plugin Name: PeeStream Video Player & DooPlay Integration
 * Plugin URI: https://peestream.com
 * Description: Official PeeStream WordPress plugin with native DooPlay theme player integration, remote URL importer, auto-migrator for competitor hosters, and webhook auto-publish receiver.
 * Version: 1.0.2
 * Author: PeeStream Team
 * Author URI: https://peestream.com
 * License: GPLv2 or later
 * Text Domain: peestream-wp-dooplay
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEESTREAM_VERSION', '1.0.2' );
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

	const DB_SCHEMA_VERSION = 103;

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

		// 2. Migration queue table (stores mode, server name, language, quality, and retry tracking)
		$table_queue = $wpdb->prefix . 'peestream_migration_queue';
		$sql_queue = "CREATE TABLE {$table_queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			original_url varchar(500) NOT NULL,
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
			PRIMARY KEY  (id),
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

		// Strict verification of table creation
		$has_webhooks = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_webhooks}'" ) === $table_webhooks );
		$has_queue    = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_queue}'" ) === $table_queue );
		$has_backups  = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_backups}'" ) === $table_backups );

		if ( ! $has_webhooks || ! $has_queue || ! $has_backups ) {
			return false;
		}

		// Schema migration: ensure mode column exists in backups table and has DEFAULT 'unknown'
		$has_mode_col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_backups}` LIKE 'mode'" );
		if ( empty( $has_mode_col ) ) {
			$wpdb->query( "ALTER TABLE `{$table_backups}` ADD COLUMN `mode` varchar(20) NOT NULL DEFAULT 'unknown' AFTER `new_code`" );
		} else {
			// Enforce default 'unknown' so MySQL doesn't assign automatic 'replace'
			$wpdb->query( "ALTER TABLE `{$table_backups}` MODIFY COLUMN `mode` varchar(20) NOT NULL DEFAULT 'unknown'" );
		}

		// Sanitize legacy backup records:
		// If migrating from earlier schemas (<103) where mode might have been set to 'replace' automatically,
		// or if mode is empty/null, reset to 'unknown' unless verified.
		$wpdb->query( "UPDATE `{$table_backups}` SET `mode` = 'unknown' WHERE `mode` = '' OR `mode` IS NULL" );
		if ( $from_schema < 103 && $from_schema > 0 ) {
			// Older versions prior to schema 103 may have had 'replace' automatically assigned by dbDelta/ALTER.
			// Mark historical backups as 'unknown' so surgical rollback verifies actual presence of original server.
			$wpdb->query( "UPDATE `{$table_backups}` SET `mode` = 'unknown' WHERE `mode` = 'replace' AND `backup_time` < '" . current_time( 'mysql' ) . "'" );
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

peestream_run();
