<?php

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function normalize_url(?string $url): ?string
{
    if ($url === null || $url === '') {
        return null;
    }

    $trimmed = trim($url);
    if (!preg_match('#^https?://#i', $trimmed)) {
        $trimmed = 'https://' . $trimmed;
    }

    $parsed = parse_url($trimmed);
    if (!$parsed || empty($parsed['host'])) {
        return null;
    }

    $scheme = strtolower($parsed['scheme'] ?? 'https');
    $host = strtolower($parsed['host']);
    $path = $parsed['path'] ?? '';
    $normalizedPath = rtrim($path, '/');
    $normalizedPath = $normalizedPath === '' ? '' : $normalizedPath;

    return $scheme . '://' . $host . $normalizedPath;
}

function find_license(PDO $pdo, string $licenseKey, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM licenses WHERE license_key = ? AND product_slug = ? LIMIT 1');
    $stmt->execute([$licenseKey, $slug]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    return $license ?: null;
}

function active_activation_count(PDO $pdo, int $licenseId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM license_activations WHERE license_id = ? AND status = 'active'");
    $stmt->execute([$licenseId]);
    return (int) $stmt->fetchColumn();
}

function respond_license_status(array $license, int $activationsUsed): array
{
    return [
        'expires_at' => $license['expires_at'],
        'max_activations' => (int) $license['max_activations'],
        'activations_used' => $activationsUsed,
        'status' => $license['status'],
    ];
}

function require_license(?array $license): ?array
{
    if (!$license) {
        return [
            'success' => false,
            'license_status' => 'not_found',
            'message' => 'License not found',
        ];
    }

    if ($license['status'] === 'disabled') {
        return [
            'success' => false,
            'license_status' => 'disabled',
            'message' => 'License disabled',
        ];
    }

    if (!empty($license['expires_at']) && strtotime($license['expires_at']) < time()) {
        return [
            'success' => false,
            'license_status' => 'expired',
            'message' => 'License expired',
        ];
    }

    return null;
}
