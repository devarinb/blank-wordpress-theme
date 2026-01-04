<?php
if (! defined('ABSPATH')) {
    exit;
}

class Headless_Security_Manager
{
    public function __construct()
    {
        add_action('send_headers', array($this, 'add_security_headers'));
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('wp_headers', array($this, 'remove_x_pingback'));
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_generator');
    }

    public function add_security_headers()
    {
        header('X-Content-Type-Options: nosniff');
        
        // Skip X-Frame-Options for admin pages to allow WordPress 6.9+ iframe-based block editor
        // The block editor now loads content in an iframe for better style isolation
        if (!is_admin()) {
            header('X-Frame-Options: SAMEORIGIN');
        }
        
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    public function remove_x_pingback($headers)
    {
        unset($headers['X-Pingback']);
        return $headers;
    }
}
