<?php
/**
 * Plugin Name: Photo Album Embed
 * Description: Fetch and embed photo albums from services like Google Photos.
 * Author: Strong Anchor Tech
 * Version: 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PhotoAlbumEmbed {
	private $redirect_uri;

	public function __construct() {
		$this->redirect_uri = admin_url( 'admin-post.php?action=photo_album_auth' );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_shortcode( 'photo_album', array( $this, 'render_album_shortcode' ) );
		add_action( 'admin_post_photo_album_auth', array( $this, 'handle_auth_callback' ) );
	}

	public function register_menu() {
		add_menu_page(
			'Photo Album Embed',
			'Photo Album Embed',
			'manage_options',
			'photo-album-auth',
			array( $this, 'auth_page' ),
			'dashicons-images-alt2'
		);

		add_submenu_page(
			'photo-album-auth',
			'Photo Album Settings',
			'Settings',
			'manage_options',
			'photo-album-settings',
			array( $this, 'settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'photo_album_settings', 'photo_album_client_id', 'sanitize_text_field' );
		register_setting( 'photo_album_settings', 'photo_album_client_secret', 'sanitize_text_field' );
	}

	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Photo Album Settings', 'photo-album-embed' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'photo_album_settings' );
				do_settings_sections( 'photo_album_settings' );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Client ID', 'photo-album-embed' ); ?></th>
						<td>
							<input type="text" name="photo_album_client_id" value="<?php echo esc_attr( $this->get_client_id() ); ?>" class="regular-text">
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Client Secret', 'photo-album-embed' ); ?></th>
						<td>
							<input type="password" name="photo_album_client_secret" value="<?php echo esc_attr( $this->get_client_secret() ); ?>" class="regular-text" autocomplete="off">
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function auth_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Connect to Photo Album Service', 'photo-album-embed' ) . '</h1>';

		if ( ! $this->get_client_id() || ! $this->get_client_secret() ) {
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL */
					__( 'Please configure your Client ID and Secret in the <a href="%s">Settings</a> page.', 'photo-album-embed' ),
					esc_url( admin_url( 'admin.php?page=photo-album-settings' ) )
				)
			) . '</p>';
		} else {
			echo '<a href="' . esc_url( $this->get_auth_url() ) . '" class="button-primary">' . esc_html__( 'Authorize', 'photo-album-embed' ) . '</a>';
		}

		echo '</div>';
	}

	public function get_client_id() {
		return sanitize_text_field( get_option( 'photo_album_client_id', '' ) );
	}

	public function get_client_secret() {
		return sanitize_text_field( get_option( 'photo_album_client_secret', '' ) );
	}

	public function get_auth_url() {
		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $this->get_client_id(),
				'redirect_uri'  => $this->redirect_uri,
				'scope'         => 'https://www.googleapis.com/auth/photoslibrary.readonly',
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => wp_create_nonce( 'photo_album_auth_' . get_current_user_id() ),
			),
			'https://accounts.google.com/o/oauth2/auth'
		);
	}

	public function handle_auth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this photo album service.', 'photo-album-embed' ), '', array( 'response' => 403 ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, 'photo_album_auth_' . get_current_user_id() ) ) {
			wp_die( esc_html__( 'Authorization state check failed.', 'photo-album-embed' ), '', array( 'response' => 403 ) );
		}

		if ( ! isset( $_GET['code'] ) ) {
			wp_die( esc_html__( 'Authorization failed.', 'photo-album-embed' ) );
		}

		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $this->get_client_id(),
					'client_secret' => $this->get_client_secret(),
					'redirect_uri'  => $this->redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_die( esc_html__( 'Authorization token exchange failed.', 'photo-album-embed' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['access_token'] ) ) {
			update_option( 'photo_album_access_token', sanitize_text_field( $data['access_token'] ) );
			if ( ! empty( $data['refresh_token'] ) ) {
				update_option( 'photo_album_refresh_token', sanitize_text_field( $data['refresh_token'] ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=photo-album-auth' ) );
			exit;
		}

		wp_die( esc_html__( 'Authorization failed.', 'photo-album-embed' ) );
	}

	public function render_album_shortcode( $atts ) {
		$atts         = shortcode_atts( array( 'album_id' => '' ), $atts, 'photo_album' );
		$album_id     = sanitize_text_field( $atts['album_id'] );
		$access_token = sanitize_text_field( get_option( 'photo_album_access_token', '' ) );

		if ( ! $album_id || ! $access_token ) {
			return esc_html__( 'Invalid album ID or authorization is missing.', 'photo-album-embed' );
		}

		$cache_key = 'photo_album_embed_' . md5( $album_id . '|' . md5( $access_token ) );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			'https://photoslibrary.googleapis.com/v1/mediaItems:search',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'albumId' => $album_id ) ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$message = esc_html__( 'Unable to fetch photos.', 'photo-album-embed' );
			set_transient( $cache_key, $message, 2 * MINUTE_IN_SECONDS );
			return $message;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['mediaItems'] ) || ! is_array( $data['mediaItems'] ) ) {
			$message = esc_html__( 'Unable to fetch photos.', 'photo-album-embed' );
			set_transient( $cache_key, $message, 2 * MINUTE_IN_SECONDS );
			return $message;
		}

		$html = '<div class="photo-album-gallery">';
		foreach ( $data['mediaItems'] as $item ) {
			$image_url   = isset( $item['baseUrl'] ) ? esc_url( $item['baseUrl'] ) : '';
			$description = isset( $item['description'] ) ? $item['description'] : '';
			if ( '' === $image_url ) {
				continue;
			}
			$html .= '<img src="' . $image_url . '" alt="' . esc_attr( $description ) . '">';
		}
		$html .= '</div>';

		set_transient( $cache_key, $html, 10 * MINUTE_IN_SECONDS );

		return $html;
	}
}

new PhotoAlbumEmbed();
