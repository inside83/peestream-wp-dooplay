<?php
/**
 * PeeStream Universal Shortcode & Frontend Player
 * File: includes/class-peestream-shortcode.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PeeStream_Shortcode {

	private $options;

	public function __construct() {
		$this->options = get_option( 'peestream_settings', array() );

		add_shortcode( 'peestream', array( $this, 'render_shortcode' ) );
		add_shortcode( 'peeplayer', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
	}

	public function register_frontend_assets() {
		wp_register_style(
			'peestream-player-css',
			PEESTREAM_PLUGIN_URL . 'assets/css/peestream-player.css',
			array(),
			PEESTREAM_VERSION
		);
	}

	public function render_shortcode( $atts ) {
		$defaults = array(
			'id'         => '',
			'code'       => '',
			'width'      => '100%',
			'height'     => '480px',
			'responsive' => 'true',
			'autoplay'   => 'false',
			'title'      => 'PeeStream Video Player',
			'class'      => '',
		);

		$parsed = shortcode_atts( $defaults, $atts, 'peestream' );

		// Prioritize 'id' then 'code'
		$raw_code = ! empty( $parsed['id'] ) ? $parsed['id'] : $parsed['code'];

		// Fallback: If no code specified in shortcode, try reading current post's meta
		if ( empty( $raw_code ) ) {
			$post_id = get_the_ID();
			if ( $post_id ) {
				$raw_code = get_post_meta( $post_id, 'peestream_code', true );
			}
		}

		$file_code = $this->extract_code( $raw_code );
		if ( empty( $file_code ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<div class="peestream-player-notice peestream-player-warning">' . esc_html__( 'PeeStream Shortcode Error: No valid video ID or code provided.', 'peestream-wp-dooplay' ) . '</div>';
			}
			return '';
		}

		// Enqueue frontend styles on demand
		wp_enqueue_style( 'peestream-player-css' );

		$player_domain = ! empty( $this->options['player_domain'] ) ? rtrim( $this->options['player_domain'], '/' ) : 'https://peeplayer.com';
		$embed_url     = $player_domain . '/e/' . $file_code;

		if ( 'true' === strtolower( $parsed['autoplay'] ) || '1' === $parsed['autoplay'] ) {
			$embed_url .= '?autoplay=1';
		}

		$is_responsive = ( 'false' !== strtolower( $parsed['responsive'] ) && '0' !== $parsed['responsive'] );
		$extra_class   = sanitize_html_class( $parsed['class'] );

		ob_start();
		if ( $is_responsive ) {
			?>
			<div class="peestream-player-container peestream-responsive-container <?php echo esc_attr( $extra_class ); ?>">
				<div class="peestream-responsive-wrapper">
					<iframe 
						src="<?php echo esc_url( $embed_url ); ?>" 
						title="<?php echo esc_attr( $parsed['title'] ); ?>" 
						frameborder="0" 
						scrolling="no" 
						allowfullscreen="true" 
						webkitallowfullscreen="true" 
						mozallowfullscreen="true" 
						allow="autoplay; fullscreen; picture-in-picture; encrypted-media">
					</iframe>
				</div>
			</div>
			<?php
		} else {
			$style = sprintf( 'width:%s;height:%s;', esc_attr( $parsed['width'] ), esc_attr( $parsed['height'] ) );
			?>
			<div class="peestream-player-container peestream-fixed-container <?php echo esc_attr( $extra_class ); ?>" style="<?php echo esc_attr( $style ); ?>">
				<iframe 
					src="<?php echo esc_url( $embed_url ); ?>" 
					title="<?php echo esc_attr( $parsed['title'] ); ?>" 
					style="width:100%;height:100%;border:none;" 
					frameborder="0" 
					scrolling="no" 
					allowfullscreen="true" 
					webkitallowfullscreen="true" 
					mozallowfullscreen="true" 
					allow="autoplay; fullscreen; picture-in-picture; encrypted-media">
				</iframe>
			</div>
			<?php
		}

		return ob_get_clean();
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
