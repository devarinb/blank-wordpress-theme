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
        add_action('admin_menu', array($this, 'headless_add_api_monitoring_menu'));
        add_action('admin_enqueue_scripts', array($this, 'headless_enqueue_monitoring_assets'));
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

    public function customize_dashboard_by_role()
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

    public function headless_add_api_monitoring_menu()
    {
        add_management_page(
            'API Monitoring',
            'API Monitoring',
            'manage_options',
            'api-monitoring',
            array($this, 'headless_api_monitoring_page')
        );
    }

    public function headless_enqueue_monitoring_assets($hook)
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

    public function headless_api_monitoring_page()
    {
        // ... (Content of the monitoring page from functions.php)
        // Since the content is very long and contains JS/HTML, I'll copy it from the previous view_file output.
        // I need to be careful to include the full content.
        // I will use a placeholder comment here and fill it in with the actual content in the next step or just include it now if I can recall it all.
        // Actually, I have the content in the history. I'll paste it.
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
                        
                        // Disable refresh button during loading
                        $('#refresh-data').prop('disabled', true).text('Loading...');
        
                        $.ajax({
                            type: 'POST',
                            url: ajaxurl,
                            data: {
                                action: 'get_api_analytics',
                                api_analytics_nonce: apiAnalyticsNonce,
                                timeframe: timeframe
                            },
                            timeout: 30000, // 30 second timeout
                            success: function(response) {
                                if (response.success && response.data) {
                                    renderAnalytics(response.data);
                                } else {
                                    console.error('Error fetching analytics:', response.data);
                                    $('#stat-cards').html('<div class="api-stat-card"><h3>No data available or error occurred.</h3></div>');
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('AJAX error for analytics:', status, error);
                                let errorMsg = 'Error loading data.';
                                if (status === 'timeout') {
                                    errorMsg = 'Request timed out. Please try again.';
                                } else if (status === 'parsererror') {
                                    errorMsg = 'Data parsing error. Please check server configuration.';
                                }
                                $('#stat-cards').html(`<div class="api-stat-card"><h3>${errorMsg}</h3></div>`);
                            },
                            complete: function() {
                                // Re-enable refresh button
                                $('#refresh-data').prop('disabled', false).text('Refresh Data');
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
                            timeout: 30000,
                            success: function(response) {
                                if (response.success && response.data) {
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
                            timeout: 30000,
                            success: function(response) {
                                if (response.success && response.data) {
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
                        if (!data) {
                            $('#stat-cards').html('<div class="api-stat-card"><h3>No data available</h3></div>');
                            return;
                        }
                        
                        const statCards = `
                        <div class="api-stat-card">
                            <h3>Total API Requests</h3>
                            <div class="stat-value">${(data.total_requests || 0).toLocaleString()}</div>
                        </div>
                        <div class="api-stat-card">
                            <h3>Unique Endpoints</h3>
                            <div class="stat-value">${data.unique_endpoints || 0}</div>
                        </div>
                        <div class="api-stat-card">
                            <h3>Avg. Response Time</h3>
                            <div class="stat-value">${data.avg_response_time ? data.avg_response_time.toFixed(2) : '0'} ms</div>
                        </div>
                        <div class="api-stat-card">
                            <h3>Success Rate</h3>
                            <div class="stat-value">${(data.success_rate || 0).toFixed(1)}%</div>
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
}
