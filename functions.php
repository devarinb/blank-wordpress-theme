<?php
if (! defined('ABSPATH')) {
    exit;
}

function headless_theme_setup()
{
    add_filter('rest_enabled', '__return_true');
    add_filter('rest_jsonp_enabled', '__return_true');
}
add_action('after_setup_theme', 'headless_theme_setup');

class Headless_API_Usage_Analytics
{
    private $analytics_table = 'wp_api_analytics';
    private $request_start_times = [];

    public function __construct()
    {
        global $wpdb;
        $this->analytics_table = $wpdb->prefix . $this->analytics_table;

        add_action('rest_api_init', array($this, 'track_api_usage'));
        add_action('wp_ajax_get_api_analytics', array($this, 'get_api_analytics'));
        add_filter('rest_post_dispatch', array($this, 'log_request_end'), 10, 3);
    }

    public function create_analytics_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->analytics_table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            endpoint varchar(255) NOT NULL,
            method varchar(10) NOT NULL,
            response_time float NOT NULL,
            status_code int NOT NULL,
            user_agent text,
            ip_address varchar(45),
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX endpoint_idx (endpoint),
            INDEX timestamp_idx (timestamp)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $wpdb->query("DELETE FROM {$this->analytics_table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }

    public function track_api_usage()
    {
        add_filter('rest_pre_dispatch', array($this, 'log_api_request'), 10, 3);
    }

    public function log_api_request($result, $server, $request)
    {
        $request_id = spl_object_hash($request);
        $this->request_start_times[$request_id] = microtime(true);
        return $result;
    }

    public function log_request_end($response, $server, $request)
    {
        $request_id = spl_object_hash($request);
        if (isset($this->request_start_times[$request_id])) {
            $response_time = (microtime(true) - $this->request_start_times[$request_id]) * 1000;
            unset($this->request_start_times[$request_id]);

            global $wpdb;
            $wpdb->insert(
                $this->analytics_table,
                array(
                    'endpoint' => $request->get_route(),
                    'method' => $request->get_method(),
                    'response_time' => $response_time,
                    'status_code' => $response->get_status(),
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                    'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : ''
                )
            );
            // Purge old analytics data
            $wpdb->query("DELETE FROM {$this->analytics_table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        }
        return $response;
    }

    public function get_api_analytics()
    {
        if (
            !current_user_can('manage_options') ||
            !isset($_POST['api_analytics_nonce']) ||
            !wp_verify_nonce($_POST['api_analytics_nonce'], 'get_api_analytics')
        ) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $timeframe = isset($_POST['timeframe']) ? sanitize_key($_POST['timeframe']) : '30d';

        $interval = 'INTERVAL 30 DAY';
        if ($timeframe === '24h') {
            $interval = 'INTERVAL 24 HOUR';
        } elseif ($timeframe === '7d') {
            $interval = 'INTERVAL 7 DAY';
        }

        $sql = "SELECT COUNT(*) FROM {$this->analytics_table} WHERE timestamp >= DATE_SUB(NOW(), {$interval})";
        $total_requests = $wpdb->get_var($sql);

        $sql = "SELECT COUNT(DISTINCT endpoint) FROM {$this->analytics_table} WHERE timestamp >= DATE_SUB(NOW(), {$interval})";
        $unique_endpoints = $wpdb->get_var($sql);

        $sql = "SELECT AVG(response_time) FROM {$this->analytics_table} WHERE timestamp >= DATE_SUB(NOW(), {$interval})";
        $avg_response_time = $wpdb->get_var($sql);

        $sql = "SELECT COUNT(*) FROM {$this->analytics_table} WHERE status_code < 400 AND timestamp >= DATE_SUB(NOW(), {$interval})";
        $success_count = $wpdb->get_var($sql);

        $success_rate = ($total_requests > 0) ? ($success_count / $total_requests * 100) : 0;

        $sql = "SELECT
                    endpoint,
                    COUNT(*) as usage_count,
                    AVG(response_time) as avg_response_time,
                    SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) / COUNT(*) * 100 as success_rate
                 FROM {$this->analytics_table}
                 WHERE timestamp >= DATE_SUB(NOW(), {$interval})
                 GROUP BY endpoint
                 ORDER BY usage_count DESC
                 LIMIT 10";
        $most_used_endpoints = $wpdb->get_results($sql);

        $response = array(
            'total_requests' => (int)$total_requests,
            'unique_endpoints' => (int)$unique_endpoints,
            'avg_response_time' => (float)$avg_response_time,
            'success_rate' => (float)$success_rate,
            'most_used_endpoints' => $most_used_endpoints
        );

        wp_send_json_success($response);
    }
}

class Headless_API_Performance_Monitor
{
    private $analytics_table = 'wp_api_analytics';
    private $performance_data = [];

    public function __construct()
    {
        global $wpdb;
        $this->analytics_table = $wpdb->prefix . $this->analytics_table;

        add_action('rest_api_init', array($this, 'add_performance_headers'));
        add_action('wp_ajax_get_performance_metrics', array($this, 'get_performance_metrics'));
        add_filter('rest_pre_dispatch', array($this, 'start_performance_tracking'), 10, 3);
        add_filter('rest_post_dispatch', array($this, 'end_performance_tracking'), 10, 3);
    }

    public function add_performance_headers()
    {
        // Headers are added in end_performance_tracking
    }

    public function start_performance_tracking($result, $server, $request)
    {
        $request_id = spl_object_hash($request);
        $this->performance_data[$request_id] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage()
        ];
        return $result;
    }

    public function end_performance_tracking($response, $server, $request)
    {
        $request_id = spl_object_hash($request);

        if (isset($this->performance_data[$request_id])) {
            $start_data = $this->performance_data[$request_id];
            $end_time = microtime(true);
            $end_memory = memory_get_usage();

            $execution_time = ($end_time - $start_data['start_time']) * 1000;
            $memory_usage = ($end_memory - $start_data['start_memory']) / 1024 / 1024;
            $peak_memory = memory_get_peak_usage() / 1024 / 1024;

            if (current_user_can('manage_options')) {
                $response->header('X-Response-Time', round($execution_time, 2) . 'ms');
                $response->header('X-Memory-Usage', round($memory_usage, 2) . 'MB');
                $response->header('X-Peak-Memory', round($peak_memory, 2) . 'MB');
                $response->header('X-Query-Count', get_num_queries());
            }

            unset($this->performance_data[$request_id]);
        }

        return $response;
    }

    public function get_performance_metrics()
    {
        if (
            !current_user_can('manage_options') ||
            !isset($_POST['performance_metrics_nonce']) ||
            !wp_verify_nonce($_POST['performance_metrics_nonce'], 'get_performance_metrics')
        ) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $timeframe = isset($_POST['timeframe']) ? sanitize_text_field($_POST['timeframe']) : '30d';

        $interval = 'INTERVAL 30 DAY';
        $date_format = '%Y-%m-%d';
        if ($timeframe === '24h') {
            $interval = 'INTERVAL 24 HOUR';
            $date_format = '%Y-%m-%d %H:00';
        } elseif ($timeframe === '7d') {
            $interval = 'INTERVAL 7 DAY';
        }

        $daily_metrics = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    DATE_FORMAT(timestamp, %s) as date,
                    AVG(response_time) as avg_response_time,
                    MIN(response_time) as min_response_time,
                    MAX(response_time) as max_response_time,
                    COUNT(*) as total_requests
                 FROM {$this->analytics_table}
                 WHERE timestamp >= DATE_SUB(NOW(), {$interval})
                 GROUP BY DATE_FORMAT(timestamp, %s)
                 ORDER BY date ASC",
                $date_format,
                $date_format
            )
        );

