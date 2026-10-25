<?php
declare(strict_types=1);

/**
 * Статика (app.js/cart.js/style.css) отдаётся с Cache-Control:
 * max-age=315360000 (10 лет, похоже на дефолт Plesk/nginx для .js/.css) —
 * без версии в URL у вернувшегося посетителя браузер молча продолжает
 * использовать старый файл после каждого деплоя, сколько бы раз мы ни
 * меняли app.js/style.css (поймано на живую: window.trackEvent был не
 * определён в уже загруженной странице, хотя в самом файле на сервере
 * функция уже была — конверсии Google Ads из-за этого не отправлялись).
 * filemtime() в query-строке меняет сам URL при каждом изменении файла,
 * так что это не одноразовый обход кэша, а автоматический cache-busting
 * на каждый будущий деплой.
 */
function asset(string $path): string
{
    $full = __DIR__ . '/' . ltrim($path, '/');
    $v = @filemtime($full);
    return $path . ($v !== false ? '?v=' . $v : '');
}
