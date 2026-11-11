<?php
/**
 * PeeStream DooPlay Metabox & Player Formatter
 * File: includes/class-peestream-metabox.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PeeStream_Metabox {

	private $options;

	public function __construct() {
		$this->options = get_option( 'peestream_settings', array() );

		add_action( 'add_meta_boxes', array( $this, 'register_metaboxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_metabox_assets' ) );
		
		// AJAX endpoints
		add_action( 'wp_ajax_peestream_add_player', array( $this, 'ajax_add_player' ) );
		add_action( 'wp_ajax_peestream_remove_player', array( $this, 'ajax_remove_player' ) );
		add_action( 'wp_ajax_peestream_metabox_remote_upload', array( $this, 'ajax_remote_upload' ) );
		add_action( 'wp_ajax_peestream_metabox_imdb_upload', array( $this, 'ajax_imdb_upload' ) );
	}

	public function register_metaboxes() {
		$supported_post_types = array( 'movies', 'episodes', 'post' );
		
		// Allow filtering supported post types
		$supported_post_types = apply_filters( 'peestream_supported_post_types', $supported_post_types );

		foreach ( $supported_post_types as $pt ) {
			add_meta_box(
				'peestream_dooplay_box',
				__( 'PeeStream Video Player & DooPlay Integration', 'peestream-wp-dooplay' ),
				array( $this, 'render_metabox' ),
				$pt,
				'normal',
				'high'
			);
		}
	}

	public function enqueue_metabox_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'peestream-admin-css',
			PEESTREAM_PLUGIN_URL . 'assets/css/peestream-admin.css',
			array(),
			PEESTREAM_VERSION
		);

		wp_enqueue_script(
			'peestream-metabox-js',
			PEESTREAM_PLUGIN_URL . 'assets/js/peestream-metabox.js',
			array( 'jquery' ),
			PEESTREAM_VERSION,
			true
		);

		wp_localize_script(
			'peestream-metabox-js',
			'peestreamMetaboxData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'peestream_metabox_nonce' ),
				'i18n'     => array(
					'confirm_remove' => __( 'Are you sure you want to remove this video player server?', 'peestream-wp-dooplay' ),
					'submitting'     => __( 'Connecting to PeeStream...', 'peestream-wp-dooplay' ),
					'success_add'    => __( 'Player server added to DooPlay successfully!', 'peestream-wp-dooplay' ),
					'empty_code'     => __( 'Please enter a valid video code or embed URL.', 'peestream-wp-dooplay' ),
					'empty_url'      => __( 'Please enter a video URL to upload.', 'peestream-wp-dooplay' ),
					'empty_imdb'     => __( 'Please enter an IMDb ID (e.g. tt1234567).', 'peestream-wp-dooplay' ),
				),
			)
		);
	}

	public function render_metabox( $post ) {
		$repeatable = get_post_meta( $post->ID, 'repeatable_fields', true );
		if ( is_string( $repeatable ) ) {
			$repeatable = maybe_unserialize( $repeatable );
		}
		if ( ! is_array( $repeatable ) ) {
			$repeatable = array();
		}

		$default_server_name = ! empty( $this->options['default_server_name'] ) ? $this->options['default_server_name'] : 'PeeStream HD';
		$default_language    = ! empty( $this->options['default_language'] ) ? $this->options['default_language'] : 'English';
		$default_quality     = ! empty( $this->options['default_quality'] ) ? $this->options['default_quality'] : '1080p';
		$player_domain       = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';

		?>
		<div class="peestream-mb-container" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<!-- Notification banner -->
			<div id="pst_mb_alert" class="peestream-mb-alert" style="display:none;"></div>

			<!-- Current DooPlay Video Servers Table -->
			<div class="peestream-mb-current-servers">
				<h4 class="peestream-mb-section-title">
					<span class="dashicons dashicons-format-video"></span>
					<?php esc_html_e( 'Current Video Servers in DooPlay', 'peestream-wp-dooplay' ); ?>
					<span class="peestream-badge-count" id="pst_servers_count"><?php echo count( $repeatable ); ?></span>
				</h4>

				<div id="pst_servers_table_wrapper">
					<?php $this->render_servers_table( $repeatable, $post->ID ); ?>
				</div>
			</div>

			<!-- Tabs Navigation -->
			<div class="peestream-mb-tabs-nav">
				<button type="button" class="peestream-mb-tab-btn active" data-tab="quick">
					<span class="dashicons dashicons-embed-video"></span> <?php esc_html_e( '1. Quick Embed', 'peestream-wp-dooplay' ); ?>
				</button>
				<button type="button" class="peestream-mb-tab-btn" data-tab="remote">
					<span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e( '2. Remote URL Upload', 'peestream-wp-dooplay' ); ?>
				</button>
				<button type="button" class="peestream-mb-tab-btn" data-tab="imdb">
					<span class="dashicons dashicons-tickets-alt"></span> <?php esc_html_e( '3. IMDb Importer', 'peestream-wp-dooplay' ); ?>
				</button>
			</div>

			<!-- Tab 1: Quick Embed -->
			<div class="peestream-mb-tab-pane active" id="pst_tab_quick">
				<div class="peestream-mb-grid">
					<div class="peestream-mb-field peestream-mb-col-12">
						<label for="pst_quick_code"><strong><?php esc_html_e( 'PeeStream Video Code or Embed URL:', 'peestream-wp-dooplay' ); ?></strong></label>
						<input type="text" id="pst_quick_code" class="widefat" placeholder="e.g. eK7g1xY9 or https://peeplayer.com/e/eK7g1xY9">
						<span class="description"><?php esc_html_e( 'Enter the unique file code or the full iframe URL.', 'peestream-wp-dooplay' ); ?></span>
					</div>

					<div class="peestream-mb-field peestream-mb-col-4">
						<label for="pst_quick_name"><?php esc_html_e( 'Server Name:', 'peestream-wp-dooplay' ); ?></label>
						<input type="text" id="pst_quick_name" class="widefat" value="<?php echo esc_attr( $default_server_name ); ?>">
					</div>

					<div class="peestream-mb-field peestream-mb-col-4">
						<label for="pst_quick_lang"><?php esc_html_e( 'Audio Language:', 'peestream-wp-dooplay' ); ?></label>
						<select id="pst_quick_lang" class="widefat">
							<?php
							$languages = array( 'English', 'Spanish', 'Subbed', 'Latino', 'Portuguese', 'French', 'German', 'Italian', 'Russian', 'Turkish', 'Arabic', 'Hindi', 'Japanese' );
							foreach ( $languages as $l ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $l ), selected( $default_language, $l, false ), esc_html( $l ) );
							}
							?>
						</select>
					</div>

					<div class="peestream-mb-field peestream-mb-col-4">
						<label for="pst_quick_quality"><?php esc_html_e( 'Quality Badge:', 'peestream-wp-dooplay' ); ?></label>
						<select id="pst_quick_quality" class="widefat">
							<?php
							$qualities = array( '1080p', '720p', '4K UltraHD', 'HD', 'CAM' );
							foreach ( $qualities as $q ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $q ), selected( $default_quality, $q, false ), esc_html( $q ) );
							}
							?>
						</select>
					</div>

					<div class="peestream-mb-field peestream-mb-col-6">
						<label for="pst_quick_position"><?php esc_html_e( 'Player Position in DooPlay:', 'peestream-wp-dooplay' ); ?></label>
						<select id="pst_quick_position" class="widefat">
							<option value="primary"><?php esc_html_e( 'Server 1 (Primary / Top Tab)', 'peestream-wp-dooplay' ); ?></option>
							<option value="append" selected><?php esc_html_e( 'Append to end of list', 'peestream-wp-dooplay' ); ?></option>
						</select>
					</div>

					<div class="peestream-mb-field peestream-mb-col-6 peestream-mb-align-bottom">
						<button type="button" class="button button-primary button-large" id="pst_btn_add_quick">
							<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add to DooPlay Players', 'peestream-wp-dooplay' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Tab 2: Remote URL Upload -->
			<div class="peestream-mb-tab-pane" id="pst_tab_remote">
				<div class="peestream-mb-grid">
					<div class="peestream-mb-field peestream-mb-col-12">
						<label for="pst_remote_url"><strong><?php esc_html_e( 'Remote Video Link (MP4 / Direct / Competitor URL):', 'peestream-wp-dooplay' ); ?></strong></label>
						<input type="url" id="pst_remote_url" class="widefat" placeholder="https://example.com/video.mp4 or MixDrop / Streamtape / Doodstream URL">
						<span class="description"><?php esc_html_e( 'PeeStream will download, transcode into HLS, and attach the player automatically.', 'peestream-wp-dooplay' ); ?></span>
					</div>

					<div class="peestream-mb-field peestream-mb-col-8">
						<label for="pst_remote_title"><?php esc_html_e( 'Custom Video Title (Optional):', 'peestream-wp-dooplay' ); ?></label>
						<input type="text" id="pst_remote_title" class="widefat" value="<?php echo esc_attr( $post->post_title ); ?>">
					</div>

					<div class="peestream-mb-field peestream-mb-col-4 peestream-mb-align-bottom">
						<button type="button" class="button button-primary button-large widefat" id="pst_btn_submit_remote">
							<span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e( 'Upload & Attach Server', 'peestream-wp-dooplay' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Tab 3: IMDb Importer -->
			<div class="peestream-mb-tab-pane" id="pst_tab_imdb">
				<div class="peestream-mb-grid">
					<div class="peestream-mb-field peestream-mb-col-4">
						<label for="pst_imdb_id"><strong><?php esc_html_e( 'IMDb Identifier (ID):', 'peestream-wp-dooplay' ); ?></strong></label>
						<?php
						$current_imdb = get_post_meta( $post->ID, 'imdb_id', true );
						if ( empty( $current_imdb ) ) {
							$current_imdb = get_post_meta( $post->ID, 'id', true );
						}
						?>
						<input type="text" id="pst_imdb_id" class="widefat" value="<?php echo esc_attr( $current_imdb ); ?>" placeholder="e.g. tt0111161">
					</div>

					<div class="peestream-mb-field peestream-mb-col-4">
						<label for="pst_imdb_season"><?php esc_html_e( 'Season (0 = Movie):', 'peestream-wp-dooplay' ); ?></label>
						<?php
						$season = get_post_meta( $post->ID, 'temporada', true );
						?>
						<input type="number" id="pst_imdb_season" class="widefat" min="0" value="<?php echo esc_attr( $season ? $season : '0' ); ?>">
					</div>

					<div class="peestream-mb-field peestream-mb-col-4">
						<label for="pst_imdb_episode"><?php esc_html_e( 'Episode (0 = Movie):', 'peestream-wp-dooplay' ); ?></label>
						<?php
						$episode = get_post_meta( $post->ID, 'episodio', true );
						?>
						<input type="number" id="pst_imdb_episode" class="widefat" min="0" value="<?php echo esc_attr( $episode ? $episode : '0' ); ?>">
					</div>

					<div class="peestream-mb-field peestream-mb-col-12" style="text-align:right;">
						<button type="button" class="button button-primary button-large" id="pst_btn_submit_imdb">
							<span class="dashicons dashicons-tickets-alt"></span> <?php esc_html_e( 'Queue IMDb Video & Attach', 'peestream-wp-dooplay' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_servers_table( $repeatable, $post_id ) {
		if ( empty( $repeatable ) || ! is_array( $repeatable ) ) {
			echo '<div class="peestream-no-servers">' . esc_html__( 'No video players currently configured in DooPlay for this post.', 'peestream-wp-dooplay' ) . '</div>';
			return;
		}

		echo '<table class="peestream-mb-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>#</th>';
		echo '<th>' . esc_html__( 'Server Name', 'peestream-wp-dooplay' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'peestream-wp-dooplay' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'peestream-wp-dooplay' ) . '</th>';
		echo '<th>' . esc_html__( 'Quality', 'peestream-wp-dooplay' ) . '</th>';
		echo '<th>' . esc_html__( 'Player URL', 'peestream-wp-dooplay' ) . '</th>';
		echo '<th style="width:80px;text-align:center;">' . esc_html__( 'Action', 'peestream-wp-dooplay' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $repeatable as $index => $row ) {
			$name    = isset( $row['name'] ) ? $row['name'] : 'Server ' . ( $index + 1 );
			$select  = isset( $row['select'] ) ? $row['select'] : 'iframe';
			$lang    = isset( $row['idioma'] ) ? $row['idioma'] : '-';
			$quality = isset( $row['quality'] ) ? $row['quality'] : '-';
			$url     = isset( $row['url'] ) ? $row['url'] : '';

			$is_peestream = ( stripos( $url, 'peeplayer.com' ) !== false || stripos( $url, 'peestream.com' ) !== false );

			echo '<tr>';
			printf( '<td><strong>%d</strong></td>', (int) $index + 1 );
			printf( '<td>%s %s</td>', esc_html( $name ), $is_peestream ? '<span class="peestream-tag-pst">PeeStream</span>' : '' );
			printf( '<td><code>%s</code></td>', esc_html( $select ) );
			printf( '<td>%s</td>', esc_html( $lang ) );
			printf( '<td><span class="peestream-quality-badge">%s</span></td>', esc_html( $quality ) );
			printf( '<td class="peestream-url-cell" title="%s">%s</td>', esc_attr( $url ), esc_html( $url ) );
			printf(
				'<td style="text-align:center;"><button type="button" class="button button-small button-link-delete pst-btn-remove-row" data-index="%d"><span class="dashicons dashicons-trash"></span></button></td>',
				(int) $index
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public function ajax_add_player() {
		check_ajax_referer( 'peestream_metabox_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		$raw_code = isset( $_POST['code'] ) ? sanitize_text_field( trim( $_POST['code'] ) ) : '';
		$code     = $this->extract_code( $raw_code );

		if ( empty( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid video code or embed link.', 'peestream-wp-dooplay' ) ) );
		}

		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$embed_url     = $player_domain . '/e/' . $code;

		$server_name = isset( $_POST['name'] ) ? sanitize_text_field( trim( $_POST['name'] ) ) : 'PeeStream HD';
		$language    = isset( $_POST['language'] ) ? sanitize_text_field( trim( $_POST['language'] ) ) : 'English';
		$quality     = isset( $_POST['quality'] ) ? sanitize_text_field( trim( $_POST['quality'] ) ) : '1080p';
		$position    = isset( $_POST['position'] ) ? sanitize_key( $_POST['position'] ) : 'append';

		$repeatable = get_post_meta( $post_id, 'repeatable_fields', true );
		if ( is_string( $repeatable ) ) {
			$repeatable = maybe_unserialize( $repeatable );
		}
		if ( ! is_array( $repeatable ) ) {
			$repeatable = array();
		}

		// Prevent duplicate server entries
		foreach ( $repeatable as $existing_srv ) {
			$ex_url = isset( $existing_srv['url'] ) ? $existing_srv['url'] : '';
			if ( $ex_url === $embed_url || $this->extract_code( $ex_url ) === $code ) {
				wp_send_json_error( array( 'message' => __( 'This PeeStream video is already attached to this post.', 'peestream-wp-dooplay' ) ) );
				return;
			}
		}

		$new_entry = array(
			'name'    => $server_name,
			'select'  => 'iframe',
			'idioma'  => $language,
			'quality' => $quality,
			'url'     => $embed_url,
		);

		if ( 'primary' === $position ) {
			array_unshift( $repeatable, $new_entry );
		} else {
			$repeatable[] = $new_entry;
		}

		// Re-index array keys cleanly
		$repeatable = array_values( $repeatable );

		update_post_meta( $post_id, 'repeatable_fields', $repeatable );
		update_post_meta( $post_id, 'peestream_code', $code );
		update_post_meta( $post_id, 'peestream_embed_url', $embed_url );

		ob_start();
		$this->render_servers_table( $repeatable, $post_id );
		$table_html = ob_get_clean();

		wp_send_json_success( array(
			'message'      => __( 'Player added to DooPlay successfully!', 'peestream-wp-dooplay' ),
			'table_html'   => $table_html,
			'count'        => count( $repeatable ),
			'file_code'    => $code,
			'embed_url'    => $embed_url,
		) );
	}

	public function ajax_remove_player() {
		check_ajax_referer( 'peestream_metabox_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$index   = isset( $_POST['index'] ) ? intval( $_POST['index'] ) : -1;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || $index < 0 ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied or invalid index.', 'peestream-wp-dooplay' ) ) );
		}

		$repeatable = get_post_meta( $post_id, 'repeatable_fields', true );
		if ( is_string( $repeatable ) ) {
			$repeatable = maybe_unserialize( $repeatable );
		}
		if ( ! is_array( $repeatable ) || ! isset( $repeatable[ $index ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Server entry not found.', 'peestream-wp-dooplay' ) ) );
		}

		unset( $repeatable[ $index ] );
		$repeatable = array_values( $repeatable );

		update_post_meta( $post_id, 'repeatable_fields', $repeatable );

		ob_start();
		$this->render_servers_table( $repeatable, $post_id );
		$table_html = ob_get_clean();

		wp_send_json_success( array(
			'message'    => __( 'Player removed successfully.', 'peestream-wp-dooplay' ),
			'table_html' => $table_html,
			'count'      => count( $repeatable ),
		) );
	}

	public function ajax_remote_upload() {
		check_ajax_referer( 'peestream_metabox_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		$url   = isset( $_POST['url'] ) ? esc_url_raw( trim( $_POST['url'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( trim( $_POST['title'] ) ) : '';

		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid video link.', 'peestream-wp-dooplay' ) ) );
		}

		$api_key = ! empty( $this->options['api_key'] ) ? $this->options['api_key'] : '';
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'PeeStream API key is not configured.', 'peestream-wp-dooplay' ) ) );
		}

		$api_domain = ! empty( $this->options['api_domain'] ) ? rtrim( $this->options['api_domain'], '/' ) : 'https://peestream.com';
		if ( 0 !== strpos( $api_domain, 'https://' ) ) {
			$api_domain = 'https://peestream.com';
		}
		$fld_id     = isset( $this->options['default_folder_id'] ) ? absint( $this->options['default_folder_id'] ) : 0;

		$endpoint = $api_domain . '/api/upload/url?' . http_build_query( array(
			'key'       => $api_key,
			'url'       => $url,
			'new_title' => $title,
			'fld_id'    => $fld_id,
		) );

		$response = wp_safe_remote_get( $endpoint, array(
			'timeout'    => 25,
			'sslverify'  => true,
			'user-agent' => 'PeeStream-WP-DooPlay/' . PEESTREAM_VERSION,
		) );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! $data || ( isset( $data['status'] ) && 200 !== (int) $data['status'] ) ) {
			$err = isset( $data['msg'] ) ? sanitize_text_field( $data['msg'] ) : __( 'Failed to queue video on PeeStream.', 'peestream-wp-dooplay' );
			wp_send_json_error( array( 'message' => $err ) );
		}

		$filecode = isset( $data['result']['filecode'] ) ? sanitize_text_field( $data['result']['filecode'] ) : '';
		if ( empty( $filecode ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing filecode in PeeStream API response.', 'peestream-wp-dooplay' ) ) );
		}

		wp_send_json_success( array(
			'filecode' => $filecode,
			'message'  => __( 'Video queued successfully on PeeStream!', 'peestream-wp-dooplay' ),
		) );
	}

	public function ajax_imdb_upload() {
		check_ajax_referer( 'peestream_metabox_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'peestream-wp-dooplay' ) ) );
		}

		$imdb = isset( $_POST['imdb'] ) ? sanitize_text_field( trim( $_POST['imdb'] ) ) : '';
		$s    = isset( $_POST['season'] ) ? absint( $_POST['season'] ) : 0;
		$ep   = isset( $_POST['episode'] ) ? absint( $_POST['episode'] ) : 0;

		if ( empty( $imdb ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter an IMDb identifier.', 'peestream-wp-dooplay' ) ) );
		}

		$api_key = ! empty( $this->options['api_key'] ) ? $this->options['api_key'] : '';
		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'PeeStream API key is not configured.', 'peestream-wp-dooplay' ) ) );
		}

		$api_domain = ! empty( $this->options['api_domain'] ) ? rtrim( $this->options['api_domain'], '/' ) : 'https://peestream.com';
		if ( 0 !== strpos( $api_domain, 'https://' ) ) {
			$api_domain = 'https://peestream.com';
		}
		$fld_id     = isset( $this->options['default_folder_id'] ) ? absint( $this->options['default_folder_id'] ) : 0;

		$endpoint = $api_domain . '/api/upload/imdb?' . http_build_query( array(
			'key'     => $api_key,
			'imdb'    => $imdb,
			'season'  => $s,
			'episode' => $ep,
			'fld_id'  => $fld_id,
		) );

		$response = wp_safe_remote_get( $endpoint, array(
			'timeout'    => 25,
			'sslverify'  => true,
			'user-agent' => 'PeeStream-WP-DooPlay/' . PEESTREAM_VERSION,
		) );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! $data || ( isset( $data['status'] ) && 200 !== (int) $data['status'] ) ) {
			$err = isset( $data['msg'] ) ? sanitize_text_field( $data['msg'] ) : __( 'Failed to import IMDb title.', 'peestream-wp-dooplay' );
			wp_send_json_error( array( 'message' => $err ) );
		}

		$filecode = isset( $data['result']['filecode'] ) ? sanitize_text_field( $data['result']['filecode'] ) : '';
		if ( empty( $filecode ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing filecode in PeeStream API response.', 'peestream-wp-dooplay' ) ) );
		}

		wp_send_json_success( array(
			'filecode' => $filecode,
			'message'  => __( 'IMDb title reserved and queued on PeeStream!', 'peestream-wp-dooplay' ),
		) );
	}

	private function extract_code( $input ) {
		$input = trim( $input );
		if ( preg_match( '#/(?:e|v|embed)/([a-zA-Z0-9_\-]+)#i', $input, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#^[a-zA-Z0-9_\-]+$#', $input ) ) {
			return $input;
		}
		return '';
	}
}
