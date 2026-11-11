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
		$server_name   = ! empty( $this->options['default_server_name'] ) ? sanitize_text_field( $this->options['default_server_name'] ) : 'PeeStream HD';

		global $wpdb;
		$table_queue   = $wpdb->prefix . 'peestream_migration_queue';
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';

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

			$snapshot_meta = $meta;
			$post_modified = false;

			foreach ( $meta as $idx => &$srv ) {
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

				// Dry run branch
				if ( $dry_run ) {
					$logs[] = sprintf( '[DRY-RUN] Post #%d (%s): Found %s link (%s). Would queue to PeeStream (Strategy: %s).', $p_id, esc_html( $title ), $detected['name'], esc_html( $url ), $mode );
					continue;
				}

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

				if ( $existing_queue ) {
					$filecode = $existing_queue['filecode'];
					if ( 'completed' === $existing_queue['status'] ) {
						// Already completed
						$logs[] = sprintf( '[SKIP] Post #%d: Link %s already successfully migrated (filecode: %s).', $p_id, esc_html( $url ), $filecode );
						continue;
					}

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
								'updated_at' => current_time( 'mysql' ),
							),
							array( 'id' => (int) $existing_queue['id'] )
						);
						$logs[] = sprintf( '[PENDING] Post #%d: File %s still processing (%s). Original link untouched.', $p_id, $filecode, $cur_state );
						continue;
					}
				} else {
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
						$logs[] = sprintf( '[ERROR] Post #%d: Remote upload network error: %s', $p_id, $res->get_error_message() );
						continue;
					}

					$data = json_decode( wp_remote_retrieve_body( $res ), true );
					if ( ! $data || ( isset( $data['status'] ) && 200 !== (int) $data['status'] ) ) {
						$err_msg = isset( $data['msg'] ) ? sanitize_text_field( $data['msg'] ) : 'Remote upload error';
						$logs[] = sprintf( '[FAIL] Post #%d: Could not queue %s (%s). Original link preserved.', $p_id, esc_html( $url ), $err_msg );
						continue;
					}

					$filecode = isset( $data['result']['filecode'] ) ? sanitize_text_field( $data['result']['filecode'] ) : '';
					if ( empty( $filecode ) ) {
						$logs[] = sprintf( '[FAIL] Post #%d: Missing filecode for %s.', $p_id, esc_html( $url ) );
						continue;
					}

					// Insert into persistent queue
					$wpdb->insert(
						$table_queue,
						array(
							'post_id'             => $p_id,
							'original_url'        => $url,
							'filecode'            => $filecode,
							'state'               => 'pending',
							'original_repeatable' => maybe_serialize( $snapshot_meta ),
							'created_at'          => current_time( 'mysql' ),
							'updated_at'          => current_time( 'mysql' ),
							'status'              => 'pending',
						)
					);
					$queue_id = $wpdb->insert_id;

					// Query status immediately (in case file is instantly available or cached)
					$status_info = $this->query_file_info( $api_domain, $api_key, $filecode );
					if ( $status_info && isset( $status_info['canplay'] ) && 1 === (int) $status_info['canplay'] ) {
						$is_ready = true;
					} else {
						$cur_state = ( $status_info && isset( $status_info['state'] ) ) ? $status_info['state'] : 'pending';
						$logs[] = sprintf( '[QUEUED] Post #%d: File %s queued on PeeStream (%s). Original link untouched until ready.', $p_id, $filecode, $cur_state );
						continue;
					}
				}

				// Step 2: Video is verified READY (canplay=1). Apply update to DooPlay repeatable_fields!
				if ( $is_ready ) {
					$new_url = $player_domain . '/e/' . $filecode;

					// Check if this PeeStream player is already attached anywhere in the post (dedup)
					$already_present = false;
					foreach ( $meta as $check_s ) {
						if ( isset( $check_s['url'] ) && ( $check_s['url'] === $new_url || strpos( $check_s['url'], '/e/' . $filecode ) !== false ) ) {
							$already_present = true;
							break;
						}
					}

					if ( ! $already_present ) {
						// Create backup snapshot before modifying
						$wpdb->insert(
							$table_backups,
							array(
								'post_id'             => $p_id,
								'original_repeatable' => maybe_serialize( $snapshot_meta ),
								'migrated_url'        => $url,
								'new_code'            => $filecode,
								'backup_time'         => current_time( 'mysql' ),
								'status'              => 'completed',
							)
						);

						if ( 'replace' === $mode ) {
							$srv['url']  = $new_url;
							$srv['name'] = $server_name;
							$post_modified = true;
							$logs[] = sprintf( '[READY - REPLACED] Post #%d: Replaced %s with PeeStream %s (canplay=1).', $p_id, $detected['name'], $filecode );
						} else {
							// Primary mode: Prepend PeeStream as Server 1, preserve existing servers
							$new_entry = array(
								'name'    => $server_name,
								'select'  => 'iframe',
								'idioma'  => isset( $srv['idioma'] ) ? $srv['idioma'] : 'English',
								'quality' => isset( $srv['quality'] ) ? $srv['quality'] : '1080p',
								'url'     => $new_url,
							);
							array_unshift( $meta, $new_entry );
							$post_modified = true;
							$logs[] = sprintf( '[READY - PRIMARY] Post #%d: PeeStream %s attached as Server 1. Original %s retained as fallback.', $p_id, $filecode, $detected['name'] );
						}

						// Update queue status to completed
						$wpdb->update(
							$table_queue,
							array(
								'state'      => 'ready',
								'status'     => 'completed',
								'updated_at' => current_time( 'mysql' ),
							),
							array( 'post_id' => $p_id, 'original_url' => $url )
						);
					}
				}
			}
			unset( $srv );

			if ( $post_modified ) {
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

	/**
	 * Poll PeeStream API for single file status (/api/file/info)
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

		return isset( $body['result'] ) ? $body['result'] : false;
	}

	/**
	 * Poll pending queue items and update DooPlay repeatable_fields if ready
	 */
	public function ajax_check_queue() {
		check_ajax_referer( 'peestream_migrator_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		$api_key = ! empty( $this->options['api_key'] ) ? $this->options['api_key'] : '';
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key not configured.', 'peestream-wp-dooplay' ) ) );
		}

		$api_domain    = ! empty( $this->options['api_domain'] ) ? rtrim( $this->options['api_domain'], '/' ) : 'https://peestream.com';
		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$server_name   = ! empty( $this->options['default_server_name'] ) ? sanitize_text_field( $this->options['default_server_name'] ) : 'PeeStream HD';

		global $wpdb;
		$table_queue   = $wpdb->prefix . 'peestream_migration_queue';
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';

		$pending_items = $wpdb->get_results(
			"SELECT * FROM {$table_queue} WHERE status IN ('pending', 'queued', 'checking') LIMIT 25",
			ARRAY_A
		);

		$updated_count = 0;
		$logs = array();

		foreach ( $pending_items as $item ) {
			$p_id     = (int) $item['post_id'];
			$filecode = $item['filecode'];
			$orig_url = $item['original_url'];

			$info = $this->query_file_info( $api_domain, $api_key, $filecode );
			if ( ! $info ) {
				continue;
			}

			if ( isset( $info['canplay'] ) && 1 === (int) $info['canplay'] ) {
				// Video is ready!
				$meta = get_post_meta( $p_id, 'repeatable_fields', true );
				if ( is_string( $meta ) ) {
					$meta = maybe_unserialize( $meta );
				}
				if ( ! is_array( $meta ) ) {
					$meta = array();
				}

				$new_url = $player_domain . '/e/' . $filecode;

				// Check duplicate
				$already_present = false;
				foreach ( $meta as $check_s ) {
					if ( isset( $check_s['url'] ) && ( $check_s['url'] === $new_url || strpos( $check_s['url'], '/e/' . $filecode ) !== false ) ) {
						$already_present = true;
						break;
					}
				}

				if ( ! $already_present ) {
					// Save backup
					$wpdb->insert(
						$table_backups,
						array(
							'post_id'             => $p_id,
							'original_repeatable' => maybe_serialize( $meta ),
							'migrated_url'        => $orig_url,
							'new_code'            => $filecode,
							'backup_time'         => current_time( 'mysql' ),
							'status'              => 'completed',
						)
					);

					// Prepend as primary
					array_unshift( $meta, array(
						'name'    => $server_name,
						'select'  => 'iframe',
						'idioma'  => 'English',
						'quality' => '1080p',
						'url'     => $new_url,
					) );

					update_post_meta( $p_id, 'repeatable_fields', array_values( $meta ) );
				}

				$wpdb->update(
					$table_queue,
					array(
						'state'      => 'ready',
						'status'     => 'completed',
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => (int) $item['id'] )
				);

				$updated_count++;
				$logs[] = sprintf( '[READY] Post #%d: Video %s is ready! PeeStream player attached.', $p_id, $filecode );
			} elseif ( isset( $info['state'] ) && 'failed' === $info['state'] ) {
				$wpdb->update(
					$table_queue,
					array(
						'state'      => 'failed',
						'status'     => 'failed',
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => (int) $item['id'] )
				);
				$logs[] = sprintf( '[FAILED] Post #%d: PeeStream remote download failed for %s. Original link untouched.', $p_id, $filecode );
			}
		}

		wp_send_json_success( array(
			'checked_count' => count( $pending_items ),
			'ready_count'   => $updated_count,
			'logs'          => $logs,
		) );
	}

	/**
	 * Rollback migrated posts to their original state from backups table
	 */
	public function ajax_rollback() {
		check_ajax_referer( 'peestream_migrator_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		global $wpdb;
		$table_backups = $wpdb->prefix . 'peestream_migration_backups';

		$backups = $wpdb->get_results(
			"SELECT * FROM {$table_backups} WHERE status = 'completed' ORDER BY id DESC LIMIT 100",
			ARRAY_A
		);

		if ( empty( $backups ) ) {
			wp_send_json_error( array( 'message' => __( 'No active backups found to rollback.', 'peestream-wp-dooplay' ) ) );
		}

		$restored_count = 0;
		$logs = array();

		foreach ( $backups as $b ) {
			$post_id  = (int) $b['post_id'];
			$original = maybe_unserialize( $b['original_repeatable'] );

			if ( is_array( $original ) ) {
				update_post_meta( $post_id, 'repeatable_fields', $original );
				$wpdb->update(
					$table_backups,
					array( 'status' => 'rolled_back' ),
					array( 'id' => (int) $b['id'] )
				);
				$restored_count++;
				$logs[] = sprintf( '[ROLLBACK] Post #%d: Restored original DooPlay video servers.', $post_id );
			}
		}

		wp_send_json_success( array(
			'restored_count' => $restored_count,
			'logs'           => $logs,
			'message'        => sprintf( __( 'Rollback completed: %d posts restored.', 'peestream-wp-dooplay' ), $restored_count ),
		) );
	}
}
