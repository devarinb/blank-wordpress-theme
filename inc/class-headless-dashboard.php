<?php
if (! defined('ABSPATH')) {
    exit;
}

class Headless_Dashboard_Manager
{
    public function __construct()
    {
        add_action('wp_dashboard_setup', array($this, 'remove_default_dashboard_widgets'));
        add_action('wp_dashboard_setup', array($this, 'add_content_overview_widget'));
        add_action('wp_dashboard_setup', array($this, 'add_recent_activity_widget'));
        add_action('wp_dashboard_setup', array($this, 'add_quick_actions_widget'));
        add_action('wp_dashboard_setup', array($this, 'customize_dashboard_by_role'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_custom_dashboard_styles'));
    }

    public function remove_default_dashboard_widgets()
    {
        remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
        remove_meta_box('dashboard_incoming_links', 'dashboard', 'normal');
        remove_meta_box('dashboard_plugins', 'dashboard', 'normal');
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        remove_meta_box('dashboard_recent_drafts', 'dashboard', 'side');
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
        remove_meta_box('dashboard_secondary', 'dashboard', 'side');
        remove_meta_box('dashboard_activity', 'dashboard', 'normal');
        remove_action('welcome_panel', 'wp_welcome_panel');
    }

    public function add_content_overview_widget()
    {
        wp_add_dashboard_widget(
            'content_overview_widget',
            'Content Overview',
            array($this, 'content_overview_widget_display')
        );
    }

    public function content_overview_widget_display()
    {
        $post_counts = wp_count_posts('post');
        $page_counts = wp_count_posts('page');
        $custom_post_types = get_post_types(
            ['public' => true, '_builtin' => false],
            'objects'
        );

        echo '<div class="content-overview-grid">';

        $url = admin_url('edit.php');
        echo '<a href="' . esc_url($url) . '" class="content-stat-item">';
        echo '<h4>Posts</h4>';
        echo '<div class="stat-numbers">';
        echo '<span class="published">' . esc_html($post_counts->publish) . ' Published</span>';
        echo '<span class="draft">'     . esc_html($post_counts->draft)   . ' Drafts</span>';
        echo '</div>';
        echo '</a>';

        $url = admin_url('edit.php?post_type=page');
        echo '<a href="' . esc_url($url) . '" class="content-stat-item">';
        echo '<h4>Pages</h4>';
        echo '<div class="stat-numbers">';
        echo '<span class="published">' . esc_html($page_counts->publish) . ' Published</span>';
        echo '<span class="draft">'     . esc_html($page_counts->draft)   . ' Drafts</span>';
        echo '</div>';
        echo '</a>';

        foreach ($custom_post_types as $pt) {
            $counts = wp_count_posts($pt->name);
            $url = admin_url('edit.php?post_type=' . $pt->name);
            echo '<a href="' . esc_url($url) . '" class="content-stat-item">';
            echo '<h4>' . esc_html($pt->labels->name) . '</h4>';
            echo '<div class="stat-numbers">';
            echo '<span class="published">' . esc_html($counts->publish) . ' Published</span>';
            echo '<span class="draft">'     . esc_html($counts->draft)   . ' Drafts</span>';
            echo '</div>';
            echo '</a>';
        }

        echo '</div>';
    }

    public function add_recent_activity_widget()
    {
        wp_add_dashboard_widget(
            'recent_activity_widget',
            'Recent Content Activity',
            array($this, 'recent_activity_widget_display')
        );
    }

    public function recent_activity_widget_display()
    {
        $recent_posts = get_posts(array(
            'numberposts' => 10,
            'post_status'  => array('publish', 'draft', 'pending'),
            'post_type'    => 'any',
            'orderby'      => 'modified',
            'order'        => 'DESC'
        ));

        echo '<div class="recent-activity-list">';
        foreach ($recent_posts as $post) {
            $author    = get_userdata($post->post_author);
            $post_type = get_post_type_object($post->post_type);
            $time_diff = human_time_diff(
                strtotime($post->post_modified),
                current_time('timestamp')
            ) . ' ago';
            $edit_link = get_edit_post_link($post->ID);
            $author_name = $author ? $author->display_name : __('Unknown author', 'blank-wordpress-theme');
            $post_type_label = $post_type ? $post_type->labels->singular_name : $post->post_type;

            echo '<a href="' . esc_url($edit_link) .
                '" class="activity-item">';
            echo '<div class="activity-content">';
            echo '<strong>' . esc_html(get_the_title($post)) . '</strong>';
            echo '<span class="post-type-badge">' . esc_html($post_type_label) . '</span>';
            echo '</div>';
            echo '<div class="activity-meta">';
            echo '<span class="author">by ' .
                esc_html($author_name) . '</span>';
            echo '<span class="time">' . esc_html($time_diff) .
                '</span>';
            echo '<span class="status status-' .
                esc_attr($post->post_status) . '">' .
                esc_html(ucfirst($post->post_status)) . '</span>';
            echo '</div>';
            echo '</a>';
        }
        echo '</div>';
    }

    public function add_quick_actions_widget()
    {
        wp_add_dashboard_widget(
            'quick_actions_widget',
            'Quick Actions',
            array($this, 'quick_actions_widget_display')
        );
    }

    public function quick_actions_widget_display()
    {
        echo '<div class="quick-actions-grid">';

        if (current_user_can('edit_posts')) {
            echo '<a href="' . esc_url(admin_url('post-new.php')) . '" class="quick-action-btn">';
            echo '<span class="dashicons dashicons-edit"></span>';
            echo '<span>New Post</span>';
            echo '</a>';
        }

        if (current_user_can('edit_pages')) {
            echo '<a href="' . esc_url(admin_url('post-new.php?post_type=page')) . '" class="quick-action-btn">';
            echo '<span class="dashicons dashicons-admin-page"></span>';
            echo '<span>New Page</span>';
            echo '</a>';
        }

        if (current_user_can('upload_files')) {
            echo '<a href="' . esc_url(admin_url('media-new.php')) . '" class="quick-action-btn">';
            echo '<span class="dashicons dashicons-admin-media"></span>';
            echo '<span>Upload Media</span>';
            echo '</a>';
        }

        $custom_post_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');
        foreach ($custom_post_types as $post_type) {
            if (current_user_can($post_type->cap->edit_posts)) {
                echo '<a href="' . esc_url(admin_url('post-new.php?post_type=' . $post_type->name)) . '" class="quick-action-btn">';
                echo '<span class="dashicons dashicons-plus"></span>';
                echo '<span>New ' . esc_html($post_type->labels->singular_name) . '</span>';
                echo '</a>';
            }
        }

        echo '</div>';
    }

    public function customize_dashboard_by_role()
    {
        $current_user = wp_get_current_user();

        $roles = is_array($current_user->roles) ? $current_user->roles : array();

        if (in_array('editor', $roles, true)) {
            remove_meta_box('dashboard_site_health', 'dashboard', 'normal');

            add_action('wp_dashboard_setup', function () {
                global $wp_meta_boxes;

                if (!isset($wp_meta_boxes['dashboard']['normal']['core']['content_overview_widget'])) {
                    return;
                }

                $content_widget = $wp_meta_boxes['dashboard']['normal']['core']['content_overview_widget'];
                unset($wp_meta_boxes['dashboard']['normal']['core']['content_overview_widget']);
                $wp_meta_boxes['dashboard']['normal']['high']['content_overview_widget'] = $content_widget;
            }, 999);
        }

        if (in_array('administrator', $roles, true)) {
            wp_add_dashboard_widget(
                'system_status_widget',
                'System Status',
                array($this, 'system_status_widget_display')
            );
        }
    }

    public function system_status_widget_display()
    {
        global $wpdb;

        echo '<div class="system-status-grid">';
        echo '<div class="status-item">';
        echo '<strong>Database Size:</strong> ';

        $db_size = $wpdb->get_var($wpdb->prepare("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS db_size FROM information_schema.tables WHERE table_schema=%s", DB_NAME));
        $db_size = $db_size !== null ? $db_size : '0';
        echo esc_html($db_size) . ' MB';
        echo '</div>';

        echo '<div class="status-item">';
        echo '<strong>WordPress Version:</strong> ' . get_bloginfo('version');
        echo '</div>';

        echo '<div class="status-item">';
        echo '<strong>PHP Version:</strong> ' . PHP_VERSION;
        echo '</div>';
        echo '</div>';
    }

    public function enqueue_custom_dashboard_styles($hook)
    {
        if ($hook !== 'index.php') {
            return;
        }
        wp_enqueue_style(
            'custom-dashboard',
            get_stylesheet_directory_uri() . '/style.css',
            array(),
            '1.0.0'
        );
    }
}
