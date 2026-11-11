<?php
/**
 * PeeStream DooPlay Auto-Migrator
 * File: includes/class-peestream-migrator.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PeeStream_Migrator {

	private $options;

	// Known competitor video hoster regex patterns
	private $competitor_patterns = array(
		'mixdrop'    => '(?:mixdrop\.(?:co|to|sx|bz|ch|ag|vc|gl))',
		'doodstream' => '(?:dood\.(?:wf|so|pm|to|ws|sh|cx|la|li)|doodstream\.com|ds2play\.com|dooodster\.com)',
		'streamtape' => '(?:streamtape\.(?:com|net|to|site|xyz))',
		'filemoon'   => '(?:filemoon\.(?:sx|to|me|nl|top))',
		'streamwish' => '(?:streamwish\.(?:to|com)|wishembed\.pro|wishfast\.top)',
		'vidguard'   => '(?:vidguard\.(?:to|net)|vgfplay\.com|vembed\.net)',
		'voe'        => '(?:voe\.sx|reputationsheriffgrey\.com)',
		'upstream'   => '(?:upstream\.(?:to|com))',
		'vidoza'     => '(?:vidoza\.(?:net|org|co))',
	);

	public function __construct() {
		$this->options = get_option( 'peestream_settings', array() );

		add_action( 'admin_menu', array( $this, 'register_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_migrator_assets' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_peestream_migrator_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_peestream_migrator_process_batch', array( $this, 'ajax_process_batch' ) );
	}

	public function register_submenu() {
		add_submenu_page(
			'peestream-settings',
			__( 'DooPlay Auto-Migrator', 'peestream-wp-dooplay' ),
			__( 'Auto-Migrator', 'peestream-wp-dooplay' ),
			'manage_options',
			'peestream-migrator',
			array( $this, 'render_migrator_page' )
		);
	}

	public function enqueue_migrator_assets( $hook ) {
		if ( strpos( $hook, 'peestream-migrator' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'peestream-admin-css',
			PEESTREAM_PLUGIN_URL . 'assets/css/peestream-admin.css',
			array(),
			PEESTREAM_VERSION
		);

		wp_enqueue_script(
			'peestream-migrator-js',
			PEESTREAM_PLUGIN_URL . 'assets/js/peestream-migrator.js',
			array( 'jquery' ),
			PEESTREAM_VERSION,
			true
		);

		wp_localize_script(
			'peestream-migrator-js',
			'peestreamMigratorData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'peestream_migrator_nonce' ),
				'i18n'     => array(
					'scanning'     => __( 'Scanning DooPlay database for competitor links...', 'peestream-wp-dooplay' ),
					'migrating'    => __( 'Migrating video servers in background...', 'peestream-wp-dooplay' ),
					'completed'    => __( 'Migration process finished successfully!', 'peestream-wp-dooplay' ),
					'confirm_start'=> __( 'Are you sure you want to start the migration? This will process selected competitor links and queue them to PeeStream.', 'peestream-wp-dooplay' ),
				),
			)
		);
	}

	public function render_migrator_page() {
		$api_key = ! empty( $this->options['api_key'] ) ? $this->options['api_key'] : '';
		?>
		<div class="wrap peestream-wrap">
			<div class="peestream-header">
				<div class="peestream-header-brand">
					<img src="<?php echo esc_url( PEESTREAM_PLUGIN_URL . 'assets/images/peestream-logo.png' ); ?>" alt="PeeStream Logo" class="peestream-header-logo">
					<div>
						<h1><?php esc_html_e( 'DooPlay Competitor Auto-Migrator', 'peestream-wp-dooplay' ); ?></h1>
						<p class="peestream-tagline"><?php esc_html_e( 'Batch scan, transfer, and replace dead MixDrop, Doodstream & Streamtape players with high-speed PeeStream servers.', 'peestream-wp-dooplay' ); ?></p>
					</div>
				</div>
				<div class="peestream-header-badge"><?php esc_html_e( 'Mass Replacement Engine', 'peestream-wp-dooplay' ); ?></div>
			</div>

			<?php if ( empty( $api_key ) ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Warning: Your PeeStream API Key is not configured. Please go to Settings and enter your API key first.', 'peestream-wp-dooplay' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="peestream-grid">
				<!-- Step 1: Scanner Card -->
				<div class="peestream-col">
					<div class="peestream-card">
						<h2><span class="dashicons dashicons-search"></span> <?php esc_html_e( '1. Scan DooPlay Repeatable Fields', 'peestream-wp-dooplay' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Scans all movies and TV episodes for embedded competitor hoster links.', 'peestream-wp-dooplay' ); ?></p>

						<div style="margin: 20px 0;">
							<button type="button" class="button button-primary button-hero" id="pst_btn_scan">
								<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Scan Entire Database Now', 'peestream-wp-dooplay' ); ?>
							</button>
						</div>

						<div id="pst_scan_loading" style="display:none;padding:15px;color:#64748b;">
							<span class="dashicons dashicons-update peestream-spin"></span> <?php esc_html_e( 'Analyzing database tables (wp_postmeta)...', 'peestream-wp-dooplay' ); ?>
						</div>

						<div id="pst_scan_results" style="display:none;margin-top:20px;">
							<h3><?php esc_html_e( 'Competitor Links Breakdown', 'peestream-wp-dooplay' ); ?></h3>
							<table class="peestream-mb-table widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Video Hoster', 'peestream-wp-dooplay' ); ?></th>
										<th><?php esc_html_e( 'Detected Players', 'peestream-wp-dooplay' ); ?></th>
										<th><?php esc_html_e( 'Status', 'peestream-wp-dooplay' ); ?></th>
									</tr>
								</thead>
								<tbody id="pst_scan_table_body"></tbody>
							</table>
							<p><strong><?php esc_html_e( 'Total Posts Found:', 'peestream-wp-dooplay' ); ?> <span id="pst_total_posts_found" class="peestream-badge-count">0</span></strong></p>
						</div>
					</div>
				</div>

				<!-- Step 2: Migration Settings & Action -->
				<div class="peestream-col">
					<div class="peestream-card">
						<h2><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( '2. Migration Configuration & Execution', 'peestream-wp-dooplay' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Configure how newly encoded PeeStream players are attached to your posts.', 'peestream-wp-dooplay' ); ?></p>

						<table class="form-table">
							<tr>
								<th scope="row"><label for="pst_migration_mode"><?php esc_html_e( 'Migration Strategy', 'peestream-wp-dooplay' ); ?></label></th>
								<td>
									<select id="pst_migration_mode" class="widefat">
										<option value="replace"><?php esc_html_e( 'Replace competitor player in-place with PeeStream', 'peestream-wp-dooplay' ); ?></option>
										<option value="primary" selected><?php esc_html_e( 'Set PeeStream as Primary (Server 1) & keep competitor as fallback', 'peestream-wp-dooplay' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Primary mode places PeeStream on top of player tabs while keeping old players intact.', 'peestream-wp-dooplay' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="pst_filter_hoster"><?php esc_html_e( 'Target Hoster', 'peestream-wp-dooplay' ); ?></label></th>
								<td>
									<select id="pst_filter_hoster" class="widefat">
										<option value="all"><?php esc_html_e( 'All Detected Hosters (MixDrop, Dood, Streamtape, etc.)', 'peestream-wp-dooplay' ); ?></option>
										<option value="mixdrop">MixDrop</option>
										<option value="doodstream">Doodstream</option>
										<option value="streamtape">Streamtape</option>
										<option value="filemoon">Filemoon</option>
										<option value="streamwish">Streamwish</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="pst_batch_size"><?php esc_html_e( 'Batch Size per Request', 'peestream-wp-dooplay' ); ?></label></th>
								<td>
									<input type="number" id="pst_batch_size" value="5" min="1" max="20" class="small-text">
									<span class="description"><?php esc_html_e( 'Number of posts processed per AJAX cycle (avoids timeouts).', 'peestream-wp-dooplay' ); ?></span>
								</td>
							</tr>
						</table>

						<div style="margin:25px 0 10px 0;">
							<button type="button" class="button button-primary button-large" id="pst_btn_start_migration" disabled>
								<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Start Batch Migration', 'peestream-wp-dooplay' ); ?>
							</button>
						</div>

						<!-- Progress Bar -->
						<div id="pst_progress_container" style="display:none;margin-top:20px;">
							<div style="display:flex;justify-content:space-between;font-weight:600;font-size:12px;margin-bottom:6px;">
								<span id="pst_progress_status"><?php esc_html_e( 'Processing...', 'peestream-wp-dooplay' ); ?></span>
								<span id="pst_progress_percent">0%</span>
							</div>
							<div style="background:#e2e8f0;height:12px;border-radius:6px;overflow:hidden;">
								<div id="pst_progress_bar" style="background:#0284c7;width:0%;height:100%;transition:width 0.3s ease;"></div>
							</div>
						</div>

						<!-- Live Console Output -->
						<div style="margin-top:20px;">
							<label><strong><?php esc_html_e( 'Live Migration Console:', 'peestream-wp-dooplay' ); ?></strong></label>
							<div id="pst_migration_console" style="background:#0f172a;color:#38bdf8;font-family:monospace;font-size:11px;padding:12px 14px;border-radius:6px;height:160px;overflow-y:auto;margin-top:6px;line-height:1.5;">
								<?php esc_html_e( '[Ready] Press Scan to find candidate videos.', 'peestream-wp-dooplay' ); ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_scan() {
		check_ajax_referer( 'peestream_migrator_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		global $wpdb;

		// Find post_ids that contain repeatable_fields
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'repeatable_fields' AND meta_value != ''",
			ARRAY_A
		);

		$hoster_counts = array_fill_keys( array_keys( $this->competitor_patterns ), 0 );
		$candidate_posts = array();

		foreach ( $rows as $r ) {
			$post_id = (int) $r['post_id'];
			$meta    = maybe_unserialize( $r['meta_value'] );

			if ( ! is_array( $meta ) ) {
				continue;
			}

			$has_competitor = false;
			foreach ( $meta as $server ) {
				$url = isset( $server['url'] ) ? $server['url'] : '';
				if ( empty( $url ) ) {
					continue;
				}

				foreach ( $this->competitor_patterns as $h_key => $pattern ) {
					if ( preg_match( '#' . $pattern . '#i', $url ) ) {
						$hoster_counts[ $h_key ]++;
						$has_competitor = true;
					}
				}
			}

			if ( $has_competitor ) {
				$candidate_posts[] = $post_id;
			}
		}

		$candidate_posts = array_unique( $candidate_posts );

		wp_send_json_success( array(
			'hoster_counts' => $hoster_counts,
			'total_posts'   => count( $candidate_posts ),
			'post_ids'      => array_values( $candidate_posts ),
		) );
	}

	public function ajax_process_batch() {
		check_ajax_referer( 'peestream_migrator_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array();
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'primary';
		$target_h = isset( $_POST['target_hoster'] ) ? sanitize_key( $_POST['target_hoster'] ) : 'all';

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No post IDs provided for processing.', 'peestream-wp-dooplay' ) ) );
		}

		$api_key = ! empty( $this->options['api_key'] ) ? $this->options['api_key'] : '';
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'PeeStream API key is not configured.', 'peestream-wp-dooplay' ) ) );
		}

		$api_domain    = ! empty( $this->options['api_domain'] ) ? rtrim( $this->options['api_domain'], '/' ) : 'https://peestream.com';
		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$fld_id        = isset( $this->options['default_folder_id'] ) ? absint( $this->options['default_folder_id'] ) : 0;
		$server_name   = ! empty( $this->options['default_server_name'] ) ? $this->options['default_server_name'] : 'PeeStream HD';

		$logs = array();
		$processed_count = 0;

		foreach ( $post_ids as $p_id ) {
			$title = get_the_title( $p_id );
			$meta = get_post_meta( $p_id, 'repeatable_fields', true );
			if ( is_string( $meta ) ) {
				$meta = maybe_unserialize( $meta );
			}
			if ( ! is_array( $meta ) ) {
				continue;
			}

			$modified = false;
			$new_servers_to_add = array();

			foreach ( $meta as $idx => &$srv ) {
				$url = isset( $srv['url'] ) ? $srv['url'] : '';
				if ( empty( $url ) ) continue;

				// Skip if already PeeStream
				if ( stripos( $url, 'peeplayer.com' ) !== false || stripos( $url, 'peestream.com' ) !== false ) {
					continue;
				}

				// Check match against patterns
				$matches_hoster = false;
				foreach ( $this->competitor_patterns as $h_key => $pat ) {
					if ( 'all' === $target_h || $h_key === $target_h ) {
						if ( preg_match( '#' . $pat . '#i', $url ) ) {
							$matches_hoster = true;
							break;
						}
					}
				}

				if ( ! $matches_hoster ) {
					continue;
				}

				// Call PeeStream Remote Upload API
				$endpoint = $api_domain . '/api/upload/url?' . http_build_query( array(
					'key'       => $api_key,
					'url'       => $url,
					'new_title' => $title,
					'fld_id'    => $fld_id,
				) );

				$res = wp_remote_get( $endpoint, array( 'timeout' => 20, 'sslverify' => true ) );
				if ( is_wp_error( $res ) ) {
					$logs[] = sprintf( "[ERROR] Post #%d: Network failure: %s", $p_id, $res->get_error_message() );
					continue;
				}

				$data = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( ! $data || ( isset( $data['status'] ) && 200 !== (int) $data['status'] ) ) {
					$err_msg = isset( $data['msg'] ) ? $data['msg'] : 'API error';
					$logs[] = sprintf( "[FAIL] Post #%d: Could not queue %s (%s)", $p_id, esc_html( $url ), esc_html( $err_msg ) );
					continue;
				}

				$filecode = isset( $data['result']['filecode'] ) ? $data['result']['filecode'] : '';
				if ( empty( $filecode ) ) {
					$logs[] = sprintf( "[FAIL] Post #%d: Empty filecode returned.", $p_id );
					continue;
				}

				$new_url = $player_domain . '/e/' . $filecode;

				if ( 'replace' === $mode ) {
					$srv['url'] = $new_url;
					$srv['name'] = $server_name;
					$modified = true;
					$logs[] = sprintf( "[REPLACED] Post #%d: In-place replaced with %s", $p_id, $filecode );
				} else {
					// Primary mode
					$new_servers_to_add[] = array(
						'name'    => $server_name,
						'select'  => 'iframe',
						'idioma'  => isset( $srv['idioma'] ) ? $srv['idioma'] : 'English',
						'quality' => isset( $srv['quality'] ) ? $srv['quality'] : '1080p',
						'url'     => $new_url,
					);
					$modified = true;
					$logs[] = sprintf( "[PRIMARY] Post #%d: Attached PeeStream %s as Server 1", $p_id, $filecode );
				}
			}
			unset( $srv );

			if ( $modified ) {
				if ( ! empty( $new_servers_to_add ) ) {
					// Prepend new PeeStream servers to beginning
					$meta = array_merge( $new_servers_to_add, $meta );
				}
				$meta = array_values( $meta );
				update_post_meta( $p_id, 'repeatable_fields', $meta );
				$processed_count++;
			}
		}

		wp_send_json_success( array(
			'processed_count' => $processed_count,
			'logs'            => $logs,
		) );
	}
}
