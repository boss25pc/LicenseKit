<?php

$dbFile = __DIR__ . '/data.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE IF NOT EXISTS licenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    license_key TEXT UNIQUE NOT NULL,
    product_slug TEXT NOT NULL,
    max_activations INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT "active",
    expires_at TEXT NULL,
    customer_email TEXT NULL,
    source TEXT NULL,
    notes TEXT NULL,
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
    last_check_at TEXT NULL,
    status TEXT NOT NULL DEFAULT "active",
    UNIQUE(license_id, site_url),
    FOREIGN KEY(license_id) REFERENCES licenses(id)
)');
