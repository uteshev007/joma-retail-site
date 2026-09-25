<?php
// Минимальный XLSX-ридер без внешних зависимостей (Composer-доступность на
// ps.kz не подтверждена — см. раздел 10 ТЗ). Читает первый лист .xlsx как
// массив строк (каждая строка — массив значений по 0-based индексу колонки).

function xlsx_read_rows(string $path, int $sheetIndex = 0): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Не удалось открыть xlsx как zip: $path");
    }

    $sharedStrings = xlsx_read_shared_strings($zip);

    $sheetFile = "xl/worksheets/sheet" . ($sheetIndex + 1) . ".xml";
    $sheetXml = $zip->getFromName($sheetFile);
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException("Лист не найден: $sheetFile");
    }
    $zip->close();

    $xml = simplexml_load_string($sheetXml);
    $rows = [];

    // Полностью пустые строки Excel иногда не пишет в XML вовсе (нет <row>
    // элемента) — нельзя просто добавлять строки по порядку (foreach ...
    // $rows[] = $row), это сдвигает всё последующее на число пропущенных
    // строк. Используем r="N" самой строки как настоящий 0-based индекс —
    // это критично для сопоставления с якорями картинок в drawing.xml, которые
    // ссылаются на абсолютный номер строки листа.
    foreach ($xml->sheetData->row as $rowXml) {
        $rowIndex = ((int) $rowXml['r']) - 1;
        $row = [];
        foreach ($rowXml->c as $cellXml) {
            $ref = (string) $cellXml['r'];
            $colIndex = xlsx_col_letter_to_index(preg_replace('/\d+/', '', $ref));
            $type = (string) $cellXml['t'];
            $value = null;

            if ($type === 's') {
                $idx = (int) $cellXml->v;
                $value = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'str' || $type === 'inlineStr') {
                $value = (string) $cellXml->v;
            } elseif ($type === 'b') {
                $value = ((string) $cellXml->v) === '1';
            } elseif (isset($cellXml->v)) {
                $raw = (string) $cellXml->v;
                $value = is_numeric($raw) ? (str_contains($raw, '.') ? (float) $raw : (int) $raw) : $raw;
            }

            $row[$colIndex] = $value;
        }
        $rows[$rowIndex] = $row;
    }

    // Заполняем пропуски пустыми строками, чтобы индексы массива совпадали
    // с 0-based номерами строк листа без дырок (ksort — на случай, если XML
    // отдал строки не по возрастанию r=, что валидно по формату OOXML).
    if (!empty($rows)) {
        ksort($rows);
        $maxIndex = array_key_last($rows);
        for ($i = 0; $i <= $maxIndex; $i++) {
            if (!isset($rows[$i])) {
                $rows[$i] = [];
            }
        }
        ksort($rows);
    }

    return $rows;
}

function xlsx_read_shared_strings(ZipArchive $zip): array {
    $xmlStr = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlStr === false) {
        return [];
    }
    $xml = simplexml_load_string($xmlStr);
    $strings = [];
    foreach ($xml->si as $si) {
        if (isset($si->t)) {
            $strings[] = (string) $si->t;
        } else {
            // Строка составлена из нескольких <r><t>...</t></r> (rich text runs)
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }
    }
    return $strings;
}

