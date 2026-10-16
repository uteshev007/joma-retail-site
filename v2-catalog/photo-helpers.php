<?php
declare(strict_types=1);

/**
 * Поставщик (joma-sport.net) пускает фото не со всех сетей — с части
 * хостингов/стран соединение обрывается по таймауту (см. тот же фикс в
 * CRM, app/helpers.php: photoProxyUrl()). Поэтому сайт тоже не отдаёт
 * браузеру прямую ссылку на joma-sport.net, а проксирует через себя:
 * api/photo.php сам скачивает картинку у поставщика и
 * отдаёт её со своего домена — независимо от того, откуда смотрит
 * посетитель.
 */
function photoProxyUrl(?string $url): ?string
{
    if (!$url) {
        return null;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['www.joma-sport.net', 'joma-sport.net'], true)) {
        return $url;
    }
    return 'api/photo.php?u=' . rawurlencode($url);
}
