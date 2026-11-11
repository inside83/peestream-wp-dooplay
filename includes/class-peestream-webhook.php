<?php
/**
 * PeeStream REST API Webhook Receiver
 * File: includes/class-peestream-webhook.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PeeStream_Webhook {

	private $options;

	public function __construct() {
		$this->options = get_option( 'peestream_settings', array() );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'peestream/v1', '/webhook', array(
			'methods'             => WP_REST_Server::CREATABLE, // POST
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => array( $this, 'verify_permission' ),
		) );
	}

	public function verify_permission( WP_REST_Request $request ) {
		$configured_secret = ! empty( $this->options['webhook_secret'] ) ? trim( $this->options['webhook_secret'] ) : '';

		// If no secret configured, reject for security
		if ( empty( $configured_secret ) ) {
			return new WP_Error( 'peestream_no_secret', __( 'Webhook secret is not configured in WordPress settings.', 'peestream-wp-dooplay' ), array( 'status' => 403 ) );
		}

		$incoming_secret = $request->get_param( 'secret' );
		if ( empty( $incoming_secret ) ) {
			$incoming_secret = $request->get_header( 'x-webhook-secret' );
		}

		if ( empty( $incoming_secret ) || ! hash_equals( (string) $configured_secret, (string) $incoming_secret ) ) {
			return new WP_Error( 'peestream_invalid_secret', __( 'Access denied. Invalid webhook secret token.', 'peestream-wp-dooplay' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) || ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		$file_code = isset( $params['file_code'] ) ? sanitize_text_field( trim( $params['file_code'] ) ) : '';
		if ( empty( $file_code ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => __( 'Missing required parameter: file_code', 'peestream-wp-dooplay' ),
			), 400 );
		}

		$event       = isset( $params['event'] ) ? sanitize_text_field( trim( $params['event'] ) ) : 'video_ready';
		$imdb_id     = isset( $params['imdb_id'] ) ? sanitize_text_field( trim( $params['imdb_id'] ) ) : '';
		$title       = isset( $params['title'] ) ? sanitize_text_field( trim( wp_strip_all_tags( $params['title'] ) ) ) : 'Video';
		$season      = isset( $params['season'] ) ? absint( $params['season'] ) : 0;
		$episode     = isset( $params['episode'] ) ? absint( $params['episode'] ) : 0;
		$poster_url  = isset( $params['poster'] ) ? esc_url_raw( trim( $params['poster'] ) ) : '';

		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$server_name   = ! empty( $this->options['default_server_name'] ) ? $this->options['default_server_name'] : 'PeeStream HD';
		$language      = ! empty( $this->options['default_language'] ) ? $this->options['default_language'] : 'English';
		$quality       = ! empty( $this->options['default_quality'] ) ? $this->options['default_quality'] : '1080p';
		$auto_create   = ! empty( $this->options['auto_create_posts'] ) && '1' === $this->options['auto_create_posts'];

		$embed_url = $player_domain . '/e/' . $file_code;

		$server_entry = array(
			'name'    => $server_name,
			'select'  => 'iframe',
			'idioma'  => $language,
			'quality' => $quality,
			'url'     => $embed_url,
		);

		// CASE 1: TV Show Episode (Season > 0 and Episode > 0)
		if ( $season > 0 && $episode > 0 ) {
			return $this->process_episode( $file_code, $embed_url, $server_entry, $imdb_id, $title, $season, $episode, $poster_url, $auto_create );
		}

		// CASE 2: Single Movie or Generic Video
		return $this->process_movie( $file_code, $embed_url, $server_entry, $imdb_id, $title, $poster_url, $auto_create );
	}

	private function process_episode( $file_code, $embed_url, $server_entry, $imdb_id, $title, $season, $episode, $poster_url, $auto_create ) {
		global $wpdb;

		// 1. Try to find existing episode in 'episodes' CPT
		$matched_post_id = 0;

		// Query episodes by season and episode number
		$query = "
			SELECT p.ID 
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_s ON (p.ID = pm_s.post_id AND pm_s.meta_key = 'temporada' AND pm_s.meta_value = %d)
			INNER JOIN {$wpdb->postmeta} pm_e ON (p.ID = pm_e.post_id AND pm_e.meta_key = 'episodio' AND pm_e.meta_value = %d)
			WHERE p.post_type = 'episodes' AND p.post_status = 'publish'
		";

		$candidates = $wpdb->get_col( $wpdb->prepare( $query, $season, $episode ) );

		if ( ! empty( $candidates ) ) {
			if ( ! empty( $imdb_id ) ) {
				// Filter by IMDb ID if available
				foreach ( $candidates as $cid ) {
					$cid_imdb = get_post_meta( $cid, 'ids', true );
					if ( empty( $cid_imdb ) ) {
						$cid_imdb = get_post_meta( $cid, 'imdb_id', true );
					}
					if ( $cid_imdb === $imdb_id ) {
						$matched_post_id = (int) $cid;
						break;
					}
				}
			}

			// Fallback: match by title similarity if not matched by IMDb
			if ( ! $matched_post_id && ! empty( $title ) ) {
				$clean_title = trim( preg_replace( '/\s*(?:Season|Sezona|S\d+|E\d+|\d+x\d+).*/i', '', $title ) );
				foreach ( $candidates as $cid ) {
					$ep_title = get_the_title( $cid );
					if ( stripos( $ep_title, $clean_title ) !== false ) {
						$matched_post_id = (int) $cid;
						break;
					}
				}
			}

			// If only one candidate found for that season/episode, link it
			if ( ! $matched_post_id && count( $candidates ) === 1 ) {
				$matched_post_id = (int) $candidates[0];
			}
		}

		// A. Update Existing Episode
		if ( $matched_post_id ) {
			$this->attach_server_to_post( $matched_post_id, $server_entry, $file_code, $embed_url );

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'episode_updated',
				'post_id'   => $matched_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'Existing episode post #%d updated with PeeStream player.', $matched_post_id ),
			), 200 );
		}

		// B. Auto-Create Episode Post (if enabled)
		if ( $auto_create ) {
			// Find parent TV Show
			$parent_show_id = 0;
			$clean_show_title = trim( preg_replace( '/\s*(?:Season|Sezona|S\d+|E\d+|\d+x\d+).*/i', '', $title ) );

			if ( ! empty( $imdb_id ) ) {
				$parent_show_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE (meta_key = 'ids' OR meta_key = 'imdb_id') AND meta_value = %s LIMIT 1",
					$imdb_id
				) );
			}

			if ( ! $parent_show_id && ! empty( $clean_show_title ) ) {
				$parent_show_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tvshows' AND post_title LIKE %s LIMIT 1",
					'%' . $wpdb->esc_like( $clean_show_title ) . '%'
				) );
			}

			$show_name = $parent_show_id ? get_the_title( $parent_show_id ) : $clean_show_title;
			$ep_post_title = sprintf( '%s: %dx%d', $show_name, $season, $episode );

			$new_post_id = wp_insert_post( array(
				'post_title'   => $ep_post_title,
				'post_content' => sprintf( 'Watch %s Season %d Episode %d online in full HD.', esc_html( $show_name ), $season, $episode ),
				'post_status'  => 'publish',
				'post_type'    => 'episodes',
				'post_author'  => 1,
			) );

			if ( is_wp_error( $new_post_id ) ) {
				return new WP_REST_Response( array(
					'success' => false,
					'error'   => $new_post_id->get_error_message(),
				), 500 );
			}

			// Setup DooPlay episode postmeta
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

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'episode_created',
				'post_id'   => $new_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'New episode post #%d created and player attached.', $new_post_id ),
			), 201 );
		}

		return new WP_REST_Response( array(
			'success'   => true,
			'action'    => 'ignored',
			'file_code' => $file_code,
			'message'   => 'Episode not found in database and auto-create is disabled.',
		), 200 );
	}

	private function process_movie( $file_code, $embed_url, $server_entry, $imdb_id, $title, $poster_url, $auto_create ) {
		global $wpdb;

		$matched_post_id = 0;

		// 1. Search by IMDb ID
		if ( ! empty( $imdb_id ) ) {
			$matched_post_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE (meta_key = 'ids' OR meta_key = 'imdb_id') AND meta_value = %s LIMIT 1",
				$imdb_id
			) );
		}

		// 2. Search by Title
		if ( ! $matched_post_id && ! empty( $title ) ) {
			$matched_post_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('movies', 'post') AND post_status = 'publish' AND post_title LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $title ) . '%'
			) );
		}

		// A. Update Existing Movie
		if ( $matched_post_id ) {
			$this->attach_server_to_post( $matched_post_id, $server_entry, $file_code, $embed_url );

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'movie_updated',
				'post_id'   => $matched_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'Existing movie post #%d updated with PeeStream player.', $matched_post_id ),
			), 200 );
		}

		// B. Auto-Create Movie Post (if enabled)
		if ( $auto_create ) {
			$target_cpt = ! empty( $this->options['default_post_type'] ) ? $this->options['default_post_type'] : 'movies';

			$new_post_id = wp_insert_post( array(
				'post_title'   => $title,
				'post_content' => sprintf( 'Watch %s online in full HD with multiple audio streams and subtitles.', esc_html( $title ) ),
				'post_status'  => 'publish',
				'post_type'    => $target_cpt,
				'post_author'  => 1,
			) );

			if ( is_wp_error( $new_post_id ) ) {
				return new WP_REST_Response( array(
					'success' => false,
					'error'   => $new_post_id->get_error_message(),
				), 500 );
			}

			if ( ! empty( $imdb_id ) ) {
				update_post_meta( $new_post_id, 'ids', $imdb_id );
			}
			if ( ! empty( $poster_url ) ) {
				update_post_meta( $new_post_id, 'dt_poster', $poster_url );
			}

			$this->attach_server_to_post( $new_post_id, $server_entry, $file_code, $embed_url );

			return new WP_REST_Response( array(
				'success'   => true,
				'action'    => 'movie_created',
				'post_id'   => $new_post_id,
				'file_code' => $file_code,
				'message'   => sprintf( 'New movie post #%d created and player attached.', $new_post_id ),
			), 201 );
		}

		return new WP_REST_Response( array(
			'success'   => true,
			'action'    => 'ignored',
			'file_code' => $file_code,
			'message'   => 'Movie not found in database and auto-create is disabled.',
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

		// Avoid exact duplicate
		$already_exists = false;
		foreach ( $repeatable as $srv ) {
			if ( isset( $srv['url'] ) && stripos( $srv['url'], $file_code ) !== false ) {
				$already_exists = true;
				break;
			}
		}

		if ( ! $already_exists ) {
			// Prepend as Server 1 (Primary)
			array_unshift( $repeatable, $server_entry );
			$repeatable = array_values( $repeatable );
			update_post_meta( $post_id, 'repeatable_fields', $repeatable );
		}

		update_post_meta( $post_id, 'peestream_code', $file_code );
		update_post_meta( $post_id, 'peestream_embed_url', $embed_url );
	}
}
