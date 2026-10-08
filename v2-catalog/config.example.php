<?php
// Скопировать в config.php прямо на сервере (через Plesk File Manager/SFTP)
// и заполнить реальным ключом — секреты через git не передаются, см.
// README.md в этой папке.

return [
    'crm_api_url' => 'https://crm.joma-retail.kz/api/catalog',
    'crm_api_key' => 'ЗАМЕНИТЬ_НА_РЕАЛЬНЫЙ_КЛЮЧ',
    // Как часто обновлять локальный кэш каталога (раз в 15-30 мин
    // достаточно — см. инструкцию CRM-стороны). Кэш обновляется "лениво":
    // при первом запросе после истечения TTL, без отдельного cron.
    'cache_ttl_seconds' => 1200,
    'cache_path' => __DIR__ . '/../storage/catalog-cache.json',
    // Раздел 12.1 ТЗ CRM — лендинг подписывает тело запроса HMAC-SHA256
    // этим секретом в заголовке X-Signature. Секрет только на сервере,
    // никогда не уходит в клиентский JS (см. api/lead.php).
    'crm_lead_url' => 'https://crm.joma-retail.kz/api/lead',
    'crm_lead_secret' => 'ЗАМЕНИТЬ_НА_РЕАЛЬНЫЙ_СЕКРЕТ',
];
