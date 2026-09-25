<?php
// Создаёт SQLite-файл базы и применяет схему. Запускать один раз вручную:
//   php db/init.php

require_once __DIR__ . '/../shared/config.php';

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode = WAL;');
$pdo->exec('PRAGMA foreign_keys = ON;');

$schema = file_get_contents(__DIR__ . '/schema.sql');
$pdo->exec($schema);

echo "OK: схема применена к " . DB_PATH . "\n";
