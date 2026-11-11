<?php
/**
 * Plugin Name: PeeStream Video Player & DooPlay Integration
 * Plugin URI: https://peestream.com
 * Description: Official PeeStream WordPress plugin with native DooPlay theme player integration, remote URL importer, auto-migrator for competitor hosters, and webhook auto-publish receiver.
 * Version: 1.0.1
 * Author: PeeStream Team
 * Author URI: https://peestream.com
 * License: GPLv2 or later
 * Text Domain: peestream-wp-dooplay
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEESTREAM_VERSION', '1.0.1' );
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
		
		// Initialize REST API Webhook receiver and Universal Shortcode
		new PeeStream_Webhook();
		new PeeStream_Shortcode();

		if ( is_admin() ) {
			new PeeStream_Admin();
			new PeeStream_Metabox();
			new PeeStream_Migrator();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'peestream-wp-dooplay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public static function activate() {
		global $wpdb;

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

		// Create database tables for Webhook audits, Migration queue, and Backups
		$charset_collate = $wpdb->get_charset_collate();

		$table_webhooks = $wpdb->prefix . 'peestream_webhooks';
		$sql_webhooks = "CREATE TABLE IF NOT EXISTS {$table_webhooks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_code varchar(64) NOT NULL,
			event varchar(32) NOT NULL DEFAULT 'video_ready',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip varchar(45) NOT NULL DEFAULT '',
			received_at datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'processed',
			PRIMARY KEY (id),
			KEY idx_fc_event (file_code, event),
			KEY idx_ip_time (ip, received_at)
		) {$charset_collate};";

		$table_queue = $wpdb->prefix . 'peestream_migration_queue';
		$sql_queue = "CREATE TABLE IF NOT EXISTS {$table_queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			original_url varchar(500) NOT NULL,
			filecode varchar(64) NOT NULL DEFAULT '',
			state varchar(32) NOT NULL DEFAULT 'pending',
			original_repeatable longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'queued',
			PRIMARY KEY (id),
			KEY idx_post_id (post_id),
			KEY idx_status (status)
		) {$charset_collate};";

		$table_backups = $wpdb->prefix . 'peestream_migration_backups';
		$sql_backups = "CREATE TABLE IF NOT EXISTS {$table_backups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			original_repeatable longtext,
			migrated_url varchar(500) NOT NULL DEFAULT '',
			new_code varchar(64) NOT NULL DEFAULT '',
			backup_time datetime NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'completed',
			PRIMARY KEY (id),
			KEY idx_post_id (post_id)
		) {$charset_collate};";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql_webhooks );
		dbDelta( $sql_queue );
		dbDelta( $sql_backups );
	}

	public static function deactivate() {
		// Clean temporary transients
		delete_transient( 'peestream_api_account_info' );
	}
}

register_activation_hook( __FILE__, array( 'PeeStream_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PeeStream_Plugin', 'deactivate' ) );

function peestream_run() {
	return PeeStream_Plugin::get_instance();
}

peestream_run();
