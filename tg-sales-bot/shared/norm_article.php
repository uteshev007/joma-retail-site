<?php
// Нормализация артикула X2POS: выгрузки хранят числовые артикулы как float
// ("12345.0", "12345.00"), поэтому один и тот же товар в разных отчётах может
// прийти с разным числом нулей после точки. Приводим к каноническому виду,
// чтобы JOIN/сравнение по article было надёжным.
//
// TODO: сверить с реальным форматом артикулов из присланного файла номенклатуры
// и поправить при необходимости (например, если встречаются буквенные префиксы
// или ведущие нули, которые нельзя терять при приведении к float).
function norm_article($article): string {
    $article = trim((string) $article);

    if ($article === '') {
        return '';
    }

    if (is_numeric($article)) {
        $float = (float) $article;
        if ($float == (int) $float) {
            return (string) (int) $float;
        }
        return rtrim(rtrim(sprintf('%.6f', $float), '0'), '.');
    }

    return $article;
}
