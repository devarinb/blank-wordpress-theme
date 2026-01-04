<?php
if (! defined('ABSPATH')) {
    exit;
}

class Headless_Cleanup_Manager
{
    public function __construct()
    {
        add_action('template_redirect', array($this, 'redirect_frontend'));
        add_action('wp_enqueue_scripts', array($this, 'dequeue_assets'), 100);
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        add_filter('emoji_svg_url', '__return_false');
    }

    public function redirect_frontend()
    {
        if (
            strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false ||
            is_admin() ||
            strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false ||
            strpos($_SERVER['REQUEST_URI'], '/wp-content/') !== false
        ) {
            return;
        }
        
        // If user is logged in, redirect to admin
        if (is_user_logged_in()) {
            wp_redirect(admin_url());
            exit;
        }
        
        // Otherwise redirect to login
        wp_redirect(wp_login_url());
        exit;
    }

    public function dequeue_assets()
    {
        wp_dequeue_style('wp-block-library');
        wp_dequeue_style('wp-block-library-theme');
        wp_dequeue_style('wc-block-style'); // If WooCommerce is present
    }
}
