<?php
require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$releases = require __DIR__ . '/releases.php';

$input = $method === 'POST' ? json_decode(file_get_contents('php://input'), true) ?? $_POST : $_GET;

switch ([$method, $path]) {
    case ['POST', '/license/activate']:
        handle_activate($pdo, $input);
        break;
    case ['POST', '/license/deactivate']:
        handle_deactivate($pdo, $input);
        break;
    case ['GET', '/license/check']:
        handle_check($pdo, $input);
        break;
    case ['GET', '/update/check']:
        handle_update_check($pdo, $releases, $input);
        break;
    case ['GET', '/update/download']:
        handle_update_download($pdo, $releases, $input);
        break;
    default:
        json_response(['success' => false, 'message' => 'Not found'], 404);
}

function handle_activate(PDO $pdo, array $data): void {
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');

    if (!$licenseKey || !$slug || !$siteUrl) {
        json_response(['success' => false, 'license_status' => 'invalid', 'message' => 'Missing required fields'], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($error = require_license($license)) {
        json_response($error, 403);
    }

    $activations = active_activation_count($pdo, (int) $license['id']);
    if ($activations >= (int) $license['max_activations']) {
        json_response([
            'success' => false,
            'license_status' => 'max_activations_reached',
            'message' => 'Activation limit reached',
        ], 403);
    }

    $stmt = $pdo->prepare('SELECT * FROM license_activations WHERE license_id = :id AND site_url = :site LIMIT 1');
    $stmt->execute([':id' => $license['id'], ':site' => $siteUrl]);
    $activation = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activation) {
        $stmt = $pdo->prepare('UPDATE license_activations SET status = "active", last_check_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => $activation['id']]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO license_activations (license_id, site_url, wp_install_url, ip_address, user_agent) VALUES (:license_id, :site_url, :wp_install_url, :ip, :ua)');
        $stmt->execute([
            ':license_id' => $license['id'],
            ':site_url' => $siteUrl,
            ':wp_install_url' => normalize_url($data['wp_install_url'] ?? null),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    $activationsUsed = active_activation_count($pdo, (int) $license['id']);
    json_response([
        'success' => true,
        'license_status' => 'valid',
        'message' => 'License activated',
        'data' => respond_license_status($license, $activationsUsed),
    ]);
}

function handle_deactivate(PDO $pdo, array $data): void {
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');

    if (!$licenseKey || !$slug || !$siteUrl) {
        json_response(['success' => false, 'license_status' => 'invalid', 'message' => 'Missing required fields'], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if (!$license) {
        json_response(['success' => true, 'license_status' => 'not_found', 'message' => 'No activation found']);
    }

    $stmt = $pdo->prepare('UPDATE license_activations SET status = "deactivated", last_check_at = CURRENT_TIMESTAMP WHERE license_id = :id AND site_url = :site');
    $stmt->execute([':id' => $license['id'], ':site' => $siteUrl]);

    json_response([
        'success' => true,
        'license_status' => 'valid',
        'message' => 'License deactivated',
    ]);
}

function handle_check(PDO $pdo, array $data): void {
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');

    if (!$licenseKey || !$slug) {
        json_response(['success' => false, 'license_status' => 'invalid', 'message' => 'Missing required fields'], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($error = require_license($license)) {
        json_response($error, 403);
    }

    $stmt = $pdo->prepare('UPDATE license_activations SET last_check_at = CURRENT_TIMESTAMP WHERE license_id = :id AND site_url = :site');
    $stmt->execute([':id' => $license['id'], ':site' => $siteUrl]);

    $activationsUsed = active_activation_count($pdo, (int) $license['id']);
    json_response([
        'success' => true,
        'license_status' => 'valid',
        'data' => respond_license_status($license, $activationsUsed),
    ]);
}

function handle_update_check(PDO $pdo, array $releases, array $data): void {
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');
    $currentVersion = trim($data['current_version'] ?? '');

    if (!$licenseKey || !$slug || !$currentVersion) {
        json_response(['success' => false, 'license_status' => 'invalid', 'message' => 'Missing required fields'], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($error = require_license($license)) {
        json_response($error, 403);
    }

    $release = $releases[$slug] ?? null;
    if (!$release) {
        json_response(['success' => false, 'license_status' => 'valid', 'update_available' => false]);
    }

    $latest = $release['latest_version'];
    $updateAvailable = version_compare($currentVersion, $latest, '<');

    if (!$updateAvailable) {
        json_response([
            'success' => true,
            'license_status' => 'valid',
            'update_available' => false,
        ]);
    }

    $packageUrl = $release['download_base'] . '?' . http_build_query([
        'license_key' => $licenseKey,
        'site_url' => $siteUrl,
        'plugin_slug' => $slug,
        'version' => $latest,
    ]);

    json_response([
        'success' => true,
        'license_status' => 'valid',
        'update_available' => true,
        'new_version' => $latest,
        'changelog' => $release['changelog'],
        'package_url' => $packageUrl,
    ]);
}

function handle_update_download(PDO $pdo, array $releases, array $data): void {
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');
    $version = trim($data['version'] ?? '');

    if (!$licenseKey || !$slug || !$version) {
        json_response(['success' => false, 'license_status' => 'invalid', 'message' => 'Missing required fields'], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($error = require_license($license)) {
        json_response($error, 403);
    }

    $release = $releases[$slug] ?? null;
    if (!$release || $release['latest_version'] !== $version) {
        json_response(['success' => false, 'license_status' => 'invalid', 'message' => 'Unknown release'], 404);
    }

    // For phase 1, just echo JSON describing the download. Production would stream a zip file.
    json_response([
        'success' => true,
        'license_status' => 'valid',
        'message' => 'Download authorized',
        'data' => [
            'plugin_slug' => $slug,
            'version' => $version,
            'note' => 'Replace with file streaming in production.',
            'download_path' => $release['download_base'],
        ],
    ]);
}
