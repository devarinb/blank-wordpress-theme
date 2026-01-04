<?php
if (! defined('ABSPATH')) {
    exit;
}

class Headless_API_Usage_Analytics
{
    private $analytics_table = 'wp_api_analytics';
    private $request_start_times = [];
    private $max_tracked_requests = 1000; // Prevent memory issues

    public function __construct()
    {
        global $wpdb;
        $this->analytics_table = $wpdb->prefix . $this->analytics_table;

        add_action('rest_api_init', array($this, 'track_api_usage'));
        add_action('wp_ajax_get_api_analytics', array($this, 'get_api_analytics'));
        add_filter('rest_post_dispatch', array($this, 'log_request_end'), 10, 3);
        
        // Clean up tracking arrays periodically
        add_action('wp_scheduled_delete', array($this, 'cleanup_tracking_arrays'));
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

        // Clean up old records
        $wpdb->query("DELETE FROM {$this->analytics_table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }

    public function cleanup_tracking_arrays()
    {
        // Clear arrays if they get too large
        if (count($this->request_start_times) > $this->max_tracked_requests) {
            $this->request_start_times = [];
        }
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
            $result = $wpdb->insert(
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
            
            // Only run cleanup occasionally to avoid performance impact
            if ($result && mt_rand(1, 100) === 1) {
                $wpdb->query("DELETE FROM {$this->analytics_table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            }
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

        $interval_map = array(
            '24h' => 'INTERVAL 24 HOUR',
            '7d' => 'INTERVAL 7 DAY',
            '30d' => 'INTERVAL 30 DAY'
        );
        
        $interval = isset($interval_map[$timeframe]) ? $interval_map[$timeframe] : 'INTERVAL 30 DAY';

        // Use prepared statements for better security
        $total_requests = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->analytics_table} WHERE timestamp >= DATE_SUB(NOW(), {$interval})"
        ));

        $unique_endpoints = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT endpoint) FROM {$this->analytics_table} WHERE timestamp >= DATE_SUB(NOW(), {$interval})"
        ));

        $avg_response_time = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(response_time) FROM {$this->analytics_table} WHERE timestamp >= DATE_SUB(NOW(), {$interval})"
        ));

        $success_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->analytics_table} WHERE status_code < 400 AND timestamp >= DATE_SUB(NOW(), {$interval})"
        ));

        $success_rate = ($total_requests > 0) ? ($success_count / $total_requests * 100) : 0;

        $most_used_endpoints = $wpdb->get_results($wpdb->prepare(
            "SELECT
                endpoint,
                COUNT(*) as usage_count,
                AVG(response_time) as avg_response_time,
                SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) / COUNT(*) * 100 as success_rate
             FROM {$this->analytics_table}
             WHERE timestamp >= DATE_SUB(NOW(), {$interval})
             GROUP BY endpoint
             ORDER BY usage_count DESC
             LIMIT 10"
        ));

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
    private $max_tracked_performance = 1000; // Prevent memory issues

    public function __construct()
    {
        global $wpdb;
        $this->analytics_table = $wpdb->prefix . $this->analytics_table;

        add_action('rest_api_init', array($this, 'add_performance_headers'));
        add_action('wp_ajax_get_performance_metrics', array($this, 'get_performance_metrics'));
        add_filter('rest_pre_dispatch', array($this, 'start_performance_tracking'), 10, 3);
        add_filter('rest_post_dispatch', array($this, 'end_performance_tracking'), 10, 3);
        
        // Clean up tracking arrays periodically
        add_action('wp_scheduled_delete', array($this, 'cleanup_performance_arrays'));
    }

    public function cleanup_performance_arrays()
    {
        // Clear arrays if they get too large
        if (count($this->performance_data) > $this->max_tracked_performance) {
            $this->performance_data = [];
        }
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

        $interval_map = array(
            '24h' => array('INTERVAL 24 HOUR', '%Y-%m-%d %H:00'),
            '7d' => array('INTERVAL 7 DAY', '%Y-%m-%d'),
            '30d' => array('INTERVAL 30 DAY', '%Y-%m-%d')
        );
        
        $config = isset($interval_map[$timeframe]) ? $interval_map[$timeframe] : $interval_map['30d'];
        $interval = $config[0];
        $date_format = $config[1];

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

        $overall_metrics = $wpdb->get_row($wpdb->prepare(
            "SELECT
                AVG(response_time) as avg_response_time,
                MIN(response_time) as min_response_time,
                MAX(response_time) as max_response_time,
                COUNT(*) as total_requests
             FROM {$this->analytics_table}
             WHERE timestamp >= DATE_SUB(NOW(), {$interval})"
        ));

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

        $interval_map = array(
            '24h' => 'INTERVAL 24 HOUR',
            '7d' => 'INTERVAL 7 DAY',
            '30d' => 'INTERVAL 30 DAY'
        );
        
        $interval = isset($interval_map[$timeframe]) ? $interval_map[$timeframe] : 'INTERVAL 30 DAY';

        $recent_errors = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->error_log_table}
             WHERE timestamp >= DATE_SUB(NOW(), {$interval})
             ORDER BY timestamp DESC
             LIMIT 100"
        ));

        wp_send_json_success($recent_errors);
    }
}
