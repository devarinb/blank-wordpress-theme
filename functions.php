<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Theme Setup
 */
function headless_theme_setup()
{
    add_filter('rest_enabled', '__return_true');
    add_filter('rest_jsonp_enabled', '__return_true');
    
    // Add support for post thumbnails (featured images)
    add_theme_support('post-thumbnails');
    
    // Optional: Set default post thumbnail size
    set_post_thumbnail_size(1200, 630, true); // 1200x630 with hard crop - good for social media
    
    // Optional: Add additional image sizes for different use cases
    add_image_size('thumbnail-small', 300, 200, true);
    add_image_size('thumbnail-medium', 600, 400, true);
    add_image_size('thumbnail-large', 1200, 800, true);
}
add_action('after_setup_theme', 'headless_theme_setup');

/**
 * Require Headless Classes
 */
require_once get_template_directory() . '/inc/class-headless-cors.php';
require_once get_template_directory() . '/inc/class-headless-security.php';
require_once get_template_directory() . '/inc/class-headless-cleanup.php';
require_once get_template_directory() . '/inc/class-headless-analytics.php';
require_once get_template_directory() . '/inc/class-headless-canonical.php';
require_once get_template_directory() . '/inc/class-headless-dashboard.php';
require_once get_template_directory() . '/inc/class-headless-login.php';

/**
 * Initialize Classes
 */
function headless_theme_init() {
    new Headless_CORS_Manager();
    new Headless_Security_Manager();
    new Headless_Cleanup_Manager();
    new Headless_API_Usage_Analytics();
    new Headless_API_Performance_Monitor();
    new Headless_API_Error_Logger();
    new Headless_Canonical_Manager();
    new Headless_Dashboard_Manager();
    new Headless_Login_Manager();
}
add_action('init', 'headless_theme_init');

// Create tables on theme activation
// Create tables on theme switch
add_action('after_switch_theme', function () {
    $temp_analytics = new Headless_API_Usage_Analytics();
    $temp_analytics->create_analytics_table();
    $temp_error_logger = new Headless_API_Error_Logger();
    $temp_error_logger->create_error_log_table();
    update_option('headless_tables_created', 1);
});

// Ensure tables exist (fallback for existing installs)
add_action('admin_init', function() {
    if (!get_option('headless_tables_created')) {
        $temp_analytics = new Headless_API_Usage_Analytics();
        $temp_analytics->create_analytics_table();
        $temp_error_logger = new Headless_API_Error_Logger();
        $temp_error_logger->create_error_log_table();
        update_option('headless_tables_created', 1);
    }
});
