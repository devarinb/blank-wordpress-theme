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
        add_action('wp_ajax_nopriv_get_api_analytics', array($this, 'get_api_analytics'));
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
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
                )
            );
        }
        return $response;
    }

    public function get_api_analytics()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $timeframe = isset($_GET['timeframe']) ? sanitize_text_field($_GET['timeframe']) : '30d';

        switch ($timeframe) {
            case '24h':
                $interval = 'INTERVAL 24 HOUR';
                break;
            case '7d':
                $interval = 'INTERVAL 7 DAY';
                break;
            case '30d':
            default:
                $interval = 'INTERVAL 30 DAY';
                break;
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

            $response->header('X-Response-Time', round($execution_time, 2) . 'ms');
            $response->header('X-Memory-Usage', round($memory_usage, 2) . 'MB');
            $response->header('X-Peak-Memory', round($peak_memory, 2) . 'MB');
            $response->header('X-Query-Count', get_num_queries());

            unset($this->performance_data[$request_id]);
        }

        return $response;
    }

    public function get_performance_metrics()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $timeframe = isset($_GET['timeframe']) ? sanitize_text_field($_GET['timeframe']) : '30d';

        switch ($timeframe) {
            case '24h':
                $interval = 'INTERVAL 24 HOUR';
                $date_format = '%Y-%m-%d %H:00';
                break;
            case '7d':
                $interval = 'INTERVAL 7 DAY';
                $date_format = '%Y-%m-%d';
                break;
            case '30d':
            default:
                $interval = 'INTERVAL 30 DAY';
                $date_format = '%Y-%m-%d';
                break;
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
                    'request_data' => json_encode($request->get_params()),
                    'user_id' => get_current_user_id(),
                    'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '')
                )
            );
        }

        return $response;
    }

    public function get_error_logs()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $timeframe = isset($_GET['timeframe']) ? sanitize_text_field($_GET['timeframe']) : '30d';

        switch ($timeframe) {
            case '24h':
                $interval = 'INTERVAL 24 HOUR';
                break;
            case '7d':
                $interval = 'INTERVAL 7 DAY';
                break;
            case '30d':
            default:
                $interval = 'INTERVAL 30 DAY';
                break;
        }

        $sql = "SELECT * FROM {$this->error_log_table}
                 WHERE timestamp >= DATE_SUB(NOW(), {$interval})
                 ORDER BY timestamp DESC
                 LIMIT 100";
        $recent_errors = $wpdb->get_results($sql);

        wp_send_json_success($recent_errors);
    }
}

// Initialize classes
$analytics_instance = null;
$performance_instance = null;
$error_logger_instance = null;

function headless_api_monitor_init_tables()
{
    global $analytics_instance, $performance_instance, $error_logger_instance;
    $temp_analytics = new Headless_API_Usage_Analytics();
    $temp_analytics->create_analytics_table();
    $temp_error_logger = new Headless_API_Error_Logger();
    $temp_error_logger->create_error_log_table();
    $analytics_instance = new Headless_API_Usage_Analytics();
    $performance_instance = new Headless_API_Performance_Monitor();
    $error_logger_instance = new Headless_API_Error_Logger();
}
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

            function fetchData(timeframe = '30d') {
                $('#stat-cards').html('<div class="api-stat-card"><h3>Loading...</h3></div>');

                $.ajax({
                    url: ajaxurl,
                    data: {
                        action: 'get_api_analytics',
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
                    url: ajaxurl,
                    data: {
                        action: 'get_performance_metrics',
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
                    url: ajaxurl,
                    data: {
                        action: 'get_error_logs',
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
                        item.endpoint,
                        item.usage_count,
                        item.avg_response_time ? item.avg_response_time.toFixed(2) : '0',
                        `${item.success_rate ? item.success_rate.toFixed(1) : '0'}%`
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
                        item.date,
                        (parseFloat(item.avg_response_time) || 0).toFixed(2),
                        (parseFloat(item.max_response_time) || 0).toFixed(2),
                        (parseFloat(item.min_response_time) || 0).toFixed(2),
                        item.total_requests
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
                        new Date(item.timestamp).toLocaleString(),
                        item.endpoint,
                        item.error_code,
                        item.error_message,
                        item.ip_address
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

// Login customization functions
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
        return $content . '<div class="login-divider"></div>';
    });
}
add_action('login_init', 'custom_login_form');
