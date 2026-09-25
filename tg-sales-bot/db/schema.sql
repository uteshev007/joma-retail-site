-- JOMA Retail sales bots — общая база SQLite (см. раздел 3 ТЗ)

CREATE TABLE IF NOT EXISTS products (
    article TEXT PRIMARY KEY,
    name TEXT,
    category TEXT,              -- running/football/futsal/gym/barefoot/аксессуары
    brand TEXT DEFAULT 'JOMA',
    cost_price REAL,
    photo_path TEXT,
    -- Не входят в раздел 3 ТЗ: X2POS "Прайслист" отдаёт розничную цену/скидку,
    -- но не себестоимость — хранится отдельно от cost_price (тот из номенклатуры).
    retail_price REAL,
    discount_price REAL,
    price_updated_at TEXT,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS stock (
    article TEXT,
    size TEXT,
    qty_on_hand INTEGER,
    updated_at TEXT,
    PRIMARY KEY (article, size)
);

CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    article TEXT,
    size TEXT,
    qty INTEGER,
    price REAL,
    sale_date TEXT,
    channel TEXT,                -- 'retail' | 'kaspi'
    kaspi_order_id TEXT,
    kaspi_city TEXT,
    customer_id INTEGER,
    seller_id TEXT,
    payment_method TEXT,
    -- Не входят в раздел 3 ТЗ: X2POS "История продаж по товарам" отдаёт готовые
    -- себестоимость и маржу по строке — надёжнее взять как есть, чем пересчитывать
    -- из products.cost_price (который может измениться к моменту анализа).
    cost_price REAL,
    margin REAL,
    discount REAL,
    -- "price" — номинальная цена ДО скидки ("Цена продажа" из X2POS), для
    -- отчётов по выручке/ABC/XYZ нужна total_amount ("Сумма продажа" — то,
    -- что реально оплачено за строку, за вычетом скидки); price*qty даёт
    -- завышенную выручку на всех строках со скидкой (33% строк в реальных
    -- данных отличались от факта на 5-20%).
    total_amount REAL,
    x2pos_doc_id TEXT,           -- для /связки (товары одного чека) — номер из "Документ"
    source_file TEXT,
    imported_at TEXT
);

CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    x2pos_contragent_id TEXT UNIQUE,
    phone TEXT,
    name TEXT,
    first_purchase_at TEXT,
    last_purchase_at TEXT,
    avg_purchase_interval_days REAL,
    ltv REAL
);

CREATE TABLE IF NOT EXISTS demand_signals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_text TEXT,
    matched_article TEXT,
    matched_size TEXT,
    ai_confidence REAL,
    created_at TEXT
);

CREATE TABLE IF NOT EXISTS alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_type TEXT,              -- 'size_deficit' | 'margin_drop' | 'churn' | 'competitor_price'
    entity_key TEXT,
    first_triggered_at TEXT,
    last_notified_at TEXT,
    resolved_at TEXT
);

CREATE TABLE IF NOT EXISTS competitor_prices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    our_article TEXT,
    competitor TEXT,               -- 'kaspi' | 'chempion' | 'joma_kz'
    competitor_url TEXT,
    competitor_price REAL,
    checked_at TEXT
);

-- Не входит в схему раздела 3 ТЗ. X2POS "Экспорт продаж" отдаёт данные на
-- уровне ЧЕКА (сумма, скидка, контрагент), без состава товаров — то есть
-- table `sales` из раздела 3 (article/size/qty за строку) из этого файла
-- не заполняется, для неё нужна отдельная построчная выгрузка X2POS (если
-- она существует) либо состав заказа из Kaspi API. Здесь храним то, что
-- реально есть в чеке — этого достаточно для оборота/скидок/LTV клиента.
CREATE TABLE IF NOT EXISTS sales_documents (
    id INTEGER PRIMARY KEY,          -- "# Документа" из X2POS
    action TEXT,                     -- 'Продажа' | 'Возврат товара'
    branch TEXT,
    employee TEXT,
    doc_date TEXT,
    contragent_raw TEXT,
    contragent_phone TEXT,           -- последние 10 цифр номера, извлечённые из contragent_raw
    subtotal REAL,
    total_due REAL,
    total_paid REAL,
    discount_type TEXT,
    discount_value REAL,
    status TEXT,
    price_type TEXT,
    external_order_id TEXT,
    source_file TEXT,
    imported_at TEXT
);

-- Не входит в схему раздела 3 ТЗ — добавлена для приёма X2POS-выгрузки
-- "Платежи" как есть (раздел 2.2: "не первый приоритет", но данные сохранить).
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY,       -- "# платежа" из X2POS — стабильный natural key
    payment_date TEXT,
    branch TEXT,
    payment_method TEXT,
    account TEXT,
    counterparty TEXT,
    income REAL,
    expense REAL,
    balance_after REAL,
    purpose TEXT,
    employee TEXT,
    source_file TEXT,
    imported_at TEXT
);

CREATE TABLE IF NOT EXISTS sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT,                   -- 'kaspi' | 'x2pos_file' | 'competitor_prices'
    status TEXT,
    details TEXT,
    synced_at TEXT
);
