<?php
if (! defined('ABSPATH')) {
    exit;
}

class Headless_CORS_Manager
{
    public function __construct()
    {
        add_action('init', array($this, 'handle_cors'));
        add_filter('rest_pre_serve_request', array($this, 'handle_preflight'), 10, 4);
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function handle_cors()
    {
        $allowed_origin = get_option('headless_allowed_origin', '*');
        
        // If we are in a development environment or specific origin is set
        if ($allowed_origin === '*') {
             header("Access-Control-Allow-Origin: *");
        } else {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
            if ($origin === $allowed_origin) {
                header("Access-Control-Allow-Origin: " . $allowed_origin);
            }
        }

        header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-WP-Nonce");
    }

    public function handle_preflight($value, $result, $request, $server)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            return true;
        }
        return $value;
    }

    public function add_settings_page()
    {
        add_options_page(
            'Headless Settings',
            'Headless Settings',
            'manage_options',
            'headless-settings',
            array($this, 'settings_page')
        );
    }

    public function register_settings()
    {
        register_setting('headless_settings_group', 'headless_allowed_origin');
    }

    public function settings_page()
    {
        ?>
        <div class="wrap">
            <h1>Headless Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('headless_settings_group'); ?>
                <?php do_settings_sections('headless_settings_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Allowed Origin (CORS)</th>
                        <td>
                            <input type="text" name="headless_allowed_origin" value="<?php echo esc_attr(get_option('headless_allowed_origin', '*')); ?>" class="regular-text" />
                            <p class="description">Enter the URL of your frontend application (e.g., https://myapp.com) or * for all.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
