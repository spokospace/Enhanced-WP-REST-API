<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

use Spoko\EnhancedRestAPI\Services\ErrorLogger;

class HeadlessMode
{
    public function __construct(
        private ErrorLogger $logger
    ) {}

    public function register(): void
    {
        // Check if headless mode is enabled
        if (get_option('spoko_rest_headless_mode_enabled', '0') !== '1') {
            return;
        }

        add_action('parse_request', [$this, 'disableFrontEnd'], 99);
    }

    public function disableFrontEnd(): void
    {
        try {
            /**
             * Filters whether the current user has access to the front-end.
             *
             * By default, the front-end is disabled if the user doesn't
             * have the capability to "edit_posts".
             *
             * Return true if you want the front-end to be disabled.
             *
             * @param bool $disabled True if the current user doesn't have the capability to "edit_posts".
             */
            $disable_front_end = apply_filters(
                'spoko_headless_mode_disable_front_end',
                !current_user_can('edit_posts')
            );

            if (false === $disable_front_end) {
                return;
            }

            global $wp;

            /**
             * If the request is not part of a CRON, REST Request, GraphQL Request or Admin request,
             * redirect to the headless frontend
             */
            if (
                !defined('DOING_CRON') &&
                !defined('REST_REQUEST') &&
                !is_admin() &&
                (
                    empty($wp->query_vars['rest_oauth1']) &&
                    !defined('GRAPHQL_HTTP_REQUEST')
                )
            ) {
                $client_url = get_option('spoko_rest_headless_client_url', '');

                // If no client URL is configured, show a simple message
                if (empty($client_url)) {
                    $this->showHeadlessMessage();
                    exit;
                }

                // Build the redirect URL preserving the request path
                $new_url = trailingslashit($client_url) . $wp->request;

                /**
                 * Filters whether redirect will occur.
                 *
                 * This filter allows you to do something else when a redirect normally would occur.
                 *
                 * @param bool $will_redirect If truthy redirect will happen.
                 * @param string $new_url The URL that would be redirected to
                 */
                if (apply_filters('spoko_headless_mode_will_redirect', true, $new_url)) {
                    $this->redirect($new_url);
                }
            }
        } catch (\Exception $e) {
            $this->logger->logError('Headless Mode Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Perform 301 permanent redirect
     *
     * @param string $url The URL to redirect to
     */
    private function redirect(string $url): void
    {
        header('Location: ' . $url, true, 301);
        exit;
    }

    /**
     * Show a simple message when headless mode is enabled but no client URL is set
     */
    private function showHeadlessMessage(): void
    {
        $site_name = get_bloginfo('name');

        header('HTTP/1.1 200 OK');
        header('Content-Type: text/html; charset=utf-8');

        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($site_name) . ' - Headless Mode</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: #f0f0f1;
            color: #3c434a;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            max-width: 500px;
            text-align: center;
        }
        h1 {
            margin: 0 0 20px;
            font-size: 24px;
            font-weight: 600;
            color: #1d2327;
        }
        p {
            margin: 0 0 15px;
            line-height: 1.6;
        }
        .code {
            background: #f0f0f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
        }
        .info {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dcdcde;
            font-size: 14px;
            color: #646970;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>' . esc_html($site_name) . '</h1>
        <p>This site is running in <strong>Headless Mode</strong>.</p>
        <p>The WordPress backend is being used as a content API, and the frontend is served separately.</p>
        <div class="info">
            <p><strong>For administrators:</strong></p>
            <p>Configure the headless frontend URL in:<br>
            <span class="code">WordPress Admin → SPOKO REST API → Features Management</span></p>
        </div>
    </div>
</body>
</html>';
    }
}
