<?php
if (! defined('ABSPATH')) {
    exit;
}

class Headless_Login_Manager
{
    public function __construct()
    {
        add_action('login_enqueue_scripts', array($this, 'custom_login_stylesheet'));
        add_filter('login_headerurl', array($this, 'custom_login_logo_url'));
        add_filter('login_headertext', array($this, 'custom_login_logo_url_title'));
        add_filter('gettext', array($this, 'custom_login_form_labels'), 20, 3);
        add_filter('login_errors', array($this, 'custom_login_error_message'));
        add_action('login_init', array($this, 'init_login_page_vars'));
        add_action('login_init', array($this, 'handle_login_form'));
        add_action('login_init', array($this, 'custom_login_form'));
    }

    public function custom_login_stylesheet()
    {
        wp_enqueue_style('custom-login', get_stylesheet_directory_uri() . '/style.css', array(), '1.0.1');
    }

    public function custom_login_logo_url()
    {
        return home_url();
    }

    public function custom_login_logo_url_title()
    {
        return get_bloginfo('name');
    }

    public function custom_login_form_labels($translated_text, $text, $domain)
    {
        switch ($translated_text) {
            case 'Username or Email Address':
                return 'Email Address';
            case 'Lost your password?':
                return 'Forgot Password?';
            case 'Remember Me':
                return 'Keep me signed in';
        }
        return $translated_text;
    }

    public function custom_login_error_message()
    {
        return 'Invalid login credentials.';
    }

    public function init_login_page_vars()
    {
        global $user_login, $error;
        $user_login = '';
        $error = '';
    }

    public function handle_login_form()
    {
        if (isset($_POST['wp-submit'])) {
            check_admin_referer('login', 'login_nonce', false); 
            $user = wp_signon();
            if (is_wp_error($user)) {
                $error = $user->get_error_message();
            } else {
                wp_redirect(admin_url());
                exit;
            }
        }
    }

    public function custom_login_form()
    {
        add_action('login_form', function() {
            wp_nonce_field('login', 'login_nonce');
            echo '<div class="login-divider"></div>';
        });
    }
}
