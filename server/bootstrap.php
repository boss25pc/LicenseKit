<?php

// Simple bootstrap for the license server.
// Uses SQLite for persistence and basic routing helpers.

$dbFile = __DIR__ . '/data.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure tables exist.
$pdo->exec('CREATE TABLE IF NOT EXISTS licenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    license_key TEXT UNIQUE NOT NULL,
    product_slug TEXT NOT NULL,
    max_activations INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT "active",
    expires_at TEXT NULL,
    notes TEXT NULL,
    customer_email TEXT NULL,
    source TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS license_activations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id INTEGER NOT NULL,
    site_url TEXT NOT NULL,
    wp_install_url TEXT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    activated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_check_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status TEXT NOT NULL DEFAULT "active",
    FOREIGN KEY(license_id) REFERENCES licenses(id)
)');

function json_response($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function normalize_url(?string $url): ?string {
    if (!$url) {
        return null;
    }
    $parsed = parse_url(trim($url));
    if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
        return null;
    }
    $scheme = strtolower($parsed['scheme']);
    $host = strtolower($parsed['host']);
    $path = $parsed['path'] ?? '/';
    $normalizedPath = rtrim($path, '/');
    if ($normalizedPath === '') {
        $normalizedPath = '/';
    }
    return $scheme . '://' . $host . $normalizedPath;
}

function find_license(PDO $pdo, string $licenseKey, string $slug): ?array {
    $stmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = :key AND product_slug = :slug LIMIT 1');
    $stmt->execute([':key' => $licenseKey, ':slug' => $slug]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$license) {
        return null;
    }
    $expired = $license['expires_at'] && strtotime($license['expires_at']) < time();
    $license['is_expired'] = $expired;
    return $license;
}

function active_activation_count(PDO $pdo, int $licenseId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM license_activations WHERE license_id = :id AND status = "active"');
    $stmt->execute([':id' => $licenseId]);
    return (int) $stmt->fetchColumn();
}

function respond_license_status(array $license, int $activationsUsed): array {
    return [
        'expires_at' => $license['expires_at'],
        'max_activations' => (int) $license['max_activations'],
        'activations_used' => $activationsUsed,
        'status' => $license['status'],
    ];
}

function require_license(?array $license): ?array {
    if (!$license) {
        return ['success' => false, 'license_status' => 'not_found', 'message' => 'License not found'];
    }
    if ($license['status'] === 'disabled') {
        return ['success' => false, 'license_status' => 'disabled', 'message' => 'License disabled'];
    }
    if ($license['is_expired']) {
        return ['success' => false, 'license_status' => 'expired', 'message' => 'License expired'];
    }
    return null;
}
