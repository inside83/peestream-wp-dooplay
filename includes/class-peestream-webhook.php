<?php
/**
 * PeeStream REST API Webhook Receiver (Hardened)
 * File: includes/class-peestream-webhook.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PeeStream_Webhook {

	private $options;

	// Permitted webhook events from PeeStream backend
	private $allowed_events = array(
		'video_ready',
		'new_episode_ready',
		'movie_ready',
	);

	public function __construct() {
		$this->options = get_option( 'peestream_settings', array() );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'peestream/v1', '/webhook', array(
			'methods'             => WP_REST_Server::CREATABLE, // POST only
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => array( $this, 'verify_permission' ),
		) );
	}

	public function verify_permission( WP_REST_Request $request ) {
		$configured_secret = ! empty( $this->options['webhook_secret'] ) ? trim( (string) $this->options['webhook_secret'] ) : '';

		// Fail-closed: Reject if secret is missing or too weak (< 16 chars)
		if ( empty( $configured_secret ) || strlen( $configured_secret ) < 16 ) {
			return new WP_Error(
				'peestream_no_secret',
				__( 'Webhook secret is not configured or too weak (min 16 chars). Request rejected.', 'peestream-wp-dooplay' ),
				array( 'status' => 403 )
			);
		}

		$incoming_secret = $request->get_param( 'secret' );
		if ( empty( $incoming_secret ) ) {
			$incoming_secret = $request->get_header( 'x-webhook-secret' );
		}

		if ( empty( $incoming_secret ) || ! hash_equals( (string) $configured_secret, (string) $incoming_secret ) ) {
			return new WP_Error(
				'peestream_invalid_secret',
				__( 'Access denied. Invalid webhook secret token.', 'peestream-wp-dooplay' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function handle_webhook( WP_REST_Request $request ) {
		// Strict POST check
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Method not allowed. Webhook endpoint requires POST.', 'peestream-wp-dooplay' ),
			), 405 );
		}

		// Verify secret permissions
		$permission = $this->verify_permission( $request );
		if ( is_wp_error( $permission ) ) {
			$data = $permission->get_error_data();
			$status = isset( $data['status'] ) ? (int) $data['status'] : 401;
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => $permission->get_error_message(),
			), $status );
		}

		global $wpdb;

		// 1. Rate Limiting Check (Max 60 requests per minute per IP)
		$client_ip = '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$client_ip = trim( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$client_ip = trim( $ips[0] );
		} else {
			$client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
		}
		$client_ip = substr( preg_replace( '/[^0-9a-fA-F:.]/', '', $client_ip ), 0, 45 );

		$table_webhooks = $wpdb->prefix . 'peestream_webhooks';
		$rate_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_webhooks} WHERE ip = %s AND received_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
			$client_ip
		) );

		if ( $rate_count > 60 ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Rate limit exceeded (max 60 requests per minute).', 'peestream-wp-dooplay' ),
			), 429 );
		}

		// 2. Parse JSON payload
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		// Extract & Validate file_code
		$file_code = '';
		if ( ! empty( $params['file_code'] ) ) {
			$file_code = trim( (string) $params['file_code'] );
		} elseif ( ! empty( $params['filecode'] ) ) {
			$file_code = trim( (string) $params['filecode'] );
		}

		if ( empty( $file_code ) || ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $file_code ) || strlen( $file_code ) > 64 ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Missing or invalid file_code parameter.', 'peestream-wp-dooplay' ),
			), 400 );
		}

		// Validate Event Type
		$event = isset( $params['event'] ) ? sanitize_key( $params['event'] ) : 'video_ready';
		if ( ! in_array( $event, $this->allowed_events, true ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => sprintf( __( 'Unsupported event: %s', 'peestream-wp-dooplay' ), esc_html( $event ) ),
			), 400 );
		}

		// 3. Replay Protection: Check if this file_code & event has already been processed
		$existing_record = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, post_id FROM {$table_webhooks} WHERE file_code = %s AND event = %s AND status = 'processed' LIMIT 1",
			$file_code,
			$event
		) );

		if ( $existing_record ) {
			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'replay_ignored',
				'post_id'   => (int) $existing_record->post_id,
				'file_code' => $file_code,
				'message'   => __( 'Duplicate webhook event received. Video already processed.', 'peestream-wp-dooplay' ),
			), 200 );
		}

		// 4. Extract identifiers
		$imdb_id = isset( $params['imdb_id'] ) ? trim( (string) $params['imdb_id'] ) : ( isset( $params['imdb'] ) ? trim( (string) $params['imdb'] ) : '' );
		if ( ! empty( $imdb_id ) && ! preg_match( '/^tt\d{5,12}$/i', $imdb_id ) ) {
			$imdb_id = '';
		}

		$tmdb_id = isset( $params['tmdb_id'] ) ? absint( $params['tmdb_id'] ) : 0;
		$season  = isset( $params['season'] ) ? absint( $params['season'] ) : 0;
		$episode = isset( $params['episode'] ) ? absint( $params['episode'] ) : 0;

		$title = isset( $params['title'] ) ? sanitize_text_field( trim( wp_strip_all_tags( $params['title'] ) ) ) : 'Video';
		$series_title = isset( $params['series_title'] ) ? sanitize_text_field( trim( wp_strip_all_tags( $params['series_title'] ) ) ) : '';

		$poster_url = '';
		if ( ! empty( $params['poster'] ) && filter_var( $params['poster'], FILTER_VALIDATE_URL ) ) {
			$poster_url = esc_url_raw( trim( $params['poster'] ) );
		} elseif ( ! empty( $params['poster_url'] ) && filter_var( $params['poster_url'], FILTER_VALIDATE_URL ) ) {
			$poster_url = esc_url_raw( trim( $params['poster_url'] ) );
		}

		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$server_name   = ! empty( $this->options['default_server_name'] ) ? $this->options['default_server_name'] : 'PeeStream HD';
		$language      = ! empty( $this->options['default_language'] ) ? $this->options['default_language'] : 'English';
		$quality       = ! empty( $this->options['default_quality'] ) ? $this->options['default_quality'] : '1080p';
		$auto_create   = ! empty( $this->options['auto_create_posts'] ) && '1' === (string) $this->options['auto_create_posts'];

		$embed_url = $player_domain . '/e/' . $file_code;

		$server_entry = array(
			'name'    => $server_name,
			'select'  => 'iframe',
			'idioma'  => $language,
			'quality' => $quality,
			'url'     => $embed_url,
		);

		// CASE 1: TV Episode
		if ( $season > 0 && $episode > 0 ) {
			return $this->process_episode( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $series_title, $season, $episode, $poster_url, $auto_create, $client_ip, $event );
		}

		// CASE 2: Movie or Generic Video
		return $this->process_movie( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $poster_url, $auto_create, $client_ip, $event );
	}

	private function process_episode( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $series_title, $season, $episode, $poster_url, $auto_create, $client_ip, $event ) {
		global $wpdb;
		$table_webhooks = $wpdb->prefix . 'peestream_webhooks';
		$now_date = current_time( 'mysql' );

		// 1. Strict Parent TV Show Matching (Must match verified IMDb ID or TMDb ID)
		$parent_show_id = 0;

		if ( ! empty( $imdb_id ) ) {
			$parent_show_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND (pm.meta_key = 'ids' OR pm.meta_key = 'imdb_id'))
				 WHERE p.post_type = 'tvshows' AND pm.meta_value = %s LIMIT 1",
				$imdb_id
			) );
		}

		if ( ! $parent_show_id && $tmdb_id > 0 ) {
			$parent_show_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND (pm.meta_key = 'id' OR pm.meta_key = 'tmdb_id'))
				 WHERE p.post_type = 'tvshows' AND pm.meta_value = %d LIMIT 1",
				$tmdb_id
			) );
		}

		// 2. Strict Episode Matching Within That Verified TV Show ONLY
		$matched_ep_id = 0;
		if ( $parent_show_id > 0 ) {
			$show_name = get_the_title( $parent_show_id );

			// In DooPlay, episodes link to parent show via postmeta 'serie' (show title or show ID)
			$query = "
				SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_s ON (p.ID = pm_s.post_id AND pm_s.meta_key = 'temporada' AND pm_s.meta_value = %d)
				INNER JOIN {$wpdb->postmeta} pm_e ON (p.ID = pm_e.post_id AND pm_e.meta_key = 'episodio' AND pm_e.meta_value = %d)
				INNER JOIN {$wpdb->postmeta} pm_p ON (p.ID = pm_p.post_id AND pm_p.meta_key = 'serie' AND (pm_p.meta_value = %s OR pm_p.meta_value = %s))
				WHERE p.post_type = 'episodes' AND p.post_status IN ('publish', 'draft') LIMIT 1
			";

			$matched_ep_id = (int) $wpdb->get_var( $wpdb->prepare( $query, $season, $episode, $show_name, (string) $parent_show_id ) );
		}

		// A. Update Existing Verified Episode
		if ( $matched_ep_id > 0 ) {
			$this->attach_server_to_post( $matched_ep_id, $server_entry, $file_code, $embed_url );

			$wpdb->insert( $table_webhooks, array(
				'file_code'   => $file_code,
				'event'       => $event,
				'post_id'     => $matched_ep_id,
				'ip'          => $client_ip,
				'received_at' => $now_date,
				'status'      => 'processed',
			) );

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'episode_updated',
				'post_id'   => $matched_ep_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'Verified episode #%d updated with PeeStream player.', $matched_ep_id ),
			), 200 );
		}

		// B. Auto-Create Episode Post (ONLY if parent TV show is verified AND auto-create is explicitly enabled)
		if ( $auto_create && $parent_show_id > 0 ) {
			$author_id = $this->get_default_author_id();
			$show_name = get_the_title( $parent_show_id );
			$ep_post_title = sprintf( '%s: %dx%d', $show_name, $season, $episode );

			$new_post_id = wp_insert_post( array(
				'post_title'   => $ep_post_title,
				'post_content' => sprintf( 'Watch %s Season %d Episode %d online in full HD.', esc_html( $show_name ), $season, $episode ),
				'post_status'  => 'draft', // Draft status for safe review
				'post_type'    => 'episodes',
				'post_author'  => $author_id,
			) );

			if ( is_wp_error( $new_post_id ) ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => $new_post_id->get_error_message() ), 500 );
			}

			// Assign DooPlay metadata
			update_post_meta( $new_post_id, 'temporada', $season );
			update_post_meta( $new_post_id, 'episodio', $episode );
			update_post_meta( $new_post_id, 'serie', $show_name );
			if ( ! empty( $imdb_id ) ) {
				update_post_meta( $new_post_id, 'ids', $imdb_id );
			}
			if ( ! empty( $poster_url ) ) {
				update_post_meta( $new_post_id, 'dt_backdrop', $poster_url );
			}

			$this->attach_server_to_post( $new_post_id, $server_entry, $file_code, $embed_url );

			$wpdb->insert( $table_webhooks, array(
				'file_code'   => $file_code,
				'event'       => $event,
				'post_id'     => $new_post_id,
				'ip'          => $client_ip,
				'received_at' => $now_date,
				'status'      => 'processed',
			) );

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'episode_created',
				'post_id'   => $new_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'New episode #%d created as draft with PeeStream player attached.', $new_post_id ),
			), 201 );
		}

		// C. Ambiguous / Unmatched: Audit and leave untouched
		$wpdb->insert( $table_webhooks, array(
			'file_code'   => $file_code,
			'event'       => $event,
			'post_id'     => 0,
			'ip'          => $client_ip,
			'received_at' => $now_date,
			'status'      => 'unmatched',
		) );

		return new WP_REST_Response( array(
			'success'   => true,
			'action'    => 'unmatched',
			'file_code' => $file_code,
			'message'   => 'Ambiguous episode or parent TV show not found by verified IMDb/TMDb ID. Logged for manual confirmation.',
		), 200 );
	}

	private function process_movie( $file_code, $embed_url, $server_entry, $imdb_id, $tmdb_id, $title, $poster_url, $auto_create, $client_ip, $event ) {
		global $wpdb;
		$table_webhooks = $wpdb->prefix . 'peestream_webhooks';
		$now_date = current_time( 'mysql' );

		$matched_post_id = 0;

		// 1. Strict IMDb Match
		if ( ! empty( $imdb_id ) ) {
			$matched_post_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND (pm.meta_key = 'ids' OR pm.meta_key = 'imdb_id'))
				 WHERE p.post_type IN ('movies', 'post') AND pm.meta_value = %s LIMIT 1",
				$imdb_id
			) );
		}

		// 2. Strict TMDb Match
		if ( ! $matched_post_id && $tmdb_id > 0 ) {
			$matched_post_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND (pm.meta_key = 'id' OR pm.meta_key = 'tmdb_id'))
				 WHERE p.post_type IN ('movies', 'post') AND pm.meta_value = %d LIMIT 1",
				$tmdb_id
			) );
		}

		// A. Update Existing Movie
		if ( $matched_post_id > 0 ) {
			$this->attach_server_to_post( $matched_post_id, $server_entry, $file_code, $embed_url );

			$wpdb->insert( $table_webhooks, array(
				'file_code'   => $file_code,
				'event'       => $event,
				'post_id'     => $matched_post_id,
				'ip'          => $client_ip,
				'received_at' => $now_date,
				'status'      => 'processed',
			) );

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'movie_updated',
				'post_id'   => $matched_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'Verified movie #%d updated with PeeStream player.', $matched_post_id ),
			), 200 );
		}

		// B. Auto-Create Movie (Draft only, if enabled)
		if ( $auto_create ) {
			$target_cpt = ! empty( $this->options['default_post_type'] ) ? $this->options['default_post_type'] : 'movies';
			$author_id  = $this->get_default_author_id();

			$new_post_id = wp_insert_post( array(
				'post_title'   => $title,
				'post_content' => sprintf( 'Watch %s online in full HD.', esc_html( $title ) ),
				'post_status'  => 'draft',
				'post_type'    => $target_cpt,
				'post_author'  => $author_id,
			) );

			if ( is_wp_error( $new_post_id ) ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => $new_post_id->get_error_message() ), 500 );
			}

			if ( ! empty( $imdb_id ) ) {
				update_post_meta( $new_post_id, 'ids', $imdb_id );
			}
			if ( ! empty( $poster_url ) ) {
				update_post_meta( $new_post_id, 'dt_poster', $poster_url );
			}

			$this->attach_server_to_post( $new_post_id, $server_entry, $file_code, $embed_url );

			$wpdb->insert( $table_webhooks, array(
				'file_code'   => $file_code,
				'event'       => $event,
				'post_id'     => $new_post_id,
				'ip'          => $client_ip,
				'received_at' => $now_date,
				'status'      => 'processed',
			) );

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'movie_created',
				'post_id'   => $new_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'New movie #%d created as draft with PeeStream player attached.', $new_post_id ),
			), 201 );
		}

		// C. Unmatched Movie
		$wpdb->insert( $table_webhooks, array(
			'file_code'   => $file_code,
			'event'       => $event,
			'post_id'     => 0,
			'ip'          => $client_ip,
			'received_at' => $now_date,
			'status'      => 'unmatched',
		) );

		return new WP_REST_Response( array(
			'success'   => true,
			'action'    => 'unmatched',
			'file_code' => $file_code,
			'message'   => 'Movie not matched by verified IMDb/TMDb ID and auto-create is disabled.',
		), 200 );
	}

	private function attach_server_to_post( $post_id, $server_entry, $file_code, $embed_url ) {
		$repeatable = get_post_meta( $post_id, 'repeatable_fields', true );
		if ( is_string( $repeatable ) ) {
			$repeatable = maybe_unserialize( $repeatable );
		}
		if ( ! is_array( $repeatable ) ) {
			$repeatable = array();
		}

		// Prevent duplicate server entries
		$already_exists = false;
		foreach ( $repeatable as $srv ) {
			if ( isset( $srv['url'] ) && stripos( $srv['url'], $file_code ) !== false ) {
				$already_exists = true;
				break;
			}
		}

		if ( ! $already_exists ) {
			array_unshift( $repeatable, $server_entry );
			$repeatable = array_values( $repeatable );
			update_post_meta( $post_id, 'repeatable_fields', $repeatable );
		}

		update_post_meta( $post_id, 'peestream_code', $file_code );
		update_post_meta( $post_id, 'peestream_embed_url', $embed_url );
	}

	private function get_default_author_id() {
		$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( ! empty( $users ) ) {
			return $users[0]->ID;
		}
		return 1;
	}
}
