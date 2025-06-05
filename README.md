# Blank WordPress Theme for Headless Applications

This is a blank WordPress theme specifically designed to be used in conjunction with a headless WordPress setup. It provides minimal frontend rendering and focuses on enabling the WordPress REST API for content delivery to a separate frontend application (e.g., built with React, Vue, Angular, etc.).

## Features

- **Headless Ready**: Configured to work seamlessly with a headless architecture.
- **REST API Enabled**: Ensures the WordPress REST API is fully functional for content consumption.
- **Custom Login Page Styling**: Includes custom styling for the WordPress login page to match a modern aesthetic.
- **Frontend Redirection**: Automatically redirects frontend requests to the WordPress admin area or login page, as the theme is not intended for public-facing display.
- **API Usage Analytics**: Track API endpoint usage, request methods, response times, status codes, user agents, and IP addresses.
- **Performance Monitoring**: Built-in performance metrics for API responses including response time, memory usage, peak memory, and database query counts.
- **Error Logging**: Comprehensive error logging for API issues with detailed error tracking and debugging information.

## Installation

1.  **Clone or Download**: Download or clone this repository into your WordPress themes directory (`wp-content/themes/`).
    ```bash
    git clone https://github.com/deavarinb/blank-wordpress-theme.git
    ```
2.  **Activate Theme**: Activate the `Blank WordPress Theme` from your WordPress admin dashboard (`Appearance > Themes`).
3.  **Configure Permalinks**: Ensure your permalinks are set to `Post name` or another option that enables the REST API (Settings > Permalinks).
4.  **Database Tables**: The theme will automatically create necessary database tables for analytics and error logging upon activation.

## Usage

This theme is intended to be used with a separate frontend application. WordPress will serve as your content management system (CMS), and your frontend application will consume data via the WordPress REST API.

### API Monitoring Dashboard

Access the API monitoring dashboard from your WordPress admin panel:

- Navigate to **Tools > API Monitoring**
- View real-time analytics, performance metrics, and error logs
- Monitor API usage patterns and identify potential issues

### API Usage Analytics

The theme automatically tracks:

- Total requests, unique endpoints, average response time, and success rate.
- Most frequently used API endpoints, including request methods, response times, status codes, user agents, and IP addresses.

### Error Logging

Comprehensive error tracking includes:

- API endpoint errors (4xx, 5xx status codes)
- Error codes and detailed messages
- Stack traces for debugging
- Request data for error reproduction
- User and IP tracking for security analysis

### Login Page Customization

The theme includes custom styles for the WordPress login page. These styles are defined in `style.css` and enqueued via `functions.php`.

- `functions.php`: Contains the `custom_login_stylesheet` function to enqueue the custom `style.css` for the login page and other login-related customizations (e.g., logo URL, error messages).
- `style.css`: Provides the visual styling for the login form, inputs, buttons, and other elements.

### Frontend Redirection

The `redirect_frontend` function in `functions.php` ensures that any direct access to the WordPress frontend is redirected. If a user is logged in, they are redirected to the admin dashboard; otherwise, they are sent to the login page. This prevents accidental exposure of an unstyled or incomplete WordPress frontend.

## API Endpoints

The theme adds custom AJAX endpoints for monitoring data:

- `wp-admin/admin-ajax.php?action=get_api_analytics` - Get API usage statistics
- `wp-admin/admin-ajax.php?action=get_performance_metrics` - Get performance data
- `wp-admin/admin-ajax.php?action=get_error_logs` - Get recent error logs

_Note: These endpoints require administrator privileges._

## Development

If you need to further customize the login page or other aspects of this theme:

1.  **Modify `style.css`**: Adjust the CSS variables or add new rules to `style.css` to change the visual appearance.
2.  **Modify `functions.php`**: Extend or modify the existing functions in `functions.php` for custom WordPress behaviors, such as adding new REST API endpoints or further customizing the login process.

## Performance Considerations

- Analytics data is stored efficiently with proper database indexing
- Performance monitoring adds minimal overhead to API requests
- Error logging only activates for actual errors to minimize database writes
- Consider implementing data retention policies for large-scale deployments

## Security

- All monitoring endpoints require administrator privileges
- IP addresses and user agents are logged for security analysis
- Error logs include stack traces for debugging but should be secured in production

## Contributing

Feel free to contribute to this project by submitting issues or pull requests.