        $sql = "SELECT
                    AVG(response_time) as avg_response_time,
                    MIN(response_time) as min_response_time,
                    MAX(response_time) as max_response_time,
                    COUNT(*) as total_requests
                 FROM {$this->analytics_table}
                 WHERE timestamp >= DATE_SUB(NOW(), {$interval})";
        $overall_metrics = $wpdb->get_row($sql);

        $response = array(
            'daily_metrics' => $daily_metrics,
            'overall' => $overall_metrics
        );

        wp_send_json_success($response);
    }
}

class Headless_API_Error_Logger
{
    private $error_log_table = 'wp_api_error_logs';

    public function __construct()
    {
        global $wpdb;
        $this->error_log_table = $wpdb->prefix . $this->error_log_table;

        add_action('rest_api_init', array($this, 'setup_error_logging'));
        add_action('wp_ajax_get_error_logs', array($this, 'get_error_logs'));
    }

    public function create_error_log_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->error_log_table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            endpoint varchar(255) NOT NULL,
            error_code varchar(50),
            error_message text,
            stack_trace text,
            request_data text,
            user_id int,
            ip_address varchar(45),
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX endpoint_idx (endpoint),
            INDEX timestamp_idx (timestamp),
            INDEX error_code_idx (error_code)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $wpdb->query("DELETE FROM {$this->error_log_table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }

    public function setup_error_logging()
    {
        add_filter('rest_post_dispatch', array($this, 'log_api_errors'), 10, 3);
    }

    public function log_api_errors($response, $server, $request)
    {
        if ($response->is_error() || $response->get_status() >= 400) {
            global $wpdb;

            $error_data = $response->get_data();
            $error_message = isset($error_data['message']) ? sanitize_textarea_field($error_data['message']) : 'Unknown error';
            $error_code = isset($error_data['code']) ? sanitize_text_field($error_data['code']) : $response->get_status();

            $wpdb->insert(
                $this->error_log_table,
                array(
                    'endpoint' => sanitize_text_field($request->get_route()),
                    'error_code' => $error_code,
                    'error_message' => $error_message,
                    'stack_trace' => wp_debug_backtrace_summary(),
                    'request_data' => wp_json_encode($this->redact_sensitive_params($request->get_params())),
                    'user_id' => get_current_user_id(),
                    'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : ''
                )
            );
            // Purge old error logs
            $wpdb->query("DELETE FROM {$this->error_log_table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        }

        return $response;
    }

    private function redact_sensitive_params($params) {
        $sensitive_keys = array('password', 'pass', 'pwd', 'api_key', 'token', 'secret');
        foreach ($params as $k => $v) {
            foreach ($sensitive_keys as $sk) {
                if (stripos($k, $sk) !== false) {
                    $params[$k] = 'REDACTED';
                }
            }
        }
        return $params;
    }
    public function get_error_logs()
    {
        if (
            !current_user_can('manage_options') ||
            !isset($_POST['error_logs_nonce']) ||
            !wp_verify_nonce($_POST['error_logs_nonce'], 'get_error_logs')
        ) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $timeframe = isset($_POST['timeframe']) ? sanitize_key($_POST['timeframe']) : '30d';

        $interval = 'INTERVAL 30 DAY';
        if ($timeframe === '24h') {
            $interval = 'INTERVAL 24 HOUR';
        } elseif ($timeframe === '7d') {
            $interval = 'INTERVAL 7 DAY';
        }

        $sql = "SELECT * FROM {$this->error_log_table}
                 WHERE timestamp >= DATE_SUB(NOW(), {$interval})
                 ORDER BY timestamp DESC
                 LIMIT 100";
        $recent_errors = $wpdb->get_results($sql);

        wp_send_json_success($recent_errors);
    }
}


// Remove default dashboard widgets
function remove_default_dashboard_widgets()
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
add_action('wp_dashboard_setup', 'remove_default_dashboard_widgets');
function add_content_overview_widget()
{
    wp_add_dashboard_widget(
        'content_overview_widget',
        'Content Overview',
        'content_overview_widget_display'
    );
}

function content_overview_widget_display()
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
add_action('wp_dashboard_setup', 'add_content_overview_widget');



function add_recent_activity_widget()
{
    wp_add_dashboard_widget(
        'recent_activity_widget',
        'Recent Content Activity',
        'recent_activity_widget_display'
    );
}

function recent_activity_widget_display()
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
        $time_diff = human_time_diff(
            strtotime($post->post_modified),
            current_time('timestamp')
        ) . ' ago';
        $edit_link = get_edit_post_link($post->ID);

        echo '<a href="' . esc_url($edit_link) .
            '" class="activity-item">';
        echo '<div class="activity-content">';
        echo '<strong>' . esc_html(get_the_title($post)) . '</strong>';
        echo '<span class="post-type-badge">' .
            esc_html(get_post_type_object($post->post_type)
                ->labels->singular_name) .
            '</span>';
        echo '</div>';
        echo '<div class="activity-meta">';
        echo '<span class="author">by ' .
            esc_html($author->display_name) . '</span>';
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
add_action('wp_dashboard_setup', 'add_recent_activity_widget');
function add_quick_actions_widget()
{
    wp_add_dashboard_widget(
        'quick_actions_widget',
        'Quick Actions',
        'quick_actions_widget_display'
    );
}

function quick_actions_widget_display()
{
    echo '<div class="quick-actions-grid">';

    if (current_user_can('edit_posts')) {
        echo '<a href="' . admin_url('post-new.php') . '" class="quick-action-btn">';
        echo '<span class="dashicons dashicons-edit"></span>';
        echo '<span>New Post</span>';
        echo '</a>';
    }

    if (current_user_can('edit_pages')) {
        echo '<a href="' . admin_url('post-new.php?post_type=page') . '" class="quick-action-btn">';
        echo '<span class="dashicons dashicons-admin-page"></span>';
        echo '<span>New Page</span>';
        echo '</a>';
    }

    if (current_user_can('upload_files')) {
        echo '<a href="' . admin_url('media-new.php') . '" class="quick-action-btn">';
        echo '<span class="dashicons dashicons-admin-media"></span>';
        echo '<span>Upload Media</span>';
        echo '</a>';
    }

    if (current_user_can('manage_options')) {
        echo '<a href="' . admin_url('tools.php?page=api-monitoring') . '" class="quick-action-btn">';
        echo '<span class="dashicons dashicons-chart-line"></span>';
        echo '<span>API Analytics</span>';
        echo '</a>';
    }

    $custom_post_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');
    foreach ($custom_post_types as $post_type) {
        if (current_user_can('edit_posts')) {
            echo '<a href="' . esc_url(admin_url('post-new.php?post_type=' . $post_type->name)) . '" class="quick-action-btn">';
            echo '<span class="dashicons dashicons-plus"></span>';
            echo '<span>New ' . esc_html($post_type->labels->singular_name) . '</span>';
            echo '</a>';
        }
    }

    echo '</div>';
}
add_action('wp_dashboard_setup', 'add_quick_actions_widget');
function customize_dashboard_by_role()
{
    $current_user = wp_get_current_user();

    if (in_array('editor', $current_user->roles)) {
        remove_meta_box('dashboard_site_health', 'dashboard', 'normal');

        add_action('wp_dashboard_setup', function () {
            global $wp_meta_boxes;

            $content_widget = $wp_meta_boxes['dashboard']['normal']['core']['content_overview_widget'];
            unset($wp_meta_boxes['dashboard']['normal']['core']['content_overview_widget']);
            $wp_meta_boxes['dashboard']['normal']['high']['content_overview_widget'] = $content_widget;
        }, 999);
    }

    if (in_array('administrator', $current_user->roles)) {
        wp_add_dashboard_widget(
            'system_status_widget',
            'System Status',
            'system_status_widget_display'
        );
    }
}

function system_status_widget_display()
{
    global $wpdb;

    echo '<div class="system-status-grid">';
    echo '<div class="status-item">';
    echo '<strong>Database Size:</strong> ';

    $db_size = $wpdb->get_var($wpdb->prepare("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS db_size FROM information_schema.tables WHERE table_schema=%s", DB_NAME));
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

add_action('wp_dashboard_setup', 'customize_dashboard_by_role');

function enqueue_custom_dashboard_styles($hook)
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
add_action('admin_enqueue_scripts', 'enqueue_custom_dashboard_styles');


$analytics_instance = null;
$performance_instance = null;
$error_logger_instance = null;

function headless_api_monitor_init_tables() {
    global $analytics_instance, $performance_instance, $error_logger_instance;
    $analytics_instance = new Headless_API_Usage_Analytics();
    $performance_instance = new Headless_API_Performance_Monitor();
    $error_logger_instance = new Headless_API_Error_Logger();
}
register_activation_hook(__FILE__, function () {
    $temp_analytics = new Headless_API_Usage_Analytics();
    $temp_analytics->create_analytics_table();
    $temp_error_logger = new Headless_API_Error_Logger();
    $temp_error_logger->create_error_log_table();
});
add_action('init', 'headless_api_monitor_init_tables');

function headless_add_api_monitoring_menu()
{
    add_management_page(
        'API Monitoring',
        'API Monitoring',
        'manage_options',
        'api-monitoring',
        'headless_api_monitoring_page'
    );
}
add_action('admin_menu', 'headless_add_api_monitoring_menu');

function headless_enqueue_monitoring_assets($hook)
{
    if ($hook != 'tools_page_api-monitoring') {
        return;
    }

    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js', array(), '4.4.9', true);
    wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css');
    wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js', array('jquery'), '1.11.5', true);
    wp_enqueue_style(
        'blank-wordpress-theme-style',
        get_stylesheet_uri(),
        array('datatables-css'),
        wp_get_theme()->get('Version')
    );
}
add_action('admin_enqueue_scripts', 'headless_enqueue_monitoring_assets');

function headless_api_monitoring_page()
{
?>
    <div class="wrap">
        <h1>API Monitoring Dashboard</h1>

        <div class="api-monitoring-dashboard">
            <div class="api-monitoring-header">
                <h2>Overview</h2>
                <div>
                    <select class="time-filter" id="time-filter">
                        <option value="24h">Last 24 Hours</option>
                        <option value="7d">Last 7 Days</option>
                        <option value="30d" selected>Last 30 Days</option>
                    </select>
                    <button class="refresh-button" id="refresh-data">Refresh Data</button>
                </div>
            </div>

            <div class="api-stat-cards" id="stat-cards">
                <div class="api-stat-card">
                    <h3>Loading...</h3>
                </div>
            </div>

            <div class="api-monitoring-tabs">
                <div class="api-monitoring-tab active" data-tab="usage">Usage Analytics</div>
                <div class="api-monitoring-tab" data-tab="performance">Performance</div>
                <div class="api-monitoring-tab" data-tab="errors">Error Logs</div>
            </div>

            <div class="api-monitoring-panel active" id="usage-panel">
                <div class="chart-container">
                    <canvas id="endpoints-chart"></canvas>
                </div>
                <h3>Most Used Endpoints</h3>
                <table id="endpoints-table" class="display" style="width:100%">
                    <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th>Requests</th>
                            <th>Avg. Response Time (ms)</th>
                            <th>Success Rate</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="api-monitoring-panel" id="performance-panel">
                <div class="chart-container">
                    <canvas id="performance-chart"></canvas>
                </div>
                <h3>Performance Metrics</h3>
                <table id="performance-table" class="display" style="width:100%">
                    <thead>
                        <tr>
                            <th>Date/Hour</th>
                            <th>Avg. Response Time (ms)</th>
                            <th>Max Response Time (ms)</th>
                            <th>Min Response Time (ms)</th>
                            <th>Total Requests</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="api-monitoring-panel" id="errors-panel">
                <div class="chart-container">
                    <canvas id="errors-chart"></canvas>
                </div>
                <h3>Recent Errors</h3>
                <table id="errors-table" class="display" style="width:100%">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Endpoint</th>
                            <th>Error Code</th>
                            <th>Error Message</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        var apiAnalyticsNonce = '<?php echo wp_create_nonce("get_api_analytics"); ?>';
        var performanceMetricsNonce = '<?php echo wp_create_nonce("get_performance_metrics"); ?>';
        var errorLogsNonce = '<?php echo wp_create_nonce("get_error_logs"); ?>';
        jQuery(document).ready(function($) {
            $('.api-monitoring-tab').on('click', function() {
                $('.api-monitoring-tab').removeClass('active');
                $(this).addClass('active');

                const tabId = $(this).data('tab');
                $('.api-monitoring-panel').removeClass('active');
                $(`#${tabId}-panel`).addClass('active');
            });

            let endpointsChart, performanceChart, errorsChart;
            let endpointsTable, performanceTable, errorsTable;

            function escapeHTML(str) {
                return String(str).replace(/[&<>"'`=\/]/g, function (s) {
                    return ({
                        '&': '&',
                        '<': '<',
                        '>': '>',
                        '"': '"',
                        "'": ''",
                        '`': '&#96;',
                        '=': '&#61;',
                        '/': '&#47;'
                    })[s];
                });
            }
            function fetchData(timeframe = '30d') {
                $('#stat-cards').html('<div class="api-stat-card"><h3>Loading...</h3></div>');

                $.ajax({
                    type: 'POST',
                    url: ajaxurl,
                    data: {
                        action: 'get_api_analytics',
                        api_analytics_nonce: apiAnalyticsNonce,
                        timeframe: timeframe
                    },
                    success: function(response) {
                        if (response.success) {
                            renderAnalytics(response.data);
                        } else {
                            console.error('Error fetching analytics:', response.data);
                            $('#stat-cards').html('<div class="api-stat-card"><h3>Error loading data.</h3></div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error for analytics:', status, error);
                        $('#stat-cards').html('<div class="api-stat-card"><h3>Error loading data.</h3></div>');
                    }
                });

                $.ajax({
                    type: 'POST',
                    url: ajaxurl,
                    data: {
                        action: 'get_performance_metrics',
                        performance_metrics_nonce: performanceMetricsNonce,
                        timeframe: timeframe
                    },
                    success: function(response) {
                        if (response.success) {
                            renderPerformance(response.data);
                        } else {
                            console.error('Error fetching performance:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error for performance:', status, error);
                    }
                });

                $.ajax({
                    type: 'POST',
                    url: ajaxurl,
                    data: {
                        action: 'get_error_logs',
                        error_logs_nonce: errorLogsNonce,
                        timeframe: timeframe
                    },
                    success: function(response) {
                        if (response.success) {
                            renderErrors(response.data);
                        } else {
                            console.error('Error fetching errors:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error for errors:', status, error);
                    }
                });
            }

            function renderAnalytics(data) {
                const statCards = `
                <div class="api-stat-card">
                    <h3>Total API Requests</h3>
                    <div class="stat-value">${data.total_requests.toLocaleString()}</div>
                </div>
                <div class="api-stat-card">
                    <h3>Unique Endpoints</h3>
                    <div class="stat-value">${data.unique_endpoints}</div>
                </div>
                <div class="api-stat-card">
                    <h3>Avg. Response Time</h3>
                    <div class="stat-value">${data.avg_response_time ? data.avg_response_time.toFixed(2) : '0'} ms</div>
                </div>
                <div class="api-stat-card">
                    <h3>Success Rate</h3>
                    <div class="stat-value">${data.success_rate.toFixed(1)}%</div>
                </div>
            `;
                $('#stat-cards').html(statCards);

                if (data.most_used_endpoints && data.most_used_endpoints.length > 0) {
                    const labels = data.most_used_endpoints.map(item => {
                        const parts = item.endpoint.split('/');
                        if (parts.length >= 2 && parts[parts.length - 1] !== '') {
                            return parts.slice(-2).join('/');
                        } else if (parts.length >= 1 && parts[parts.length - 1] === '') {
                            return parts[parts.length - 2] || '/';
                        }
                        return parts[parts.length - 1] || '/';
                    });
                    const counts = data.most_used_endpoints.map(item => item.usage_count);

                    const ctx = document.getElementById('endpoints-chart').getContext('2d');
                    if (endpointsChart) {
                        endpointsChart.destroy();
                    }
                    endpointsChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Request Count',
                                data: counts,
                                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                                borderColor: 'rgba(54, 162, 235, 1)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        title: function(context) {
                                            return data.most_used_endpoints[context[0].dataIndex].endpoint;
                                        }
                                    }
                                }
                            }
                        }
                    });

                    if (endpointsTable) {
                        endpointsTable.destroy();
                    }

                    const tableData = data.most_used_endpoints.map(item => [
                        escapeHTML(item.endpoint),
                        escapeHTML(String(item.usage_count)),
                        escapeHTML(item.avg_response_time ? item.avg_response_time.toFixed(2) : '0'),
                        `${item.success_rate ? escapeHTML(item.success_rate.toFixed(1)) : '0'}%`
                    ]);

                    endpointsTable = $('#endpoints-table').DataTable({
                        data: tableData,
                        order: [
                            [1, 'desc']
                        ],
                        pageLength: 10,
                        destroy: true
                    });
                }
            }

            function renderPerformance(data) {
                if (data.daily_metrics && data.daily_metrics.length > 0) {
                    const dates = data.daily_metrics.map(item => item.date);
                    const avgTimes = data.daily_metrics.map(item => parseFloat(item.avg_response_time) || 0);
                    const maxTimes = data.daily_metrics.map(item => parseFloat(item.max_response_time) || 0);

                    const ctx = document.getElementById('performance-chart').getContext('2d');
                    if (performanceChart) {
                        performanceChart.destroy();
                    }
                    performanceChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: dates,
                            datasets: [{
                                    label: 'Avg Response Time (ms)',
                                    data: avgTimes,
                                    borderColor: 'rgba(75, 192, 192, 1)',
                                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                                    tension: 0.1,
                                    fill: true
                                },
                                {
                                    label: 'Max Response Time (ms)',
                                    data: maxTimes,
                                    borderColor: 'rgba(255, 99, 132, 1)',
                                    backgroundColor: 'rgba(255, 99, 132, 0)',
                                    borderDash: [5, 5],
                                    tension: 0.1,
                                    fill: false
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });

                    if (performanceTable) {
                        performanceTable.destroy();
                    }

                    const tableData = data.daily_metrics.map(item => [
                        escapeHTML(item.date),
                        escapeHTML((parseFloat(item.avg_response_time) || 0).toFixed(2)),
                        escapeHTML((parseFloat(item.max_response_time) || 0).toFixed(2)),
                        escapeHTML((parseFloat(item.min_response_time) || 0).toFixed(2)),
                        escapeHTML(String(item.total_requests))
                    ]);

                    performanceTable = $('#performance-table').DataTable({
                        data: tableData,
                        order: [
                            [0, 'desc']
                        ],
                        pageLength: 10,
                        destroy: true
                    });
                }
            }

            function renderErrors(data) {
                if (data && data.length > 0) {
                    const errorCodes = {};
                    data.forEach(item => {
                        if (!errorCodes[item.error_code]) {
                            errorCodes[item.error_code] = 0;
                        }
                        errorCodes[item.error_code]++;
                    });

                    const labels = Object.keys(errorCodes);
                    const counts = Object.values(errorCodes);

                    const ctx = document.getElementById('errors-chart').getContext('2d');
                    if (errorsChart) {
                        errorsChart.destroy();
                    }
                    errorsChart = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: counts,
                                backgroundColor: [
                                    'rgba(255, 99, 132, 0.7)',
                                    'rgba(255, 159, 64, 0.7)',
                                    'rgba(255, 205, 86, 0.7)',
                                    'rgba(75, 192, 192, 0.7)',
                                    'rgba(54, 162, 235, 0.7)',
                                    'rgba(153, 102, 255, 0.7)',
                                    'rgba(201, 203, 207, 0.7)'
                                ],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right'
                                }
                            }
                        }
                    });

                    if (errorsTable) {
                        errorsTable.destroy();
                    }

                    const tableData = data.map(item => [
                        escapeHTML(new Date(item.timestamp).toLocaleString()),
                        escapeHTML(item.endpoint),
                        escapeHTML(item.error_code),
                        escapeHTML(item.error_message),
                        escapeHTML(item.ip_address)
                    ]);

                    errorsTable = $('#errors-table').DataTable({
                        data: tableData,
                        order: [
                            [0, 'desc']
                        ],
                        pageLength: 10,
                        destroy: true,
                        createdRow: function(row, data) {
                            const errorCode = String(data[2]);
                            if (errorCode.startsWith('5')) {
                                $(row).addClass('error-log-high');
                            } else if (errorCode.startsWith('4')) {
                                $(row).addClass('error-log-medium');
                            }
                        }
                    });
                }
            }

            fetchData();

            $('#refresh-data').on('click', function() {
                const timeframe = $('#time-filter').val();
                fetchData(timeframe);
            });

            $('#time-filter').on('change', function() {
                const timeframe = $(this).val();
                fetchData(timeframe);
            });
        });
    </script>
