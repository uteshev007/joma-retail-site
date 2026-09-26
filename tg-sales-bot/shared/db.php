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

        migrate_add_columns($pdo);
    }
    return $pdo;
}

// SQLite ADD COLUMN — безопасно для уже существующих баз (локальной и на
// ps.kz), которые не пройдут через schema.sql заново (тот применяется только
// на пустой базе, см. выше). Список специально маленький и плоский — если
// разрастётся, тогда заводить нормальные миграции с версионированием.
function migrate_add_columns(PDO $pdo): void {
    $columnsToAdd = [
        'products' => [
            'x2pos_product_id' => 'TEXT',
            'x2pos_category_id' => 'TEXT',
        ],
        'stock' => [
            'x2pos_variation_id' => 'TEXT',
        ],
    ];
    foreach ($columnsToAdd as $table => $columns) {
        $existing = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ($columns as $column => $type) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
            }
        }
    }
}
