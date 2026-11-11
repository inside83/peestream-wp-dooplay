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

	/**
	 * Confirmed working hosters on PeeStream remote upload
	 * Excludes hosters that fail due to captcha/SPA/Cloudflare (Doodstream, Filemoon, LuluStream)
	 */
	private $confirmed_hosters = array(
		'streamtape' => array(
			'name'  => 'Streamtape',
			'hosts' => array( 'streamtape.com', 'streamtape.net', 'streamtape.to', 'streamtape.site', 'streamtape.xyz' ),
		),
		'mixdrop'    => array(
			'name'  => 'MixDrop',
			'hosts' => array( 'mixdrop.co', 'mixdrop.to', 'mixdrop.sx', 'mixdrop.bz', 'mixdrop.ch', 'mixdrop.ag', 'mixdrop.vc', 'mixdrop.gl' ),
		),
		'voe'        => array(
			'name'  => 'VOE',
			'hosts' => array( 'voe.sx', 'reputationsheriffgrey.com' ),
		),
		'streamwish' => array(
			'name'  => 'StreamWish',
			'hosts' => array( 'streamwish.to', 'streamwish.com', 'wishembed.pro', 'wishfast.top' ),
		),
		'earnvids'   => array(
			'name'  => 'EarnVids / VidHide',
			'hosts' => array( 'earnvids.com', 'vidhide.com', 'vidhidepro.com', 'vidhidepre.com' ),
		),
		'pixeldrain' => array(
			'name'  => 'PixelDrain',
			'hosts' => array( 'pixeldrain.com' ),
		),
	);

	public function __construct() {
		$this->options = get_option( 'peestream_settings', array() );

		add_action( 'admin_menu', array( $this, 'register_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_migrator_assets' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_peestream_migrator_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_peestream_migrator_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_peestream_migrator_check_queue', array( $this, 'ajax_check_queue' ) );
		add_action( 'wp_ajax_peestream_migrator_rollback', array( $this, 'ajax_rollback' ) );
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
					'scanning'        => __( 'Scanning DooPlay database for confirmed competitor links...', 'peestream-wp-dooplay' ),
					'migrating'       => __( 'Migrating video servers in background...', 'peestream-wp-dooplay' ),
					'completed'       => __( 'Migration process finished successfully!', 'peestream-wp-dooplay' ),
					'confirm_start'   => __( 'Are you sure you want to start the migration? Candidate videos will be queued to PeeStream and updated once ready.', 'peestream-wp-dooplay' ),
					'confirm_rollback'=> __( 'Are you sure you want to rollback migrated posts to their original state?', 'peestream-wp-dooplay' ),
				),
			)
		);
	}

	/**
	 * Detect if URL belongs to confirmed competitor hoster using strict hostname validation
	 */
	public function detect_confirmed_hoster( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}

		$host = strtolower( trim( $host ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		// Never match PeeStream itself
		if ( 'peestream.com' === $host || 'peeplayer.com' === $host || substr( $host, -14 ) === '.peestream.com' || substr( $host, -14 ) === '.peeplayer.com' ) {
			return false;
		}

		foreach ( $this->confirmed_hosters as $key => $info ) {
			foreach ( $info['hosts'] as $allowed_host ) {
				if ( $host === $allowed_host || substr( $host, -( strlen( $allowed_host ) + 1 ) ) === '.' . $allowed_host ) {
					return array(
						'key'  => $key,
						'name' => $info['name'],
						'host' => $host,
					);
				}
			}
		}

		return false;
	}

	public function render_migrator_page() {
		$api_key = ! empty( $this->options['api_key'] ) ? $this->options['api_key'] : '';
		global $wpdb;

		$table_queue = $wpdb->prefix . 'peestream_migration_queue';
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';

		$queue_count_pending = 0;
		$queue_count_ready   = 0;
		$backup_count        = 0;

		// Check if tables exist
		$has_queue_table = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_queue}'" ) === $table_queue );
		if ( $has_queue_table ) {
			$queue_count_pending = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_queue} WHERE status IN ('queued', 'pending', 'checking')" );
			$queue_count_ready   = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_queue} WHERE status = 'completed'" );
		}

		$has_backup_table = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_backups}'" ) === $table_backups );
		if ( $has_backup_table ) {
			$backup_count = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_backups} WHERE status = 'completed'" );
		}
		?>
		<div class="wrap peestream-wrap">
			<div class="peestream-header">
				<div class="peestream-header-brand">
					<img src="<?php echo esc_url( PEESTREAM_PLUGIN_URL . 'assets/images/peestream-logo.png' ); ?>" alt="PeeStream Logo" class="peestream-header-logo">
					<div>
						<h1><?php esc_html_e( 'DooPlay Competitor Auto-Migrator', 'peestream-wp-dooplay' ); ?></h1>
						<p class="peestream-tagline"><?php esc_html_e( 'Safely queue, poll, and migrate Streamtape, MixDrop & VOE players to PeeStream with dry-run and 1-click rollback.', 'peestream-wp-dooplay' ); ?></p>
					</div>
				</div>
				<div class="peestream-header-badge"><?php esc_html_e( 'Safe Async Migrator', 'peestream-wp-dooplay' ); ?></div>
			</div>

			<?php if ( empty( $api_key ) ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Warning: Your PeeStream API Key is not configured. Please go to Settings and enter your API key first.', 'peestream-wp-dooplay' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Queue Status Card -->
			<div class="peestream-card" style="margin-bottom:20px;">
				<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
					<div>
						<h3 style="margin:0 0 4px 0;"><?php esc_html_e( 'Persistent Migration Queue & Rollback Storage', 'peestream-wp-dooplay' ); ?></h3>
						<p style="margin:0;color:#64748b;font-size:13px;">
							<?php
							printf(
								esc_html__( 'Active Queue: %1$d pending / %2$d completed | Restorable Backups: %3$d posts', 'peestream-wp-dooplay' ),
								$queue_count_pending,
								$queue_count_ready,
								$backup_count
							);
							?>
						</p>
					</div>
					<div style="display:flex;gap:10px;">
						<button type="button" class="button button-secondary" id="pst_btn_check_queue">
							<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Check Pending Encodings', 'peestream-wp-dooplay' ); ?>
						</button>
						<button type="button" class="button button-link-delete" id="pst_btn_rollback" <?php echo ( $backup_count === 0 ) ? 'disabled' : ''; ?>>
							<span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Rollback Migrated Posts', 'peestream-wp-dooplay' ); ?>
						</button>
					</div>
				</div>
			</div>

			<div class="peestream-grid">
				<!-- Step 1: Scanner Card -->
				<div class="peestream-col">
					<div class="peestream-card">
						<h2><span class="dashicons dashicons-search"></span> <?php esc_html_e( '1. Scan DooPlay Repeatable Fields', 'peestream-wp-dooplay' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Scans movies and TV episodes with pagination to prevent memory exhaustion.', 'peestream-wp-dooplay' ); ?></p>

						<div style="margin: 20px 0;">
							<button type="button" class="button button-primary button-hero" id="pst_btn_scan">
								<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Scan Entire Database Now', 'peestream-wp-dooplay' ); ?>
							</button>
						</div>

						<div id="pst_scan_loading" style="display:none;padding:15px;color:#64748b;">
							<span class="dashicons dashicons-update peestream-spin"></span> <?php esc_html_e( 'Analyzing database tables (wp_postmeta)...', 'peestream-wp-dooplay' ); ?>
						</div>

						<div id="pst_scan_results" style="display:none;margin-top:20px;">
							<h3><?php esc_html_e( 'Confirmed Competitor Links Breakdown', 'peestream-wp-dooplay' ); ?></h3>
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
										<option value="primary" selected><?php esc_html_e( 'Set PeeStream as Primary (Server 1) & keep competitor as fallback (Recommended)', 'peestream-wp-dooplay' ); ?></option>
										<option value="replace"><?php esc_html_e( 'Replace competitor player in-place with PeeStream', 'peestream-wp-dooplay' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Primary mode places PeeStream on top of player tabs while keeping old players intact.', 'peestream-wp-dooplay' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="pst_filter_hoster"><?php esc_html_e( 'Target Hoster', 'peestream-wp-dooplay' ); ?></label></th>
								<td>
									<select id="pst_filter_hoster" class="widefat">
										<option value="all"><?php esc_html_e( 'All Confirmed Hosters (Streamtape, MixDrop, VOE, etc.)', 'peestream-wp-dooplay' ); ?></option>
										<?php foreach ( $this->confirmed_hosters as $h_k => $h_v ) : ?>
											<option value="<?php echo esc_attr( $h_k ); ?>"><?php echo esc_html( $h_v['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Only hosters verified working on PeeStream remote upload are listed.', 'peestream-wp-dooplay' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="pst_batch_size"><?php esc_html_e( 'Batch Size per Request', 'peestream-wp-dooplay' ); ?></label></th>
								<td>
									<input type="number" id="pst_batch_size" value="5" min="1" max="20" class="small-text">
									<span class="description"><?php esc_html_e( 'Number of posts processed per AJAX cycle (avoids timeouts).', 'peestream-wp-dooplay' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Dry Run Simulation', 'peestream-wp-dooplay' ); ?></th>
								<td>
									<label>
										<input type="checkbox" id="pst_dry_run" value="1">
										<strong><?php esc_html_e( 'Simulate only (no API calls, no database modifications)', 'peestream-wp-dooplay' ); ?></strong>
									</label>
									<p class="description"><?php esc_html_e( 'Use dry run to inspect matched posts and links safely before queueing.', 'peestream-wp-dooplay' ); ?></p>
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
							<div id="pst_migration_console" style="background:#0f172a;color:#38bdf8;font-family:monospace;font-size:11px;padding:12px 14px;border-radius:6px;height:180px;overflow-y:auto;margin-top:6px;line-height:1.5;">
								<?php esc_html_e( '[Ready] Press Scan to find candidate videos.', 'peestream-wp-dooplay' ); ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Paginated scan for candidate posts containing confirmed competitor hoster links
	 */
	public function ajax_scan() {
		check_ajax_referer( 'peestream_migrator_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		global $wpdb;

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? min( 500, max( 50, absint( $_POST['limit'] ) ) ) : 200;

		$total_records = (int) $wpdb->get_var( "SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = 'repeatable_fields' AND meta_value != ''" );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'repeatable_fields' AND meta_value != '' ORDER BY post_id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		$hoster_counts = array_fill_keys( array_keys( $this->confirmed_hosters ), 0 );
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

				$detected = $this->detect_confirmed_hoster( $url );
				if ( $detected ) {
					$hoster_counts[ $detected['key'] ]++;
					$has_competitor = true;
				}
			}

			if ( $has_competitor ) {
				$candidate_posts[] = $post_id;
			}
		}

		$candidate_posts = array_values( array_unique( $candidate_posts ) );
		$has_more = ( $offset + $limit ) < $total_records;

		wp_send_json_success( array(
			'hoster_counts' => $hoster_counts,
			'total_posts'   => count( $candidate_posts ),
			'post_ids'      => $candidate_posts,
			'offset'        => $offset,
			'limit'         => $limit,
			'scanned_count' => count( $rows ),
			'total_records' => $total_records,
			'has_more'      => $has_more,
			'next_offset'   => $offset + $limit,
		) );
	}

	/**
	 * Safe batch processor: Queues remote upload, checks status, modifies repeatable_fields ONLY when ready
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'peestream_migrator_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) $_POST['post_ids'] ) : array();
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'primary';
		$target_h = isset( $_POST['target_hoster'] ) ? sanitize_key( $_POST['target_hoster'] ) : 'all';
		$dry_run  = ! empty( $_POST['dry_run'] );

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No post IDs provided for processing.', 'peestream-wp-dooplay' ) ) );
		}

		$api_key = ! empty( $this->options['api_key'] ) ? $this->options['api_key'] : '';
		if ( empty( $api_key ) && ! $dry_run ) {
			wp_send_json_error( array( 'message' => __( 'PeeStream API key is not configured.', 'peestream-wp-dooplay' ) ) );
		}

		$api_domain    = ! empty( $this->options['api_domain'] ) ? rtrim( $this->options['api_domain'], '/' ) : 'https://peestream.com';
		if ( 0 !== strpos( $api_domain, 'https://' ) ) {
			$api_domain = 'https://peestream.com';
		}
		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		if ( 0 !== strpos( $player_domain, 'https://' ) ) {
			$player_domain = 'https://peeplayer.com';
		}

		$fld_id        = isset( $this->options['default_folder_id'] ) ? absint( $this->options['default_folder_id'] ) : 0;
		$server_name   = ! empty( $_POST['server_name'] ) ? sanitize_text_field( $_POST['server_name'] ) : ( ! empty( $this->options['default_server_name'] ) ? sanitize_text_field( $this->options['default_server_name'] ) : 'PeeStream HD' );
		$language      = ! empty( $_POST['language'] ) ? sanitize_text_field( $_POST['language'] ) : ( ! empty( $this->options['default_language'] ) ? sanitize_text_field( $this->options['default_language'] ) : 'English' );
		$quality       = ! empty( $_POST['quality'] ) ? sanitize_text_field( $_POST['quality'] ) : ( ! empty( $this->options['default_quality'] ) ? sanitize_text_field( $this->options['default_quality'] ) : 'HD 1080p' );

		global $wpdb;
		$table_queue   = $wpdb->prefix . 'peestream_migration_queue';
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';

		$lock_token = null;
		if ( ! $dry_run ) {
			$lock_token = self::acquire_lock( 120 );
			if ( ! $lock_token ) {
				wp_send_json_error( array(
					'locked'  => true,
					'message' => __( 'Another migration process or queue worker is currently running. Please wait for it to finish.', 'peestream-wp-dooplay' ),
				) );
				return;
			}
		}

		$logs = array();
		$processed_count = 0;

		try {
			foreach ( $post_ids as $p_id ) {
				if ( $lock_token ) {
					self::extend_lock( $lock_token, 120 );
				}
				$title = get_the_title( $p_id );
				$meta = get_post_meta( $p_id, 'repeatable_fields', true );
				if ( is_string( $meta ) ) {
					$meta = maybe_unserialize( $meta );
				}
				if ( ! is_array( $meta ) ) {
					continue;
				}

				// Find competitor links in post
				$links_to_process = array();
				foreach ( $meta as $srv ) {
					$url = isset( $srv['url'] ) ? trim( $srv['url'] ) : '';
					if ( empty( $url ) ) {
						continue;
					}
					$detected = $this->detect_confirmed_hoster( $url );
					if ( ! $detected ) {
						continue;
					}
					if ( 'all' !== $target_h && $detected['key'] !== $target_h ) {
						continue;
					}
					$links_to_process[] = array(
						'url'      => $url,
						'detected' => $detected,
						'server'   => $srv,
					);
				}

				if ( empty( $links_to_process ) ) {
					continue;
				}

				if ( $dry_run ) {
					foreach ( $links_to_process as $lp ) {
						$logs[] = sprintf( '[DRY-RUN] Post #%d (%s): Found %s link (%s). Would queue to PeeStream (Strategy: %s).', $p_id, esc_html( $title ), $lp['detected']['name'], esc_html( $lp['url'] ), $mode );
					}
					continue;
				}

				// Process each competitor server individually & atomically
				foreach ( $links_to_process as $lp ) {
					if ( $lock_token ) {
						self::extend_lock( $lock_token, 120 );
					}
					$url      = $lp['url'];
					$detected = $lp['detected'];

					// Check if already in persistent queue
					$existing_queue = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM {$table_queue} WHERE post_id = %d AND original_url = %s",
							$p_id,
							$url
						),
						ARRAY_A
					);

					$filecode = '';
					$is_ready = false;
					$queue_id = 0;

					if ( $existing_queue ) {
						$queue_id = (int) $existing_queue['id'];
						$filecode = $existing_queue['filecode'];
						if ( 'completed' === $existing_queue['status'] ) {
							$logs[] = sprintf( '[SKIP] Post #%d: Link %s already successfully migrated (filecode: %s).', $p_id, esc_html( $url ), $filecode );
							continue;
						}

						if ( empty( $filecode ) || 'failed' === $existing_queue['status'] ) {
							$filecode = '';
						} else {
							// Poll status from PeeStream API
							$status_info = $this->query_file_info( $api_domain, $api_key, $filecode );
							if ( $status_info && isset( $status_info['canplay'] ) && 1 === (int) $status_info['canplay'] ) {
								$is_ready = true;
							} else {
								$cur_state = ( $status_info && isset( $status_info['state'] ) ) ? $status_info['state'] : 'pending';
								$wpdb->update(
									$table_queue,
									array(
										'state'      => $cur_state,
										'updated_at' => current_time( 'mysql', true ),
									),
									array( 'id' => $queue_id )
								);
								$logs[] = sprintf( '[PENDING] Post #%d: File %s still processing (%s). Original link untouched.', $p_id, $filecode, $cur_state );
								continue;
							}
						}
					}

					if ( empty( $filecode ) ) {
						// Idempotent pre-reservation BEFORE calling /api/upload/url to eliminate duplicate uploads
						if ( ! $existing_queue ) {
							$inserted = $wpdb->query( $wpdb->prepare(
								"INSERT INTO {$table_queue} 
								 (post_id, original_url, filecode, mode, server_name, language, quality, state, status, retry_count, error_msg, original_repeatable, created_at, updated_at)
								 VALUES (%d, %s, '', %s, %s, %s, %s, 'uploading', 'checking', 0, '', %s, %s, %s)",
								$p_id,
								$url,
								$mode,
								$server_name,
								$language,
								$quality,
								maybe_serialize( $meta ),
								current_time( 'mysql', true ),
								current_time( 'mysql', true )
							) );

							if ( false === $inserted ) {
								// Duplicate collision! Check existing record
								$existing_queue = $wpdb->get_row(
									$wpdb->prepare(
										"SELECT * FROM {$table_queue} WHERE post_id = %d AND original_url = %s",
										$p_id,
										$url
									),
									ARRAY_A
								);
								if ( $existing_queue && 'completed' === $existing_queue['status'] ) {
									$logs[] = sprintf( '[SKIP] Post #%d: Link %s already migrated.', $p_id, esc_html( $url ) );
									continue;
								}
								if ( $existing_queue && ! empty( $existing_queue['filecode'] ) ) {
									$filecode = $existing_queue['filecode'];
									$queue_id = (int) $existing_queue['id'];
								}
							} else {
								$queue_id = (int) $wpdb->insert_id;
							}
						}

						if ( empty( $filecode ) ) {
							// Step 1: Submit to PeeStream Remote Upload API
							$endpoint = $api_domain . '/api/upload/url?' . http_build_query( array(
								'key'       => $api_key,
								'url'       => $url,
								'new_title' => $title,
								'fld_id'    => $fld_id,
							) );

							$res = wp_safe_remote_get( $endpoint, array(
								'timeout'    => 20,
								'sslverify'  => true,
								'user-agent' => 'PeeStream-WP-DooPlay/' . PEESTREAM_VERSION,
							) );

							if ( is_wp_error( $res ) ) {
								if ( $queue_id > 0 ) {
									$wpdb->update(
										$table_queue,
										array(
											'state'      => 'failed',
											'status'     => 'failed',
											'error_msg'  => $res->get_error_message(),
											'updated_at' => current_time( 'mysql', true ),
										),
										array( 'id' => $queue_id )
									);
								}
								$logs[] = sprintf( '[ERROR] Post #%d: Remote upload network error: %s', $p_id, $res->get_error_message() );
								continue;
							}

							$data = json_decode( wp_remote_retrieve_body( $res ), true );
							if ( ! $data || ( isset( $data['status'] ) && 200 !== (int) $data['status'] ) ) {
								$err_msg = isset( $data['msg'] ) ? sanitize_text_field( $data['msg'] ) : 'Remote upload error';
								if ( $queue_id > 0 ) {
									$wpdb->update(
										$table_queue,
										array(
											'state'      => 'failed',
											'status'     => 'failed',
											'error_msg'  => $err_msg,
											'updated_at' => current_time( 'mysql', true ),
										),
										array( 'id' => $queue_id )
									);
								}
								$logs[] = sprintf( '[FAIL] Post #%d: Could not queue %s (%s). Original link preserved.', $p_id, esc_html( $url ), $err_msg );
								continue;
							}

							$filecode = isset( $data['result']['filecode'] ) ? sanitize_text_field( $data['result']['filecode'] ) : '';
							if ( empty( $filecode ) ) {
								if ( $queue_id > 0 ) {
									$wpdb->update(
										$table_queue,
										array(
											'state'      => 'failed',
											'status'     => 'failed',
											'error_msg'  => 'Missing filecode in API response',
											'updated_at' => current_time( 'mysql', true ),
										),
										array( 'id' => $queue_id )
									);
								}
								$logs[] = sprintf( '[FAIL] Post #%d: Missing filecode for %s.', $p_id, esc_html( $url ) );
								continue;
							}

							// Update queue with acquired filecode
							if ( $queue_id > 0 ) {
								$wpdb->update(
									$table_queue,
									array(
										'filecode'   => $filecode,
										'state'      => 'pending',
										'status'     => 'pending',
										'updated_at' => current_time( 'mysql', true ),
									),
									array( 'id' => $queue_id )
								);
							} else {
								$wpdb->insert(
									$table_queue,
									array(
										'post_id'             => $p_id,
										'original_url'        => $url,
										'filecode'            => $filecode,
										'mode'                => $mode,
										'server_name'         => $server_name,
										'language'            => $language,
										'quality'             => $quality,
										'state'               => 'pending',
										'status'              => 'pending',
										'original_repeatable' => maybe_serialize( $meta ),
										'created_at'          => current_time( 'mysql', true ),
										'updated_at'          => current_time( 'mysql', true ),
									)
								);
								$queue_id = (int) $wpdb->insert_id;
							}

							// Check if instantly ready
							$status_info = $this->query_file_info( $api_domain, $api_key, $filecode );
							if ( $status_info && isset( $status_info['canplay'] ) && 1 === (int) $status_info['canplay'] ) {
								$is_ready = true;
							} else {
								$cur_state = ( $status_info && isset( $status_info['state'] ) ) ? $status_info['state'] : 'pending';
								$logs[] = sprintf( '[QUEUED] Post #%d: File %s queued on PeeStream (%s). Original link untouched until ready.', $p_id, $filecode, $cur_state );
								continue;
							}
						}
					}

					// Step 2: Video is verified READY (canplay=1). Apply update to DooPlay repeatable_fields!
					if ( $is_ready ) {
						// Always reload freshest postmeta before modifying
						$cur_meta = get_post_meta( $p_id, 'repeatable_fields', true );
						if ( is_string( $cur_meta ) ) {
							$cur_meta = maybe_unserialize( $cur_meta );
						}
						if ( ! is_array( $cur_meta ) ) {
							$cur_meta = array();
						}

						$new_url = $player_domain . '/e/' . $filecode;

						// Check if this PeeStream player is already attached anywhere in the post
						$already_present = false;
						foreach ( $cur_meta as $check_s ) {
							if ( isset( $check_s['url'] ) && ( $check_s['url'] === $new_url || strpos( $check_s['url'], '/e/' . $filecode ) !== false ) ) {
								$already_present = true;
								break;
							}
						}

						if ( $already_present ) {
							$wpdb->update(
								$table_queue,
								array(
									'state'      => 'ready',
									'status'     => 'completed',
									'updated_at' => current_time( 'mysql', true ),
								),
								array( 'post_id' => $p_id, 'original_url' => $url )
							);
							$logs[] = sprintf( '[ALREADY PRESENT] Post #%d: Player %s already present in post.', $p_id, $filecode );
							continue;
						}

						// Verify original URL still exists in current repeatable_fields (editor conflict check)
						$orig_found_idx = false;
						foreach ( $cur_meta as $c_idx => $c_srv ) {
							if ( isset( $c_srv['url'] ) && trim( $c_srv['url'] ) === $url ) {
								$orig_found_idx = $c_idx;
								break;
							}
						}

						if ( false === $orig_found_idx ) {
							$wpdb->update(
								$table_queue,
								array(
									'state'      => 'conflict',
									'status'     => 'conflict',
									'error_msg'  => 'Original server URL modified or deleted by editor in interim. Modification aborted.',
									'updated_at' => current_time( 'mysql', true ),
								),
								array( 'post_id' => $p_id, 'original_url' => $url )
							);
							$logs[] = sprintf( '[CONFLICT] Post #%d: Original server (%s) was changed or deleted by editor. Modification aborted.', $p_id, esc_html( $url ) );
							continue;
						}

						// Create pre-migration backup snapshot for THIS specific link
						$ins_b = $wpdb->insert(
							$table_backups,
							array(
								'post_id'             => $p_id,
								'original_repeatable' => maybe_serialize( $cur_meta ),
								'migrated_url'        => $url,
								'new_code'            => $filecode,
								'mode'                => $mode,
								'backup_time'         => current_time( 'mysql', true ),
								'status'              => 'in_progress',
							)
						);

						if ( false === $ins_b || empty( $wpdb->insert_id ) ) {
							$logs[] = sprintf( '[ERROR] Post #%d: Could not save backup snapshot. Post modification aborted for safety.', $p_id );
							continue;
						}
						$link_backup_id = (int) $wpdb->insert_id;

						$updated_meta = $cur_meta;
						if ( 'replace' === $mode ) {
							$updated_meta[$orig_found_idx]['url']    = $new_url;
							$updated_meta[$orig_found_idx]['name']   = $server_name;
							$updated_meta[$orig_found_idx]['select'] = 'iframe';
							if ( ! empty( $language ) ) {
								$updated_meta[$orig_found_idx]['idioma'] = $language;
							}
							if ( ! empty( $quality ) ) {
								$updated_meta[$orig_found_idx]['quality'] = $quality;
							}
						} else {
							// Primary mode: Prepend PeeStream as Server 1, preserve existing servers
							$new_entry = array(
								'name'    => $server_name,
								'select'  => 'iframe',
								'idioma'  => ! empty( $language ) ? $language : ( isset( $cur_meta[$orig_found_idx]['idioma'] ) ? $cur_meta[$orig_found_idx]['idioma'] : 'English' ),
								'quality' => ! empty( $quality ) ? $quality : ( isset( $cur_meta[$orig_found_idx]['quality'] ) ? $cur_meta[$orig_found_idx]['quality'] : '1080p' ),
								'url'     => $new_url,
							);
							array_unshift( $updated_meta, $new_entry );
						}

						$meta_to_save = array_values( $updated_meta );
						update_post_meta( $p_id, 'repeatable_fields', $meta_to_save );

						// Strict verification: verify that post meta was indeed persisted
						$fresh_meta = get_post_meta( $p_id, 'repeatable_fields', true );
						if ( is_string( $fresh_meta ) ) {
							$fresh_meta = maybe_unserialize( $fresh_meta );
						}
						$is_saved = ( $fresh_meta === $meta_to_save );

						if ( $is_saved ) {
							$wpdb->update(
								$table_backups,
								array( 'status' => 'completed' ),
								array( 'id' => $link_backup_id )
							);
							$wpdb->update(
								$table_queue,
								array(
									'state'      => 'ready',
									'status'     => 'completed',
									'updated_at' => current_time( 'mysql', true ),
								),
								array( 'post_id' => $p_id, 'original_url' => $url )
							);
							$processed_count++;
							if ( 'replace' === $mode ) {
								$logs[] = sprintf( '[READY - REPLACED] Post #%d: Replaced %s with PeeStream %s (canplay=1).', $p_id, $detected['name'], $filecode );
							} else {
								$logs[] = sprintf( '[READY - PRIMARY] Post #%d: PeeStream %s attached as Server 1. Original %s retained as fallback.', $p_id, $filecode, $detected['name'] );
							}
						} else {
							$wpdb->update(
								$table_backups,
								array( 'status' => 'apply_failed' ),
								array( 'id' => $link_backup_id )
							);
							$wpdb->update(
								$table_queue,
								array(
									'state'      => 'apply_failed',
									'status'     => 'failed',
									'error_msg'  => 'Failed to verify repeatable_fields post meta update. Post preserved.',
									'updated_at' => current_time( 'mysql', true ),
								),
								array( 'post_id' => $p_id, 'original_url' => $url )
							);
							$logs[] = sprintf( '[ERROR] Post #%d: Could not verify repeatable_fields post meta update. Queue marked failed.', $p_id );
						}
					}
				}
			}
		} finally {
			if ( $lock_token ) {
				self::release_lock( $lock_token );
			}
		}

		wp_send_json_success( array(
			'processed_count' => $processed_count,
			'logs'            => $logs,
		) );
	}

	/**
	 * Poll PeeStream API for single file status (/api/file/info)
	 * Handles PeeStream array result format [ { "filecode": "...", "canplay": 1, ... } ]
	 */
	private function query_file_info( $api_domain, $api_key, $filecode ) {
		if ( empty( $filecode ) || empty( $api_key ) ) {
			return false;
		}

		$endpoint = $api_domain . '/api/file/info?' . http_build_query( array(
			'key'       => $api_key,
			'file_code' => $filecode,
		) );

		$res = wp_safe_remote_get( $endpoint, array(
			'timeout'    => 15,
			'sslverify'  => true,
			'user-agent' => 'PeeStream-WP-DooPlay/' . PEESTREAM_VERSION,
		) );

		if ( is_wp_error( $res ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! $body || ! isset( $body['status'] ) || 200 !== (int) $body['status'] ) {
			return false;
		}

		if ( empty( $body['result'] ) ) {
			return false;
		}

		$result = $body['result'];

		// PeeStream API returns result as an array of objects: [ { "filecode": "...", "canplay": 1, ... } ]
		if ( is_array( $result ) ) {
			if ( isset( $result[0] ) && is_array( $result[0] ) ) {
				foreach ( $result as $item ) {
					if ( is_array( $item ) ) {
						$fc = isset( $item['filecode'] ) ? $item['filecode'] : ( isset( $item['file_code'] ) ? $item['file_code'] : '' );
						if ( $fc === $filecode ) {
							return $item;
						}
					}
				}
				return false;
			}
			// Single object associative array
			$fc = isset( $result['filecode'] ) ? $result['filecode'] : ( isset( $result['file_code'] ) ? $result['file_code'] : '' );
			if ( $fc === $filecode ) {
				return $result;
			}
			return false;
		}

		return false;
	}

	/**
	 * Core batch processor for pending migration queue items
	 * Shared between manual AJAX checks and automated WP-Cron runs
	 * Features heartbeat lock renewal, time budgeting, and post meta update verification
	 */
	public function process_queue_batch( $limit = 10, $max_execution_time = 45 ) {
		$api_key = ! empty( $this->options['api_key'] ) ? $this->options['api_key'] : '';
		if ( empty( $api_key ) ) {
			return array(
				'checked_count' => 0,
				'ready_count'   => 0,
				'logs'          => array( 'PeeStream API key not configured.' ),
			);
		}

		$api_domain    = ! empty( $this->options['api_domain'] ) ? rtrim( $this->options['api_domain'], '/' ) : 'https://peestream.com';
		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$default_server_name = ! empty( $this->options['default_server_name'] ) ? sanitize_text_field( $this->options['default_server_name'] ) : 'PeeStream HD';

		global $wpdb;
		$table_queue   = $wpdb->prefix . 'peestream_migration_queue';
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';

		// Recover from any interrupted partial operations before starting new items
		self::recover_partial_failures();

		$start_time = time();
		$stale_checking_time = gmdate( 'Y-m-d H:i:s', time() - 300 );
		$pending_items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_queue} 
				 WHERE status IN ('pending', 'queued') 
				    OR (status = 'checking' AND updated_at < %s) 
				 ORDER BY id ASC LIMIT %d",
				$stale_checking_time,
				max( 1, intval( $limit ) )
			),
			ARRAY_A
		);

		$updated_count = 0;
		$logs = array();

		foreach ( $pending_items as $item ) {
			// Extend lock lease on each task (heartbeat) so active worker is never usurped by timeout
			self::extend_lock( self::$owner_token, 120 );

			// Check execution time budget to prevent exceeding PHP max_execution_time
			if ( ( time() - $start_time ) >= $max_execution_time ) {
				$logs[] = '[BUDGET] Batch execution time budget reached. Deferring remaining queue items to next run.';
				break;
			}

			$p_id        = (int) $item['post_id'];
			$filecode    = $item['filecode'];
			$orig_url    = $item['original_url'];
			$mode        = ! empty( $item['mode'] ) ? $item['mode'] : 'replace';
			$srv_name    = ! empty( $item['server_name'] ) ? $item['server_name'] : $default_server_name;
			$language    = ! empty( $item['language'] ) ? $item['language'] : 'English';
			$quality     = ! empty( $item['quality'] ) ? $item['quality'] : '1080p';
			$retry_count = isset( $item['retry_count'] ) ? (int) $item['retry_count'] : 0;

			// Row-level atomic claim so another worker doesn't process concurrently
			$claimed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table_queue} 
				 SET status = 'checking', updated_at = %s 
				 WHERE id = %d 
				   AND (status IN ('pending', 'queued') OR (status = 'checking' AND updated_at < %s))",
				current_time( 'mysql', true ),
				(int) $item['id'],
				$stale_checking_time
			) );

			if ( ! $claimed ) {
				// Task already claimed by another concurrent worker or state changed
				continue;
			}

			// Bounded retry & timeout safeguard (max 20 retries or older than 24 hours)
			$created_time = ! empty( $item['created_at'] ) ? strtotime( $item['created_at'] ) : 0;
			if ( $retry_count >= 20 || ( $created_time > 0 && ( time() - $created_time ) > 86400 ) ) {
				$wpdb->update(
					$table_queue,
					array(
						'state'      => 'failed',
						'status'     => 'failed',
						'error_msg'  => 'Max retry timeout exceeded (24h / 20 attempts).',
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $item['id'] )
				);
				$logs[] = sprintf( '[TIMEOUT] Post #%d: Encoding wait timeout exceeded for %s. Original link untouched.', $p_id, $filecode );
				continue;
			}

			$info = $this->query_file_info( $api_domain, $api_key, $filecode );
			if ( ! $info ) {
				$wpdb->update(
					$table_queue,
					array(
						'status'      => 'pending',
						'retry_count' => $retry_count + 1,
						'updated_at'  => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $item['id'] )
				);
				continue;
			}

			if ( isset( $info['canplay'] ) && 1 === (int) $info['canplay'] ) {
				// Video is verified READY!
				$meta = get_post_meta( $p_id, 'repeatable_fields', true );
				if ( is_string( $meta ) ) {
					$meta = maybe_unserialize( $meta );
				}
				if ( ! is_array( $meta ) ) {
					$meta = array();
				}

				$new_url = $player_domain . '/e/' . $filecode;

				// Check duplicate: prevent duplicate player entries
				$already_present = false;
				foreach ( $meta as $check_s ) {
					if ( isset( $check_s['url'] ) && ( $check_s['url'] === $new_url || strpos( $check_s['url'], '/e/' . $filecode ) !== false ) ) {
						$already_present = true;
						break;
					}
				}

				if ( $already_present ) {
					$wpdb->update(
						$table_queue,
						array(
							'state'      => 'ready',
							'status'     => 'completed',
							'updated_at' => current_time( 'mysql', true ),
						),
						array( 'id' => (int) $item['id'] )
					);
					$logs[] = sprintf( '[ALREADY PRESENT] Post #%d: Player %s already present in post.', $p_id, $filecode );
					continue;
				}

				$snapshot_meta = $meta;
				$post_modified = false;
				$backup_id = 0;

				if ( 'replace' === $mode ) {
					// Surgical in-place replacement of the STRICT original URL only (no loose hostname matching)
					$replaced = false;
					foreach ( $meta as &$srv ) {
						if ( isset( $srv['url'] ) && trim( $srv['url'] ) === $orig_url ) {
							$srv['url']    = $new_url;
							$srv['name']   = $srv_name;
							$srv['select'] = 'iframe';
							if ( ! empty( $language ) ) $srv['idioma'] = $language;
							if ( ! empty( $quality ) ) $srv['quality'] = $quality;
							$replaced = true;
							break;
						}
					}
					unset( $srv );

					if ( ! $replaced ) {
						// Conflict! Original server was removed or modified by editor in interim.
						$wpdb->update(
							$table_queue,
							array(
								'state'      => 'conflict',
								'status'     => 'conflict',
								'error_msg'  => 'Original server URL modified or deleted by editor in interim. Replacement aborted.',
								'updated_at' => current_time( 'mysql', true ),
							),
							array( 'id' => (int) $item['id'] )
						);
						$logs[] = sprintf( '[CONFLICT] Post #%d: Target server (%s) was changed or deleted by editor. Automatic replacement aborted.', $p_id, esc_html( $orig_url ) );
						continue;
					}

					// Save pre-modification backup snapshot initially with in_progress status
					$ins_b = $wpdb->insert(
						$table_backups,
						array(
							'post_id'             => $p_id,
							'original_repeatable' => maybe_serialize( $snapshot_meta ),
							'migrated_url'        => $orig_url,
							'new_code'            => $filecode,
							'mode'                => 'replace',
							'backup_time'         => current_time( 'mysql', true ),
							'status'              => 'in_progress',
						)
					);

					if ( false === $ins_b || empty( $wpdb->insert_id ) ) {
						$logs[] = sprintf( '[ERROR] Post #%d: Could not save backup snapshot. Replace modification aborted.', $p_id );
						continue;
					}
					$backup_id = (int) $wpdb->insert_id;
					$post_modified = true;
				} else {
					// Primary strategy: Verify original server still exists before prepending
					$orig_still_there = false;
					foreach ( $meta as $check_s ) {
						if ( isset( $check_s['url'] ) && trim( $check_s['url'] ) === $orig_url ) {
							$orig_still_there = true;
							break;
						}
					}

					if ( ! $orig_still_there ) {
						// Conflict! Original server was removed or modified by editor in interim.
						$wpdb->update(
							$table_queue,
							array(
								'state'      => 'conflict',
								'status'     => 'conflict',
								'error_msg'  => 'Original server URL modified or deleted by editor in interim. Primary insertion aborted.',
								'updated_at' => current_time( 'mysql', true ),
							),
							array( 'id' => (int) $item['id'] )
						);
						$logs[] = sprintf( '[CONFLICT] Post #%d: Original server (%s) was changed or deleted by editor. Primary attachment aborted.', $p_id, esc_html( $orig_url ) );
						continue;
					}

					// Save pre-modification backup snapshot initially with in_progress status
					$ins_b = $wpdb->insert(
						$table_backups,
						array(
							'post_id'             => $p_id,
							'original_repeatable' => maybe_serialize( $snapshot_meta ),
							'migrated_url'        => $orig_url,
							'new_code'            => $filecode,
							'mode'                => 'primary',
							'backup_time'         => current_time( 'mysql', true ),
							'status'              => 'in_progress',
						)
					);

					if ( false === $ins_b || empty( $wpdb->insert_id ) ) {
						$logs[] = sprintf( '[ERROR] Post #%d: Could not save backup snapshot. Primary attachment aborted.', $p_id );
						continue;
					}
					$backup_id = (int) $wpdb->insert_id;

					array_unshift( $meta, array(
						'name'    => $srv_name,
						'select'  => 'iframe',
						'idioma'  => $language,
						'quality' => $quality,
						'url'     => $new_url,
					) );
					$post_modified = true;
				}

				if ( $post_modified ) {
					$meta_to_save = array_values( $meta );
					update_post_meta( $p_id, 'repeatable_fields', $meta_to_save );

					// Strict verification of persisted repeatable_fields
					$fresh_meta = get_post_meta( $p_id, 'repeatable_fields', true );
					if ( is_string( $fresh_meta ) ) {
						$fresh_meta = maybe_unserialize( $fresh_meta );
					}
					$is_saved = ( $fresh_meta === $meta_to_save );

					if ( $is_saved ) {
						if ( $backup_id > 0 ) {
							$wpdb->update(
								$table_backups,
								array( 'status' => 'completed' ),
								array( 'id' => $backup_id )
							);
						}
						$wpdb->update(
							$table_queue,
							array(
								'state'      => 'ready',
								'status'     => 'completed',
								'updated_at' => current_time( 'mysql', true ),
							),
							array( 'id' => (int) $item['id'] )
						);
						$updated_count++;
						$logs[] = sprintf( '[READY] Post #%d: Video %s is ready! PeeStream player attached (Strategy: %s).', $p_id, $filecode, $mode );
					} else {
						// Post meta update failed verification: mark backup and queue failed without falsely completing
						if ( $backup_id > 0 ) {
							$wpdb->update(
								$table_backups,
								array( 'status' => 'apply_failed' ),
								array( 'id' => $backup_id )
							);
						}
						$wpdb->update(
							$table_queue,
							array(
								'state'      => 'apply_failed',
								'status'     => 'failed',
								'error_msg'  => 'Failed to verify repeatable_fields post meta update. Post preserved.',
								'updated_at' => current_time( 'mysql', true ),
							),
							array( 'id' => (int) $item['id'] )
						);
						$logs[] = sprintf( '[ERROR] Post #%d: Could not verify repeatable_fields post meta update. Queue marked failed.', $p_id );
					}
				}
			} elseif ( ( isset( $info['state'] ) && 'failed' === $info['state'] ) || ( isset( $info['status'] ) && 404 === (int) $info['status'] ) ) {
				$wpdb->update(
					$table_queue,
					array(
						'state'      => 'failed',
						'status'     => 'failed',
						'error_msg'  => 'API reported failed or missing video.',
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $item['id'] )
				);
				$logs[] = sprintf( '[FAILED] Post #%d: PeeStream remote download failed for %s. Original link untouched.', $p_id, $filecode );
			} else {
				$cur_state = ( isset( $info['state'] ) ) ? $info['state'] : 'pending';
				$wpdb->update(
					$table_queue,
					array(
						'state'       => $cur_state,
						'status'      => 'pending',
						'retry_count' => $retry_count + 1,
						'updated_at'  => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $item['id'] )
				);
			}
		}

		return array(
			'checked_count' => count( $pending_items ),
			'ready_count'   => $updated_count,
			'logs'          => $logs,
		);
	}

	private static $lock_key = 'peestream_queue_processing_lock';
	private static $owner_token = null;

	/**
	 * Acquire shared concurrency lock for queue processing using atomic DB option
	 */
	public static function acquire_lock( $timeout = 120 ) {
		global $wpdb;
		$token = wp_generate_password( 32, false );
		$now = time();
		$expires = $now + $timeout;
		$lock_val = $token . '|' . $expires;

		// 1. Attempt atomic insert via add_option
		if ( add_option( self::$lock_key, $lock_val, '', 'no' ) ) {
			self::$owner_token = $token;
			return $token;
		}

		// 2. Lock option exists: inspect expiration for safe timeout recovery
		$current = get_option( self::$lock_key );
		if ( ! empty( $current ) ) {
			$parts = explode( '|', $current );
			$lock_exp = isset( $parts[1] ) ? intval( $parts[1] ) : 0;
			if ( $lock_exp < $now ) {
				// Expired: atomic takeover with CAS (Compare-And-Swap)
				$updated = $wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$lock_val,
					self::$lock_key,
					$current
				) );
				if ( $updated ) {
					if ( function_exists( 'wp_cache_delete' ) ) {
						wp_cache_delete( self::$lock_key, 'options' );
					}
					self::$owner_token = $token;
					return $token;
				}
			}
		}

		return false;
	}

	/**
	 * Extend existing concurrency lock lease (heartbeat for active long-running workers)
	 */
	public static function extend_lock( $token = null, $timeout = 120 ) {
		global $wpdb;
		if ( null === $token ) {
			$token = self::$owner_token;
		}
		if ( empty( $token ) ) {
			return false;
		}
		$now = time();
		$new_exp = $now + $timeout;
		$new_val = $token . '|' . $new_exp;

		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
			$new_val,
			self::$lock_key,
			$wpdb->esc_like( $token ) . '|%'
		) );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::$lock_key, 'options' );
		}
		return ( $updated > 0 );
	}

	/**
	 * Release shared concurrency lock using conditional atomic SQL DELETE
	 * Deletes ONLY if current option_value is owned by the provided token.
	 */
	public static function release_lock( $token = null ) {
		global $wpdb;
		if ( null === $token ) {
			$token = self::$owner_token;
		}
		if ( empty( $token ) ) {
			return false;
		}

		// Conditional atomic SQL delete by owner token
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
			self::$lock_key,
			$wpdb->esc_like( $token ) . '|%'
		) );

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::$lock_key, 'options' );
		}
		if ( $deleted > 0 ) {
			if ( $token === self::$owner_token ) {
				self::$owner_token = null;
			}
			return true;
		}
		return false;
	}

	/**
	 * Safely recover from partial failures where backup succeeded but post_meta update did not finish
	 */
	public static function recover_partial_failures() {
		global $wpdb;
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';
		$table_queue   = $wpdb->prefix . 'peestream_migration_queue';

		// Look for in_progress backups older than 2 minutes
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 120 );
		$in_progress = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_backups} WHERE status = 'in_progress' AND backup_time < %s LIMIT 20",
			$cutoff
		), ARRAY_A );

		if ( empty( $in_progress ) ) {
			return;
		}

		foreach ( $in_progress as $b ) {
			$post_id  = (int) $b['post_id'];
			$new_code = trim( (string) $b['new_code'] );
			$orig_url = trim( (string) $b['migrated_url'] );

			$current_meta = get_post_meta( $post_id, 'repeatable_fields', true );
			if ( is_string( $current_meta ) ) {
				$current_meta = maybe_unserialize( $current_meta );
			}
			if ( ! is_array( $current_meta ) ) {
				$current_meta = array();
			}

			$has_player = false;
			foreach ( $current_meta as $srv ) {
				if ( isset( $srv['url'] ) && ( strpos( $srv['url'], '/e/' . $new_code ) !== false || $srv['url'] === $new_code ) ) {
					$has_player = true;
					break;
				}
			}

			if ( $has_player ) {
				// Post modification was actually completed before interruption
				$wpdb->update( $table_backups, array( 'status' => 'completed' ), array( 'id' => (int) $b['id'] ) );
				$wpdb->update( $table_queue, array( 'status' => 'completed', 'state' => 'ready', 'updated_at' => current_time( 'mysql', true ) ), array( 'post_id' => $post_id, 'original_url' => $orig_url ) );
			} else {
				// Post modification was interrupted or failed: mark backup as apply_failed and queue as pending for safe retry
				$wpdb->update( $table_backups, array( 'status' => 'apply_failed' ), array( 'id' => (int) $b['id'] ) );
				$wpdb->update( $table_queue, array( 'status' => 'pending', 'error_msg' => 'Recovered from interrupted partial update', 'updated_at' => current_time( 'mysql', true ) ), array( 'post_id' => $post_id, 'original_url' => $orig_url ) );
			}
		}
	}

	/**
	 * AJAX endpoint to poll pending queue items
	 */
	public function ajax_check_queue() {
		check_ajax_referer( 'peestream_migrator_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		$lock_token = self::acquire_lock();
		if ( ! $lock_token ) {
			wp_send_json_success( array(
				'checked_count' => 0,
				'ready_count'   => 0,
				'logs'          => array( __( 'Queue processor is currently busy in background. Please wait a moment and retry.', 'peestream-wp-dooplay' ) ),
				'locked'        => true,
			) );
			return;
		}

		$res = null;
		$error = null;
		try {
			$res = $this->process_queue_batch( 25 );
		} catch ( Exception $e ) {
			$error = $e->getMessage();
		} catch ( Throwable $t ) {
			$error = $t->getMessage();
		} finally {
			self::release_lock( $lock_token );
		}

		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		} else {
			wp_send_json_success( $res );
		}
	}

	/**
	 * Automated WP-Cron processor with transient concurrency lock
	 */
	public static function cron_check_queue() {
		$lock_token = self::acquire_lock();
		if ( ! $lock_token ) {
			return;
		}

		try {
			$migrator = new self();
			$migrator->process_queue_batch( 20 );
		} finally {
			self::release_lock( $lock_token );
		}
	}

	/**
	 * Surgical rollback of migrated posts
	 * Differentiates primary vs replace:
	 * - Primary: Removes only the PeeStream server; does not touch or duplicate original server.
	 * - Replace: Restores the complete original server item (name, select, idioma, quality, url).
	 * Preserves all subsequent editorial additions and modifications.
	 */
	public function ajax_rollback() {
		check_ajax_referer( 'peestream_migrator_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		global $wpdb;
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';

		$target_post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( $target_post_id > 0 ) {
			$backups = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table_backups} WHERE post_id = %d AND status = 'completed' ORDER BY id DESC", $target_post_id ),
				ARRAY_A
			);
		} else {
			$backups = $wpdb->get_results(
				"SELECT * FROM {$table_backups} WHERE status = 'completed' ORDER BY id DESC LIMIT 100",
				ARRAY_A
			);
		}

		if ( empty( $backups ) ) {
			wp_send_json_error( array( 'message' => __( 'No active backups found to rollback.', 'peestream-wp-dooplay' ) ) );
			return;
		}

		$restored_count = 0;
		$logs = array();

		foreach ( $backups as $b ) {
			$post_id  = (int) $b['post_id'];
			$orig_url = trim( (string) $b['migrated_url'] );
			$new_code = trim( (string) $b['new_code'] );
			$mode     = ! empty( $b['mode'] ) ? trim( (string) $b['mode'] ) : '';

			$current_meta = get_post_meta( $post_id, 'repeatable_fields', true );
			if ( is_string( $current_meta ) ) {
				$current_meta = maybe_unserialize( $current_meta );
			}
			if ( ! is_array( $current_meta ) ) {
				$current_meta = array();
			}

			$orig_repeatable = maybe_unserialize( $b['original_repeatable'] );
			if ( ! is_array( $orig_repeatable ) ) {
				$orig_repeatable = array();
			}

			$orig_still_present = false;
			foreach ( $current_meta as $cs ) {
				if ( isset( $cs['url'] ) && trim( $cs['url'] ) === $orig_url ) {
					$orig_still_present = true;
					break;
				}
			}

			// If mode was not explicitly stored or is 'unknown':
			if ( empty( $mode ) || 'unknown' === $mode ) {
				if ( $orig_still_present ) {
					$mode = 'primary';
				} else {
					// Mode is unknown and original link is missing from post: do NOT assume replace!
					$wpdb->update(
						$table_backups,
						array( 'status' => 'manual_review' ),
						array( 'id' => (int) $b['id'] )
					);
					$logs[] = sprintf( '[MANUAL REVIEW] Post #%d: Backup mode is unknown and original link is missing. Flagged for manual resolution to prevent data overwrite.', $post_id );
					continue;
				}
			} elseif ( 'replace' === $mode && $orig_still_present ) {
				// Safety override: mode recorded as replace, but original server URL is already in post.
				// This indicates historical mode misclassification or primary strategy was actually used.
				// Re-classify to primary so we remove only PeeStream without duplicating the original server.
				$mode = 'primary';
			}

			// Surgical rollback: do NOT overwrite entire repeatable_fields with old snapshot!
			// Check if current post still contains this PeeStream replacement
			$modified = false;
			foreach ( $current_meta as $idx => &$srv ) {
				if ( isset( $srv['url'] ) && ( strpos( $srv['url'], '/e/' . $new_code ) !== false || $srv['url'] === $new_code ) ) {
					if ( 'primary' === $mode ) {
						// Primary prepend mode: remove ONLY the PeeStream server entry.
						// DO NOT restore original link in its place because the original server already exists!
						unset( $current_meta[ $idx ] );
						$modified = true;
						break;
					} else {
						// Replace mode: restore the complete original server item (name, select, idioma, quality, url).
						$restored_original_item = null;
						foreach ( $orig_repeatable as $orig_item ) {
							if ( isset( $orig_item['url'] ) && trim( $orig_item['url'] ) === $orig_url ) {
								$restored_original_item = $orig_item;
								break;
							}
						}

						if ( $restored_original_item ) {
							$srv = $restored_original_item;
						} else {
							$srv['url'] = $orig_url;
						}
						$modified = true;
						break;
					}
				}
			}
			unset( $srv );

			if ( $modified ) {
				update_post_meta( $post_id, 'repeatable_fields', array_values( $current_meta ) );
				$restored_count++;
				$logs[] = sprintf( '[ROLLBACK - %s] Post #%d: Surgically restored server. Editorial additions preserved.', strtoupper( $mode ), $post_id );
			}

			$wpdb->update(
				$table_backups,
				array( 'status' => 'rolled_back' ),
				array( 'id' => (int) $b['id'] )
			);
		}

		wp_send_json_success( array(
			'restored_count' => $restored_count,
			'logs'           => $logs,
			'message'        => sprintf( __( 'Surgical rollback completed: %d posts reverted. Newer editorial changes preserved.', 'peestream-wp-dooplay' ), $restored_count ),
		) );
	}
}