<?php
}

function custom_login_stylesheet()
{
    wp_enqueue_style('custom-login', get_stylesheet_directory_uri() . '/style.css', array(), '1.0.1');
}
add_action('login_enqueue_scripts', 'custom_login_stylesheet');

function custom_login_logo_url()
{
    return home_url();
}
add_filter('login_headerurl', 'custom_login_logo_url');

function custom_login_logo_url_title()
{
    return get_bloginfo('name');
}
add_filter('login_headertext', 'custom_login_logo_url_title');

function custom_login_form_labels($translated_text, $text, $domain)
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
add_filter('gettext', 'custom_login_form_labels', 20, 3);

function custom_login_error_message()
{
    return 'Invalid login credentials.';
}
add_filter('login_errors', 'custom_login_error_message');

function init_login_page_vars()
{
    global $user_login, $error;
    $user_login = '';
    $error = '';
}
add_action('login_init', 'init_login_page_vars');

function handle_login_form()
{
    if (isset($_POST['wp-submit'])) {
        check_admin_referer('login', 'login_nonce');
        $user = wp_signon();
        if (is_wp_error($user)) {
            $error = $user->get_error_message();
        } else {
            wp_redirect(admin_url());
            exit;
        }
    }
}
add_action('login_init', 'handle_login_form');

function redirect_frontend()
{
    if (
        strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false ||
        is_admin() ||
        strpos($_SERVER['REQUEST_URI'], 'wp-login.php?action=logout') !== false
    ) {
        return;
    }
    if (is_user_logged_in()) {
        wp_redirect(admin_url());
        exit;
    }
    wp_redirect(wp_login_url());
    exit;
}
add_action('template_redirect', 'redirect_frontend');

function custom_login_form()
{
    add_filter('login_form_middle', function ($content) {
        if (isset($_POST['wp-submit'])) {
            wp_nonce_field('login', 'login_nonce');
        }
        return $content . '<div class="login-divider"></div>';
    });
}
add_action('login_init', 'custom_login_form');
