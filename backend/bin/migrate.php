#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * DevAgent — Migration Runner
 * Run: php bin/migrate.php
 */

require_once __DIR__ . '/../bootstrap.php';

$migrationsDir = __DIR__ . '/../../database/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

// Track applied migrations
db()->exec('
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `filename` VARCHAR(255) NOT NULL UNIQUE,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');

$applied = db()->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied)) {
        echo "  [skip] {$name}\n";
        continue;
    }

    $sql = file_get_contents($file);
    db()->exec($sql);
    db()->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$name]);
    echo "  [done] {$name}\n";
}

echo "\nAll migrations applied.\n";
