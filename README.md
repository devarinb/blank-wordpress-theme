# Blank WordPress Theme for Headless Applications

This is a blank WordPress theme specifically designed to be used in conjunction with a headless WordPress setup. It provides minimal frontend rendering and focuses on enabling the WordPress REST API for content delivery to a separate frontend application (e.g., built with React, Vue, Angular, etc.).

## Features

- **Headless Ready**: Configured to work seamlessly with a headless architecture.
- **REST API Enabled**: Ensures the WordPress REST API is fully functional for content consumption.
- **Frontend Redirection**: Automatically redirects frontend requests to the WordPress admin area or login page, as the theme is not intended for public-facing display.
- **Dashboard Customizations**: Enhances the WordPress admin dashboard with custom widgets for content overview, recent activity, quick actions, and system status, while removing default, less relevant widgets.

## Installation

1.  **Clone or Download**: Download or clone this repository into your WordPress themes directory (`wp-content/themes/`).
    ```bash
    git clone https://github.com/deavarinb/blank-wordpress-theme.git
    ```
2.  **Activate Theme**: Activate the `Blank WordPress Theme` from your WordPress admin dashboard (`Appearance > Themes`).
3.  **Configure Permalinks**: Ensure your permalinks are set to `Post name` or another option that enables the REST API (Settings > Permalinks).

## Usage

This theme is intended to be used with a separate frontend application. WordPress will serve as your content management system (CMS), and your frontend application will consume data via the WordPress REST API.

### Login Page

The theme uses the default WordPress login page and processing unchanged, for full compatibility with authentication, security, and two-factor plugins.

### Dashboard Customizations

The theme modifies the WordPress admin dashboard to provide a more focused and relevant experience:

- **Removed Default Widgets**: Less relevant default dashboard widgets are removed (`remove_default_dashboard_widgets`).
- **Content Overview Widget**: A custom widget displays counts of published and draft posts, pages, and custom post types (`add_content_overview_widget`).
- **Recent Content Activity Widget**: Shows a list of recently modified posts and pages with author, time, and status details (`add_recent_activity_widget`).
- **Quick Actions Widget**: Provides quick links to create new posts, pages, upload media, and custom post types (`add_quick_actions_widget`).
- **Role-Based Customizations**:
  - Editors see the "Content Overview" widget with high priority.
  - Administrators get a "System Status" widget displaying database size, WordPress version, and PHP version (`customize_dashboard_by_role`, `system_status_widget_display`).
- **Custom Dashboard Styling**: Enqueues `style.css` for custom dashboard aesthetics (`enqueue_custom_dashboard_styles`).

### Frontend Redirection

The `redirect_frontend` function in `functions.php` ensures that any direct access to the WordPress frontend is redirected. If a user is logged in, they are redirected to the admin dashboard; otherwise, they are sent to the login page. This prevents accidental exposure of an unstyled or incomplete WordPress frontend.

## Compatibility

- Tested up to WordPress 7.0.
- Requires PHP 8.2 or newer and is prepared for PHP 8.5 runtime behavior.
- Avoids dynamic properties, placeholder-free `$wpdb->prepare()` calls, and unsafe direct superglobal reads that can trigger warnings on newer WordPress/PHP versions.

## Development

If you need to further customize this theme:

1.  **Modify `style.css`**: Add new rules to `style.css` to change the admin dashboard appearance.
2.  **Modify `functions.php`**: Extend or modify the existing functions in `functions.php` for custom WordPress behaviors, such as adding new REST API endpoints.

## Performance Considerations

- The theme does not write per-request analytics or error logs to the database.
- Dashboard queries are limited to lightweight content counts and recent content activity.
- Frontend requests are redirected early because public rendering is delegated to the headless frontend.

## Security

- The default WordPress login page and handling are used unchanged, for compatibility with security and authentication plugins.
- Admin-only canonical settings use WordPress nonces and capability checks.
- CORS allowed origins can be configured from the Headless Settings page.

## Contributing

Feel free to contribute to this project by submitting issues or pull requests.
