<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $isNewDb = !file_exists(DB_PATH);
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');

        // На ps.kz не подтверждён доступ к shell/cron (раздел 10 ТЗ), поэтому
        // не полагаемся на ручной запуск db/init.php — применяем схему сами
        // при первом обращении. CREATE TABLE IF NOT EXISTS делает это
        // безопасным и при повторном вызове на уже готовой базе.
        if ($isNewDb || !$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='products'")->fetchColumn()) {
            $pdo->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));
        }
    }
    return $pdo;
}
