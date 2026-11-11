<?php
/**
 * Plugin Name: PeeStream Video Player & DooPlay Integration
 * Plugin URI: https://peestream.com
 * Description: Official PeeStream WordPress plugin with native DooPlay theme player integration, remote URL importer, auto-migrator for competitor hosters, and webhook auto-publish receiver.
 * Version: 1.0.0
 * Author: PeeStream Team
 * Author URI: https://peestream.com
 * License: GPLv2 or later
 * Text Domain: peestream-wp-dooplay
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PEESTREAM_VERSION', '1.0.0' );
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
	}

	private function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		
		// Initialize REST API Webhook receiver
		new PeeStream_Webhook();

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
		$default_options = array(
			'api_key'           => '',
			'player_domain'     => 'https://peeplayer.com',
			'api_domain'        => 'https://peestream.com',
			'default_folder_id' => '0',
			'default_server_name' => 'PeeStream Player',
			'default_language'  => 'English',
			'default_quality'   => '1080p',
			'webhook_secret'    => wp_generate_password( 24, false ),
			'auto_create_posts' => '1',
			'default_post_type' => 'movies',
		);

		if ( false === get_option( 'peestream_settings' ) ) {
			add_option( 'peestream_settings', $default_options );
		}
	}

	public static function deactivate() {
		// Flush temporary rewrite rules or transients if needed
	}
}

register_activation_hook( __FILE__, array( 'PeeStream_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PeeStream_Plugin', 'deactivate' ) );

function peestream_run() {
	return PeeStream_Plugin::get_instance();
}

peestream_run();
