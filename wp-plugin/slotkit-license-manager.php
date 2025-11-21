<?php
/**
 * SlotKit Pro License Manager
 */

if (!class_exists('SlotKit_License_Manager')) {
    class SlotKit_License_Manager {
        const OPTION_KEY = 'slotkit_pro_license';
        const TRANSIENT_KEY = 'slotkit_pro_license_cache';
        const API_BASE = 'https://your-server.com';
        const CACHE_TTL = HOUR_IN_SECONDS; // cache remote responses
        const GRACE_DAYS = 7;

        public static function is_pro_active(): bool {
            $transient = get_transient(self::TRANSIENT_KEY);
            if (is_array($transient) && isset($transient['status'])) {
                return $transient['status'] === 'valid';
            }

            $response = self::check_license_remote();
            if ($response !== null) {
                return $response;
            }

            $option = get_option(self::OPTION_KEY, []);
            $lastCheck = isset($option['last_check']) ? (int) $option['last_check'] : 0;
            $grace = (time() - $lastCheck) <= DAY_IN_SECONDS * self::GRACE_DAYS;
            return ($option['status'] ?? '') === 'valid' && $grace;
        }

        public static function set_license_key(string $key): void {
            $data = [
                'license_key' => trim($key),
                'status' => 'unknown',
                'last_check' => 0,
                'last_error' => '',
                'last_status_code' => 0,
            ];
            update_option(self::OPTION_KEY, $data);
            delete_transient(self::TRANSIENT_KEY);
        }

        public static function get_license_key(): string {
            $option = get_option(self::OPTION_KEY, []);
            return $option['license_key'] ?? '';
        }

        public static function activate_license(): array {
            $key = self::get_license_key();
            $body = self::base_payload();
            $body['license_key'] = $key;
            $response = wp_remote_post(self::API_BASE . '/license/activate', [
                'timeout' => 8,
                'body' => $body,
            ]);
            return self::handle_response($response, 'activate');
        }

        public static function deactivate_license(): array {
            $key = self::get_license_key();
            $body = self::base_payload();
            $body['license_key'] = $key;
            $response = wp_remote_post(self::API_BASE . '/license/deactivate', [
                'timeout' => 8,
                'body' => $body,
            ]);
            delete_transient(self::TRANSIENT_KEY);
            update_option(self::OPTION_KEY, [
                'license_key' => $key,
                'status' => 'deactivated',
                'last_check' => time(),
                'last_error' => '',
                'last_status_code' => 200,
            ]);
            return self::handle_response($response, 'deactivate');
        }

        public static function check_license_remote(): ?bool {
            $key = self::get_license_key();
            if (!$key) {
                return false;
            }
            $query = http_build_query(array_merge(self::base_payload(), [
                'license_key' => $key,
            ]));
            $url = self::API_BASE . '/license/check?' . $query;
            $response = wp_remote_get($url, ['timeout' => 8]);
            return self::handle_response($response, 'check')['active'] ?? null;
        }

        private static function handle_response($response, string $context): array {
            if (is_wp_error($response)) {
                self::cache_failure($context . ': ' . $response->get_error_message());
                return ['success' => false, 'error' => $response->get_error_message(), 'active' => null];
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true) ?? [];
            $status = $body['license_status'] ?? 'invalid';
            $isValid = $status === 'valid';

            $option = get_option(self::OPTION_KEY, []);
            $option['status'] = $status;
            $option['last_check'] = time();
            $option['last_error'] = $body['message'] ?? '';
            $option['last_status_code'] = $code;
            update_option(self::OPTION_KEY, $option);

            set_transient(self::TRANSIENT_KEY, [
                'status' => $status,
                'checked_at' => time(),
            ], self::CACHE_TTL);

            return [
                'success' => (bool) ($body['success'] ?? false),
                'status_code' => $code,
                'active' => $isValid,
                'body' => $body,
            ];
        }

        private static function cache_failure(string $message): void {
            $option = get_option(self::OPTION_KEY, []);
            $option['last_error'] = $message;
            update_option(self::OPTION_KEY, $option);
        }

        private static function base_payload(): array {
            return [
                'plugin_slug' => 'slotkit-pro',
                'plugin_version' => defined('SLOTKIT_PRO_VERSION') ? SLOTKIT_PRO_VERSION : '0.0.0',
                'site_url' => home_url(),
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
            ];
        }

        public static function check_for_updates($transient) {
            if (!self::is_pro_active()) {
                return $transient;
            }
            if (!is_object($transient)) {
                $transient = new stdClass();
            }
            $key = self::get_license_key();
            $payload = http_build_query([
                'license_key' => $key,
                'plugin_slug' => 'slotkit-pro',
                'site_url' => home_url(),
                'current_version' => defined('SLOTKIT_PRO_VERSION') ? SLOTKIT_PRO_VERSION : '0.0.0',
            ]);
            $url = self::API_BASE . '/update/check?' . $payload;
            $response = wp_remote_get($url, ['timeout' => 8]);
            if (is_wp_error($response)) {
                return $transient;
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || empty($body['success']) || empty($body['update_available'])) {
                return $transient;
            }
            $update = (object) [
                'slug' => 'slotkit-pro',
                'plugin' => 'slotkit/slotkit.php',
                'new_version' => $body['new_version'] ?? '',
                'url' => 'https://your-landing-page.com',
                'package' => $body['package_url'] ?? '',
            ];
            $transient->response['slotkit/slotkit.php'] = $update;
            return $transient;
        }

        public static function plugins_api($result, $action, $args) {
            if ($action !== 'plugin_information' || $args->slug !== 'slotkit-pro') {
                return $result;
            }
            $info = new stdClass();
            $info->name = 'SlotKit Pro';
            $info->slug = 'slotkit-pro';
            $info->version = defined('SLOTKIT_PRO_VERSION') ? SLOTKIT_PRO_VERSION : '0.0.0';
            $info->author = 'SlotKit';
            $info->sections = [
                'description' => 'SlotKit Pro features.',
                'changelog' => 'See server-provided changelog.',
            ];
            return $info;
        }

        public static function hooks(): void {
            add_filter('pre_set_site_transient_update_plugins', [self::class, 'check_for_updates']);
            add_filter('plugins_api', [self::class, 'plugins_api'], 10, 3);
        }
    }

    add_action('plugins_loaded', ['SlotKit_License_Manager', 'hooks']);
}