function xlsx_col_letter_to_index(string $letters): int {
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

// Преобразует массив строк (0-based индексы колонок) в массив ассоциативных
// массивов "имя колонки" => значение. $headerRowIndex — строка с заголовками
// (не всегда первая: экспорты X2POS часто начинаются со служебных строк вида
// "Компания:", "Период:" перед реальной таблицей).
function xlsx_rows_to_assoc(array $rows, int $headerRowIndex = 0): array {
    if (empty($rows) || !isset($rows[$headerRowIndex])) {
        return [];
    }
    $header = $rows[$headerRowIndex];
    $result = [];
    for ($i = $headerRowIndex + 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $assoc = [];
        foreach ($header as $colIndex => $colName) {
            if ($colName === null || $colName === '') {
                continue;
            }
            $assoc[$colName] = $row[$colIndex] ?? null;
        }
        $result[] = $assoc;
    }
    return $result;
}

// Картинки в xlsx не сидят в ячейках — они "приклеены" поверх листа через
// xl/drawings/drawingN.xml (якорь: номер строки/колонки + r:embed на файл в
// xl/media/). Возвращает [0-based номер строки => путь до файла в zip].
// Один анкор на строку — если для строки их несколько, берём первый.
function xlsx_read_row_images(string $path, int $sheetIndex = 0): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    $sheetRelsPath = "xl/worksheets/_rels/sheet" . ($sheetIndex + 1) . ".xml.rels";
    $sheetRelsXml = $zip->getFromName($sheetRelsPath);
    if ($sheetRelsXml === false) {
        $zip->close();
        return [];
    }

    $drawingTarget = null;
    foreach (simplexml_load_string($sheetRelsXml)->Relationship as $rel) {
        if (str_contains((string) $rel['Type'], 'drawing')) {
            $drawingTarget = (string) $rel['Target'];
        }
    }
    if ($drawingTarget === null) {
        $zip->close();
        return [];
    }

    $drawingPath = xlsx_resolve_relative_path('xl/worksheets/', $drawingTarget);
    $drawingXml = $zip->getFromName($drawingPath);
    if ($drawingXml === false) {
        $zip->close();
        return [];
    }

    $drawingRelsPath = dirname($drawingPath) . '/_rels/' . basename($drawingPath) . '.rels';
    $drawingRelsXml = $zip->getFromName($drawingRelsPath);
    $ridToTarget = [];
    if ($drawingRelsXml !== false) {
        foreach (simplexml_load_string($drawingRelsXml)->Relationship as $rel) {
            $ridToTarget[(string) $rel['Id']] = (string) $rel['Target'];
        }
    }
    $zip->close();

    $xml = simplexml_load_string($drawingXml);
    $xml->registerXPathNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
    $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

    $rowToMedia = [];
    foreach ($xml->xpath('//xdr:oneCellAnchor|//xdr:twoCellAnchor') as $anchor) {
        $rowNodes = $anchor->xpath('.//xdr:from/xdr:row');
        $blipNodes = $anchor->xpath('.//a:blip');
        if (empty($rowNodes) || empty($blipNodes)) {
            continue;
        }
        $row = (int) (string) $rowNodes[0];
        if (isset($rowToMedia[$row])) {
            continue;
        }
        $embedAttrs = $blipNodes[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rId = (string) $embedAttrs['embed'];
        if (!isset($ridToTarget[$rId])) {
            continue;
        }
        $rowToMedia[$row] = xlsx_resolve_relative_path(dirname($drawingPath) . '/', $ridToTarget[$rId]);
    }

    return $rowToMedia;
}

function xlsx_resolve_relative_path(string $basePath, string $relativeTarget): string {
    $parts = [];
    foreach (explode('/', $basePath . $relativeTarget) as $part) {
        if ($part === '..') {
            array_pop($parts);
        } elseif ($part !== '.' && $part !== '') {
            $parts[] = $part;
        }
    }
    return implode('/', $parts);
}

function xlsx_read_media_bytes(string $xlsxPath, string $mediaZipPath): ?string {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        return null;
    }
    $data = $zip->getFromName($mediaZipPath);
    $zip->close();
    return $data === false ? null : $data;
}

function xlsx_detect_image_extension(string $bytes): string {
    if (substr($bytes, 0, 2) === "\xFF\xD8") {
        return 'jpg';
    }
    if (substr($bytes, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
        return 'png';
    }
    if (substr($bytes, 0, 6) === "GIF87a" || substr($bytes, 0, 6) === "GIF89a") {
        return 'gif';
    }
    if (substr($bytes, 0, 4) === "RIFF" && substr($bytes, 8, 4) === "WEBP") {
        return 'webp';
    }
    return 'bin';
}
