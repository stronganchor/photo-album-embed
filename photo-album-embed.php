<?php
/**
 * Plugin Name: Photo Album Embed
 * Description: Fetch and embed photo albums from services like Google Photos.
 * Author: Strong Anchor Tech
 * Version: 1.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class PhotoAlbumEmbed {
    private $redirect_uri;

    public function __construct() {
        $this->redirect_uri = admin_url('admin.php?page=photo-album-auth');
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('photo_album', [$this, 'render_album_shortcode']);
        add_action('admin_post_photo_album_auth', [$this, 'handle_auth_callback']);
    }

    public function register_menu() {
        add_menu_page(
            'Photo Album Embed',
            'Photo Album Embed',
            'manage_options',
            'photo-album-auth',
            [$this, 'auth_page'],
            'dashicons-images-alt2'
        );

        add_submenu_page(
            'photo-album-auth',
            'Photo Album Settings',
            'Settings',
            'manage_options',
            'photo-album-settings',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('photo_album_settings', 'photo_album_client_id');
        register_setting('photo_album_settings', 'photo_album_client_secret');
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Photo Album Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('photo_album_settings');
                do_settings_sections('photo_album_settings');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Client ID</th>
                        <td>
                            <input type="text" name="photo_album_client_id" value="<?php echo esc_attr(get_option('photo_album_client_id')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Client Secret</th>
                        <td>
                            <input type="text" name="photo_album_client_secret" value="<?php echo esc_attr(get_option('photo_album_client_secret')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function auth_page() {
        $auth_url = $this->get_auth_url();
        echo '<div class="wrap">';
        echo '<h1>Connect to Photo Album Service</h1>';
        if (!$this->get_client_id() || !$this->get_client_secret()) {
            echo '<p>Please configure your Client ID and Secret in the <a href="' . admin_url('admin.php?page=photo-album-settings') . '">Settings</a> page.</p>';
        } else {
            echo '<a href="' . esc_url($auth_url) . '" class="button-primary">Authorize</a>';
        }
        echo '</div>';
    }

    public function get_client_id() {
        return get_option('photo_album_client_id');
    }

    public function get_client_secret() {
        return get_option('photo_album_client_secret');
    }

    public function get_auth_url() {
        $scopes = urlencode('https://www.googleapis.com/auth/photoslibrary.readonly');
        return "https://accounts.google.com/o/oauth2/auth?response_type=code&client_id={$this->get_client_id()}&redirect_uri={$this->redirect_uri}&scope={$scopes}&access_type=offline";
    }

    public function handle_auth_callback() {
        if (!isset($_GET['code'])) {
            wp_die('Authorization failed.');
        }

        $code = sanitize_text_field($_GET['code']);
        $token_url = 'https://oauth2.googleapis.com/token';
        $response = wp_remote_post($token_url, [
            'body' => [
                'code' => $code,
                'client_id' => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'redirect_uri' => $this->redirect_uri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['access_token'])) {
            update_option('photo_album_access_token', $data['access_token']);
            update_option('photo_album_refresh_token', $data['refresh_token']);
            wp_redirect(admin_url('admin.php?page=photo-album-auth'));
            exit;
        }

        wp_die('Authorization failed.');
    }

    public function render_album_shortcode($atts) {
        $atts = shortcode_atts(['album_id' => ''], $atts);
        $album_id = sanitize_text_field($atts['album_id']);
        $access_token = get_option('photo_album_access_token');

        if (!$album_id || !$access_token) {
            return 'Invalid album ID or authorization is missing.';
        }

        $url = "https://photoslibrary.googleapis.com/v1/mediaItems:search";
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['albumId' => $album_id]),
        ]);

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['mediaItems'])) {
            return 'Unable to fetch photos.';
        }

        $html = '<div class="photo-album-gallery">';
        foreach ($data['mediaItems'] as $item) {
            $html .= '<img src="' . esc_url($item['baseUrl']) . '" alt="' . esc_attr($item['description']) . '">';
        }
        $html .= '</div>';

        return $html;
    }
}

new PhotoAlbumEmbed();
