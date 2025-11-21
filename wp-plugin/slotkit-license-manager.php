<?php
/**
 * SlotKit Pro License Manager
 */

if (!defined('SLOTKIT_LICENSE_API_BASE')) {
    define('SLOTKIT_LICENSE_API_BASE', 'https://your-license-server.example.com');
}

class SlotKit_License_Manager
{
    const OPTION_KEY = 'slotkit_pro_license';
    const TRANSIENT_KEY = 'slotkit_pro_license_cache';
    const TRANSIENT_TTL = 12 * HOUR_IN_SECONDS;
    const GRACE_DAYS = 7;
    const PLUGIN_SLUG = 'slotkit-pro';
    const PLUGIN_FILE = 'slotkit/slotkit.php';

    public static function init(): void
    {
        add_action('plugins_loaded', [self::class, 'register_hooks']);
    }

    public static function register_hooks(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check_for_updates']);
        add_filter('plugins_api', [self::class, 'plugins_api'], 10, 3);
    }

    public static function set_license_key(string $key): void
    {
        $data = [
            'license_key' => trim($key),
            'status' => 'invalid',
            'last_check' => 0,
            'last_error' => '',
            'last_status_code' => 0,
        ];

        update_option(self::OPTION_KEY, $data);
        delete_transient(self::TRANSIENT_KEY);
    }

    public static function get_license_key(): string
    {
        $option = get_option(self::OPTION_KEY, []);
        return $option['license_key'] ?? '';
    }

    public static function activate_license(): array
    {
        $key = self::get_license_key();
        $body = array_merge(self::base_payload(), [
            'license_key' => $key,
        ]);

        $response = wp_remote_post(self::endpoint('/license/activate'), [
            'timeout' => 10,
            'body' => $body,
        ]);

        return self::handle_remote_response($response);
    }

    public static function deactivate_license(): array
    {
        $key = self::get_license_key();
        $body = array_merge(self::base_payload(), [
            'license_key' => $key,
        ]);

        $response = wp_remote_post(self::endpoint('/license/deactivate'), [
            'timeout' => 10,
            'body' => $body,
        ]);

        $parsed = self::handle_remote_response($response);
        delete_transient(self::TRANSIENT_KEY);

        return $parsed;
    }

    public static function check_license_remote(): array
    {
        $key = self::get_license_key();
        if (!$key) {
            return [
                'success' => false,
                'license_status' => 'invalid',
                'message' => 'Missing license key',
            ];
        }

        $query = array_merge(self::base_payload(), [
            'license_key' => $key,
        ]);

        $url = self::endpoint('/license/check') . '?' . http_build_query($query);
        $response = wp_remote_get($url, ['timeout' => 10]);

        return self::handle_remote_response($response);
    }

    public static function is_pro_active(): bool
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached) && isset($cached['status'])) {
            return $cached['status'] === 'valid';
        }

        $remote = self::check_license_remote();
        if (!empty($remote['success']) && ($remote['license_status'] ?? '') === 'valid') {
            return true;
        }

        if (!empty($remote['license_status']) && $remote['license_status'] !== 'valid') {
            return false;
        }

        $option = get_option(self::OPTION_KEY, []);
        $lastCheck = isset($option['last_check']) ? (int) $option['last_check'] : 0;
        $grace = (time() - $lastCheck) <= DAY_IN_SECONDS * self::GRACE_DAYS;

        return ($option['status'] ?? '') === 'valid' && $grace;
    }

    public static function check_for_updates($transient)
    {
        if (!self::is_pro_active()) {
            return $transient;
        }

        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        $key = self::get_license_key();
        $payload = [
            'license_key' => $key,
            'plugin_slug' => self::PLUGIN_SLUG,
            'site_url' => home_url(),
            'current_version' => self::plugin_version(),
        ];

        $url = self::endpoint('/update/check') . '?' . http_build_query($payload);
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return $transient;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success'])) {
            return $transient;
        }

        if (!empty($body['update_available'])) {
            $update = (object) [
                'slug' => self::PLUGIN_SLUG,
                'plugin' => self::PLUGIN_FILE,
                'new_version' => $body['new_version'] ?? '',
                'url' => 'https://vaultcomps.co.uk/slotkit-pro',
                'package' => $body['package_url'] ?? '',
            ];

            $transient->response[self::PLUGIN_FILE] = $update;
        }

        return $transient;
    }

    public static function plugins_api($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        return (object) [
            'name' => 'SlotKit Pro',
            'slug' => self::PLUGIN_SLUG,
            'version' => self::plugin_version(),
            'author' => 'VaultComps',
            'homepage' => 'https://vaultcomps.co.uk/slotkit-pro',
            'sections' => [
                'description' => 'Short description of SlotKit Pro.',
                'changelog' => 'See your site for full changelog.',
            ],
        ];
    }

    private static function handle_remote_response($response): array
    {
        if (is_wp_error($response)) {
            self::record_status('invalid', 0, $response->get_error_message());
            return [
                'success' => false,
                'license_status' => 'invalid',
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true) ?: [];
        $licenseStatus = $body['license_status'] ?? 'invalid';
        $success = (bool) ($body['success'] ?? false);
        $message = $body['message'] ?? '';

        self::record_status($licenseStatus, $code, $message);

        $statusToCache = $licenseStatus ?: 'invalid';
        set_transient(self::TRANSIENT_KEY, [
            'status' => $statusToCache,
            'checked_at' => time(),
        ], self::TRANSIENT_TTL);

        return [
            'success' => $success,
            'license_status' => $licenseStatus,
            'message' => $message,
            'data' => $body['data'] ?? [],
        ];
    }

    private static function record_status(string $status, int $code, string $message): void
    {
        $option = get_option(self::OPTION_KEY, []);
        $option['license_key'] = self::get_license_key();
        $option['status'] = $status;
        $option['last_check'] = time();
        $option['last_error'] = $message;
        $option['last_status_code'] = $code;

        update_option(self::OPTION_KEY, $option);
    }

    private static function endpoint(string $path): string
    {
        return rtrim(SLOTKIT_LICENSE_API_BASE, '/') . $path;
    }

    private static function base_payload(): array
    {
        return [
            'plugin_slug' => self::PLUGIN_SLUG,
            'plugin_version' => self::plugin_version(),
            'site_url' => home_url(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        ];
    }

    private static function plugin_version(): string
    {
        if (defined('SLOTKIT_PRO_VERSION')) {
            return (string) SLOTKIT_PRO_VERSION;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $pluginData = get_plugin_data(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE, false, false);

        return $pluginData['Version'] ?? '0.0.0';
    }
}

SlotKit_License_Manager::init();
