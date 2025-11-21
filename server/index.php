<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$releases = require __DIR__ . '/releases.php';

$body = file_get_contents('php://input');
$input = $method === 'POST' ? json_decode($body, true) : null;
if (!is_array($input)) {
    $input = $method === 'POST' ? $_POST : $_GET;
}

switch ([$method, $path]) {
    case ['POST', '/license/activate']:
        handle_activate($pdo, $input);
        break;
    case ['POST', '/license/deactivate']:
        handle_deactivate($pdo, $input);
        break;
    case ['GET', '/license/check']:
        handle_license_check($pdo, $input);
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

function handle_activate(PDO $pdo, array $data): void
{
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');

    if (!$licenseKey || !$slug || !$siteUrl) {
        json_response([
            'success' => false,
            'license_status' => 'invalid',
            'message' => 'Missing required fields',
        ], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($error = require_license($license)) {
        json_response($error, 403);
    }

    $stmt = $pdo->prepare('SELECT * FROM license_activations WHERE license_id = ? AND site_url = ? LIMIT 1');
    $stmt->execute([$license['id'], $siteUrl]);
    $activation = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activation) {
        if ($activation['status'] === 'deactivated') {
            $update = $pdo->prepare("UPDATE license_activations SET status = 'active', last_check_at = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$activation['id']]);
        } else {
            $touch = $pdo->prepare('UPDATE license_activations SET last_check_at = CURRENT_TIMESTAMP WHERE id = ?');
            $touch->execute([$activation['id']]);
        }
    } else {
        $activationsUsed = active_activation_count($pdo, (int) $license['id']);
        if ($activationsUsed >= (int) $license['max_activations']) {
            json_response([
                'success' => false,
                'license_status' => 'invalid',
                'message' => 'Activation limit reached',
            ], 403);
        }

        $insert = $pdo->prepare('INSERT INTO license_activations (license_id, site_url, wp_install_url, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([
            $license['id'],
            $siteUrl,
            normalize_url($data['wp_install_url'] ?? null),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
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

function handle_deactivate(PDO $pdo, array $data): void
{
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');

    if (!$licenseKey || !$slug || !$siteUrl) {
        json_response([
            'success' => false,
            'license_status' => 'invalid',
            'message' => 'Missing required fields',
        ], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($license) {
        $stmt = $pdo->prepare("UPDATE license_activations SET status = 'deactivated', last_check_at = CURRENT_TIMESTAMP WHERE license_id = ? AND site_url = ?");
        $stmt->execute([$license['id'], $siteUrl]);
        $status = 'valid';
    } else {
        $status = 'not_found';
    }

    json_response([
        'success' => true,
        'license_status' => $status,
        'message' => 'License deactivated',
    ]);
}

function handle_license_check(PDO $pdo, array $data): void
{
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrlRaw = $data['site_url'] ?? null;
    $siteUrl = $siteUrlRaw !== null ? normalize_url($siteUrlRaw) : null;

    if (!$licenseKey || !$slug) {
        json_response([
            'success' => false,
            'license_status' => 'invalid',
            'message' => 'Missing required fields',
        ], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($error = require_license($license)) {
        json_response($error, 403);
    }

    if ($siteUrl) {
        $touch = $pdo->prepare('UPDATE license_activations SET last_check_at = CURRENT_TIMESTAMP WHERE license_id = ? AND site_url = ?');
        $touch->execute([$license['id'], $siteUrl]);
    }

    $activationsUsed = active_activation_count($pdo, (int) $license['id']);
    json_response([
        'success' => true,
        'license_status' => 'valid',
        'message' => 'License valid',
        'data' => respond_license_status($license, $activationsUsed),
    ]);
}

function handle_update_check(PDO $pdo, array $releases, array $data): void
{
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');
    $currentVersion = trim($data['current_version'] ?? '');

    if (!$licenseKey || !$slug || !$siteUrl || !$currentVersion) {
        json_response([
            'success' => false,
            'license_status' => 'invalid',
            'message' => 'Missing required fields',
        ], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($error = require_license($license)) {
        json_response($error, 403);
    }

    $release = $releases[$slug] ?? null;
    if (!$release) {
        json_response([
            'success' => false,
            'license_status' => 'valid',
            'update_available' => false,
        ]);
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

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $packageUrl = $scheme . '://' . $host . '/update/download?' . http_build_query([
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

function handle_update_download(PDO $pdo, array $releases, array $data): void
{
    $licenseKey = trim($data['license_key'] ?? '');
    $slug = trim($data['plugin_slug'] ?? '');
    $siteUrl = normalize_url($data['site_url'] ?? '');
    $version = trim($data['version'] ?? '');

    if (!$licenseKey || !$slug || !$siteUrl || !$version) {
        json_response([
            'success' => false,
            'license_status' => 'invalid',
            'message' => 'Missing required fields',
        ], 400);
    }

    $license = find_license($pdo, $licenseKey, $slug);
    if ($error = require_license($license)) {
        json_response($error, 403);
    }

    $release = $releases[$slug] ?? null;
    if (!$release) {
        json_response([
            'success' => false,
            'license_status' => 'valid',
            'message' => 'Package not found',
        ], 404);
    }

    $packagePath = $release['package_path'] ?? null;
    if (!$packagePath || !file_exists($packagePath)) {
        json_response([
            'success' => false,
            'license_status' => 'valid',
            'message' => 'Package not found',
        ], 404);
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $slug . '-' . $version . '.zip"');
    header('Content-Length: ' . filesize($packagePath));
    readfile($packagePath);
    exit;
}
