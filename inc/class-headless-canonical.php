<?php
if (! defined('ABSPATH')) {
    exit;
}

class Headless_Canonical_Manager
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_head', array($this, 'add_canonical_headers'));
        add_action('wp_head', array($this, 'add_robots_meta'), 1); // Run early
        add_action('rest_api_init', array($this, 'add_api_canonical_headers'));
        add_action('template_redirect', array($this, 'add_template_canonical_headers'));
        add_action('do_robots', array($this, 'add_robots_txt_content'));
        
        // Handle wp-content requests - try multiple hooks to catch static files
        add_action('init', array($this, 'handle_wp_content_canonical'), 1);
        add_action('wp_loaded', array($this, 'handle_wp_content_canonical'), 1);
        add_action('send_headers', array($this, 'handle_wp_content_canonical'), 1);
        
        // Create .htaccess rules for static files
        add_action('admin_init', array($this, 'create_htaccess_rules'));
        add_action('admin_init', array($this, 'handle_settings_actions'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
        
        // Add test endpoint for debugging canonical headers
        add_action('init', array($this, 'add_canonical_test_endpoint'));
    }

    public function add_settings_page()
    {
        add_options_page(
            'Canonical Headers Settings',
            'Canonical Headers',
            'manage_options',
            'canonical-headers',
            array($this, 'settings_page')
        );
    }

    public function register_settings()
    {
        register_setting('canonical_headers_group', 'canonical_domain', array(
            'sanitize_callback' => 'sanitize_url'
        ));
        register_setting('canonical_headers_group', 'canonical_enabled', array(
            'sanitize_callback' => 'absint'
        ));
        register_setting('canonical_headers_group', 'exclude_wp_content', array(
            'sanitize_callback' => 'absint'
        ));
    }

    public function settings_page()
    {
        ?>
        <div class="wrap">
            <h1>Canonical Headers Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('canonical_headers_group'); ?>
                <?php do_settings_sections('canonical_headers_group'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Canonical Headers</th>
                        <td>
                            <input type="checkbox" name="canonical_enabled" value="1" <?php checked(get_option('canonical_enabled', 0), 1); ?> />
                            <p class="description">Enable canonical headers to prevent duplicate content indexing.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Main Site Domain</th>
                        <td>
                            <input type="url" name="canonical_domain" value="<?php echo esc_attr(get_option('canonical_domain', '')); ?>" class="regular-text" placeholder="https://example.com" />
                            <p class="description">Enter your main site domain (e.g., https://example.com). This CMS domain will point to this as canonical.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Exclude wp-content from noindex</th>
                        <td>
                            <input type="checkbox" name="exclude_wp_content" value="1" <?php checked(get_option('exclude_wp_content', 1), 1); ?> />
                            <p class="description">Don't add noindex meta tags to wp-content URLs (images, uploads, etc.) so they remain crawlable. Canonical headers will still be added to point to your main site.</p>
                        </td>
                    </tr>
                </table>
                
                                 <?php submit_button(); ?>
            </form>
            
            <?php if (get_option('canonical_enabled', 0) && get_option('canonical_domain', '')): ?>
            <div class="card">
                <h3>Static Files Configuration</h3>
                <p>For static files (images, CSS, JS) to get canonical headers, we need to create .htaccess rules since they bypass WordPress.</p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('force_htaccess_creation', 'htaccess_nonce'); ?>
                    <input type="hidden" name="action" value="create_htaccess" />
                    <?php submit_button('Force Create/Update .htaccess Rules', 'secondary', 'create_htaccess', false); ?>
                </form>
                
                <h4>Debug Information:</h4>
                <?php $this->display_debug_info(); ?>
            </div>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <p><strong>How it works:</strong></p>
                <ul>
                    <li><strong>Canonical Headers:</strong> ALL pages, API endpoints, and wp-content resources will include canonical headers pointing to your main site</li>
                    <li><strong>Static Files:</strong> .htaccess rules are created in wp-content/uploads/ to add canonical headers to images/files</li>
                    <li><strong>Noindex Control:</strong> wp-content resources (images, uploads) can be excluded from noindex tags so they remain crawlable</li>
                    <li><strong>SEO Benefits:</strong> Search engines will understand your main site is the canonical source while images remain accessible from this CMS</li>
                    <li><strong>Best Practice:</strong> This prevents duplicate content penalties while maintaining functionality</li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function add_canonical_headers()
    {
        if (!get_option('canonical_enabled', 0) || !get_option('canonical_domain', '')) {
            return;
        }

        $canonical_domain = rtrim(get_option('canonical_domain', ''), '/');
        $current_path = $_SERVER['REQUEST_URI'];
        $canonical_url = $canonical_domain . $current_path;

        echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
        header('Link: <' . $canonical_url . '>; rel="canonical"', false);
    }

    public function add_api_canonical_headers()
    {
        add_filter('rest_post_dispatch', array($this, 'add_rest_canonical_header'), 10, 3);
    }

    public function add_template_canonical_headers()
    {
        if (!get_option('canonical_enabled', 0) || !get_option('canonical_domain', '')) {
            return;
        }

        $canonical_domain = rtrim(get_option('canonical_domain', ''), '/');
        $current_path = $_SERVER['REQUEST_URI'];
        $canonical_url = $canonical_domain . $current_path;

        header('Link: <' . $canonical_url . '>; rel="canonical"', false);
    }

    public function add_rest_canonical_header($response, $server, $request)
    {
        if (!get_option('canonical_enabled', 0) || !get_option('canonical_domain', '')) {
            return $response;
        }

        $canonical_domain = rtrim(get_option('canonical_domain', ''), '/');
        $current_path = $_SERVER['REQUEST_URI'];
        $canonical_url = $canonical_domain . $current_path;

        $response->header('Link', '<' . $canonical_url . '>; rel="canonical"');
        return $response;
    }

    private function is_wp_content_request()
    {
        return strpos($_SERVER['REQUEST_URI'], '/wp-content/') !== false;
    }

    public function add_robots_meta()
    {
        if (!get_option('canonical_enabled', 0)) {
            return;
        }

        // Don't add noindex to wp-content resources (images, etc.) - we want them accessible
        if (get_option('exclude_wp_content', 1) && $this->is_wp_content_request()) {
            return;
        }

        // Add noindex, nofollow meta tag to prevent indexing of pages/admin
        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
    }

    public function add_robots_txt_content()
    {
        if (!get_option('canonical_enabled', 0)) {
            return;
        }

        // Block all crawlers from the site except wp-content
        echo "User-agent: *\n";
        echo "Disallow: /\n";
        
        if (get_option('exclude_wp_content', 1)) {
            echo "Allow: /wp-content/\n";
        }
        
        echo "\n";
        
        // Add canonical domain sitemap if configured
        $canonical_domain = get_option('canonical_domain', '');
        if ($canonical_domain) {
            $canonical_domain = rtrim($canonical_domain, '/');
            echo "Sitemap: {$canonical_domain}/sitemap.xml\n";
        }
    }

    private $canonical_header_sent = false;

    public function handle_wp_content_canonical()
    {
        if (!get_option('canonical_enabled', 0) || !get_option('canonical_domain', '')) {
            return;
        }

        // Only process wp-content requests
        if (!$this->is_wp_content_request()) {
            return;
        }

        // Prevent duplicate headers
        if ($this->canonical_header_sent) {
            return;
        }

        $canonical_domain = rtrim(get_option('canonical_domain', ''), '/');
        $current_path = $_SERVER['REQUEST_URI'];
        $canonical_url = $canonical_domain . $current_path;

        // Add canonical header for wp-content resources
        if (!headers_sent()) {
            header('Link: <' . $canonical_url . '>; rel="canonical"', false);
            $this->canonical_header_sent = true;
        }
    }

    public function create_htaccess_rules()
    {
        if (!get_option('canonical_enabled', 0) || !get_option('canonical_domain', '')) {
            return;
        }

        $canonical_domain = rtrim(get_option('canonical_domain', ''), '/');
        $upload_dir = wp_upload_dir();
        $htaccess_file = $upload_dir['basedir'] . '/.htaccess';

        // Only create/update if canonical domain is set
        if (!$canonical_domain) {
            return;
        }

        $htaccess_content = "# WordPress Canonical Headers for Static Files\n";
        $htaccess_content .= "<IfModule mod_headers.c>\n";
        $htaccess_content .= "    Header set Link \"<{$canonical_domain}%{REQUEST_URI}s>; rel=canonical\"\n";
        $htaccess_content .= "</IfModule>\n\n";

        // Create uploads .htaccess if it doesn't exist or update it
        if (!file_exists($htaccess_file) || !$this->htaccess_has_canonical_rules($htaccess_file)) {
            $existing_content = file_exists($htaccess_file) ? file_get_contents($htaccess_file) : '';
            
            // Remove any existing canonical rules first
            $existing_content = preg_replace('/# WordPress Canonical Headers for Static Files.*?<\/IfModule>\s*\n*/s', '', $existing_content);
            
            $new_content = $htaccess_content . $existing_content;
            file_put_contents($htaccess_file, $new_content);
        }
    }

    private function htaccess_has_canonical_rules($htaccess_file)
    {
        if (!file_exists($htaccess_file)) {
            return false;
        }
        
        $content = file_get_contents($htaccess_file);
        return strpos($content, 'WordPress Canonical Headers for Static Files') !== false;
    }

    public function handle_settings_actions()
    {
        if (isset($_POST['action']) && $_POST['action'] === 'create_htaccess') {
            if (wp_verify_nonce($_POST['htaccess_nonce'], 'force_htaccess_creation')) {
                $this->force_create_htaccess_rules();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>.htaccess rules have been created/updated for static files.</p></div>';
                });
            }
        }
        
        // Flush rewrite rules when canonical settings are updated
        if (isset($_POST['canonical_enabled']) || isset($_POST['canonical_domain'])) {
            flush_rewrite_rules();
        }
    }

    public function show_admin_notices()
    {
        if (get_option('canonical_enabled', 0) && !get_option('canonical_domain', '')) {
            echo '<div class="notice notice-warning"><p><strong>Canonical Headers:</strong> Please set your main site domain in the settings to enable canonical headers.</p></div>';
        }
    }

    public function display_debug_info()
    {
        $canonical_domain = get_option('canonical_domain', '');
        $upload_dir = wp_upload_dir();
        $htaccess_file = $upload_dir['basedir'] . '/.htaccess';
        
        echo '<ul>';
        echo '<li><strong>Canonical Domain:</strong> ' . esc_html($canonical_domain) . '</li>';
        echo '<li><strong>Upload Directory:</strong> ' . esc_html($upload_dir['basedir']) . '</li>';
        echo '<li><strong>.htaccess File:</strong> ' . esc_html($htaccess_file) . '</li>';
        echo '<li><strong>.htaccess Exists:</strong> ' . (file_exists($htaccess_file) ? 'Yes' : 'No') . '</li>';
        
        if (file_exists($htaccess_file)) {
            echo '<li><strong>Has Canonical Rules:</strong> ' . ($this->htaccess_has_canonical_rules($htaccess_file) ? 'Yes' : 'No') . '</li>';
            echo '<li><strong>File Writable:</strong> ' . (is_writable($htaccess_file) ? 'Yes' : 'No') . '</li>';
        } else {
            echo '<li><strong>Directory Writable:</strong> ' . (is_writable($upload_dir['basedir']) ? 'Yes' : 'No') . '</li>';
        }
        
        echo '<li><strong>Server Software:</strong> ' . esc_html($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . '</li>';
        echo '<li><strong>mod_headers Available:</strong> ' . (function_exists('apache_get_modules') && in_array('mod_headers', apache_get_modules()) ? 'Yes' : 'Unknown (may still work)') . '</li>';
        
        echo '</ul>';
        
        echo '<h4>Test Canonical Headers:</h4>';
        echo '<p><a href="' . home_url('/canonical-test/') . '" target="_blank">Test Canonical Headers</a> - Opens in new tab to show headers</p>';
        
        if (file_exists($htaccess_file)) {
            echo '<h4>.htaccess Content:</h4>';
            echo '<textarea readonly style="width:100%;height:200px;font-family:monospace;">' . esc_textarea(file_get_contents($htaccess_file)) . '</textarea>';
        }
    }

    public function force_create_htaccess_rules()
    {
        if (!get_option('canonical_enabled', 0) || !get_option('canonical_domain', '')) {
            return false;
        }

        $canonical_domain = rtrim(get_option('canonical_domain', ''), '/');
        $upload_dir = wp_upload_dir();
        $htaccess_file = $upload_dir['basedir'] . '/.htaccess';

        $htaccess_content = "# WordPress Canonical Headers for Static Files\n";
        $htaccess_content .= "<IfModule mod_headers.c>\n";
        $htaccess_content .= "    Header set Link \"<{$canonical_domain}%{REQUEST_URI}s>; rel=canonical\"\n";
        $htaccess_content .= "</IfModule>\n\n";

        $existing_content = file_exists($htaccess_file) ? file_get_contents($htaccess_file) : '';
        
        // Remove any existing canonical rules first
        $existing_content = preg_replace('/# WordPress Canonical Headers for Static Files.*?<\/IfModule>\s*\n*/s', '', $existing_content);
        
        $new_content = $htaccess_content . $existing_content;
        
        return file_put_contents($htaccess_file, $new_content) !== false;
    }

    public function add_canonical_test_endpoint()
    {
        add_rewrite_rule('^canonical-test/?$', 'index.php?canonical_test=1', 'top');
        add_filter('query_vars', function($vars) {
            $vars[] = 'canonical_test';
            return $vars;
        });
        
        add_action('template_redirect', function() {
            if (get_query_var('canonical_test')) {
                header('Content-Type: text/plain');
                echo "Canonical Headers Test\n";
                echo "=====================\n\n";
                echo "Request URI: " . $_SERVER['REQUEST_URI'] . "\n";
                echo "Current Time: " . date('Y-m-d H:i:s') . "\n\n";
                
                if (get_option('canonical_enabled', 0) && get_option('canonical_domain', '')) {
                    $canonical_domain = rtrim(get_option('canonical_domain', ''), '/');
                    $canonical_url = $canonical_domain . $_SERVER['REQUEST_URI'];
                    echo "Canonical URL: {$canonical_url}\n";
                    echo "Header should be: Link: <{$canonical_url}>; rel=\"canonical\"\n\n";
                    header('Link: <' . $canonical_url . '>; rel="canonical"', false);
                    echo "✅ Canonical header added!\n";
                } else {
                    echo "❌ Canonical headers not enabled or domain not set\n";
                }
                
                echo "\nAll Headers:\n";
                foreach (headers_list() as $header) {
                    echo "  {$header}\n";
                }
                
                exit;
            }
        });
    }
}
