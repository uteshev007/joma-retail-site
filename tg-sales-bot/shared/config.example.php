<?php
// Скопируйте этот файл в config.php (в той же папке shared/) и заполните реальными значениями.
// config.php в .gitignore — не коммитить, не класть в public_html.

// --- Telegram ---
define('TG_OWNER_BOT_TOKEN', 'ЗАМЕНИТЬ_ТОКЕН_БОТА_АНАЛИТИКА');
define('TG_SELLERS_BOT_TOKEN', 'ЗАМЕНИТЬ_ТОКЕН_БОТА_ПРИЁМЩИКА');

// chat_id владельца(ев) — только эти chat_id получают доступ к боту-аналитику
define('OWNER_CHAT_IDS', [
    // 123456789,
]);

// --- Kaspi Магазин API ---
define('KASPI_API_TOKEN', 'ЗАМЕНИТЬ_KASPI_TOKEN');
define('KASPI_API_BASE', 'https://kaspi.kz/shop/api/v2');

// --- Claude API (распознавание текста продавцов) ---
define('CLAUDE_API_KEY', 'ЗАМЕНИТЬ_CLAUDE_API_KEY');
define('CLAUDE_MODEL', 'claude-haiku-4-5-20251001');

// --- База данных ---
define('DB_PATH', __DIR__ . '/../db/joma_sales.sqlite');

// --- Фото товаров (извлекаются из прайслиста, если в нём есть картинки) ---
define('PHOTOS_DIR', __DIR__ . '/../photos');
