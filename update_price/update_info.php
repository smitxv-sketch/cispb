<?php
/**
 * Скрипт синхронизации прайс-листа между qMS и сайтом MODX
 * 
 * Использование:
 *   - Демо-режим (без изменений в БД): script.php?demo
 *   - Боевой режим: script.php
 */

// Подавление вывода ошибок, чтобы не ломать HTML-структуру отчета
error_reporting(0);
ini_set('display_errors', 0);

// ============================================================================
// НАСТРОЙКИ ПОДКЛЮЧЕНИЯ К БД
// ============================================================================

$dbConfig = [
    'host'     => 'localhost',
    'username' => 'cistospb_new',
    'password' => 'pbmVWVxma2V',
    'dbname'   => 'cistospb_new',
    'charset'  => 'utf8',
];

// ============================================================================
// НАСТРОЙКИ ТАБЛИЦ
// ============================================================================

$tableConfig = [
    'pricelist' => 'modx_pricelist_items',  // Таблица прайс-листа
];

// ============================================================================
// НАСТРОЙКИ API QMS
// ============================================================================

$qmsConfig = [
    'url'          => 'https://back.cispb.ru/qms-api/getPr',
    'apikey'       => '86g4njnrWN6M8xTCsAfaBstR',
    'unauthorized' => 1,
    'qqc244'       => 'ТAIdAA]AFAA',
];

// ============================================================================
// НАСТРОЙКИ EMAIL-УВЕДОМЛЕНИЙ
// ============================================================================

$emailConfig = [
    'enabled'    => true,
    'recipients' => ['n.karavaeva@cispb.ru'],
];

// ============================================================================
// НАСТРОЙКИ СИНХРОНИЗАЦИИ
// ============================================================================

// Обновлять названия услуг, если они не совпадают с данными из qMS
$updateTitle = false;

// Обновлять цены, если они не совпадают с данными из qMS
$updatePrice = true;

// Публиковать на сайте услуги, которые есть в qMS, но на сайте скрыты (published = 0)
$publishExisting = false;

// Снимать с публикации на сайте услуги, которых больше нет в qMS
$unpublishMissing = true;

// ============================================================================
// КОНЕЦ НАСТРОЕК
// ============================================================================

// Определение режима работы
$isDemoMode = isset($_GET['demo']);

// Глобальная переменная для сбора HTML отчета
$htmlReport = '';

// Массив для хранения ошибок SQL
$sqlErrors = [];

/**
 * Вывод и сохранение строки в отчет
 */
function echoReport($str) {
    global $htmlReport;
    echo $str;
    $htmlReport .= $str . PHP_EOL;
}

/**
 * Рекурсивная очистка директории
 */
function clear_dir($dir, $rmdir = false) {
    if ($objs = glob($dir . '/*')) {
        foreach ($objs as $obj) {
            is_dir($obj) ? clear_dir($obj, true) : unlink($obj);
        }
    }
    if ($rmdir) {
        rmdir($dir);
    }
}

/**
 * Безопасное выполнение UPDATE-запроса с логированием ошибок
 */
function safeUpdate($mysqli, $query, $description = '') {
    global $sqlErrors, $isDemoMode;
    
    if ($isDemoMode) {
        return true;
    }
    
    $result = mysqli_query($mysqli, $query);
    if (!$result) {
        $error = [
            'query'       => $query,
            'error'       => mysqli_error($mysqli),
            'description' => $description,
        ];
        $sqlErrors[] = $error;
        return false;
    }
    return true;
}

/**
 * Получение данных из API qMS
 */
function getData($qmsConfig) {
    global $response;

    $fields = [
        'apikey'       => $qmsConfig['apikey'],
        'unauthorized' => $qmsConfig['unauthorized'],
        'qqc244'       => $qmsConfig['qqc244'],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $qmsConfig['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $json = curl_exec($ch);
    $response = $json;

    if (curl_errno($ch)) {
        echoReport('<p class="error">Ошибка cURL: ' . curl_error($ch) . '</p>');
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    
    $data = json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echoReport('<p class="error">Ошибка декодирования JSON: ' . json_last_error_msg() . '</p>');
        return null;
    }
    
    return $data;
}

// ============================================================================
// НАЧАЛО СКРИПТА
// ============================================================================

// Подключение к БД
$mysqli = mysqli_connect(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['dbname']
);

if (!$mysqli) {
    die('<p class="error">Ошибка подключения к базе данных: ' . mysqli_connect_error() . '</p>');
}
mysqli_set_charset($mysqli, $dbConfig['charset']);

// HTML-заголовок и стили
$htmlHeader = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Отчет о синхронизации цен</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; color: #333; margin: 20px; }
        .summary { border: 1px solid #ddd; padding: 15px; border-radius: 8px; background-color: #f9f9f9; margin-bottom: 20px; max-width: 900px; }
        .settings-block { border: 1px solid #d0d0d0; padding: 10px 15px; border-radius: 5px; background-color: #f5f5f5; margin-bottom: 15px; font-size: 0.9em; }
        .settings-block code { background-color: #e8e8e8; padding: 2px 6px; border-radius: 3px; }
        .details-block { border: 1px solid #e0e0e0; border-radius: 5px; margin-bottom: 10px; }
        summary { cursor: pointer; padding: 10px; background-color: #f0f0f0; font-weight: bold; list-style: none; display: flex; align-items: center; gap: 8px; }
        summary::-webkit-details-marker { display: none; }
        summary:before { content: '▶'; font-size: 0.8em; flex-shrink: 0; }
        details[open] > summary:before { content: '▼'; }
        .details-content { padding: 15px; border-top: 1px solid #e0e0e0; }
        .details-content ol { padding-left: 20px; }
        .details-content li { margin-bottom: 5px; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .header { font-size: 1.5em; font-weight: bold; color: #333; border-bottom: 2px solid #ccc; padding-bottom: 5px; margin-top: 20px; }
        .header2 { font-size: 1.2em; font-weight: bold; color: #555; margin-top: 15px; margin-bottom: 5px; }
        .comment { font-style: italic; color: #666; margin-top: 0; margin-bottom: 15px; font-size: 0.9em; }
        .change_price, .change_status, .change_title { margin-left: 20px; padding: 5px; border-radius: 4px; font-size: 0.9em; }
        .change_price { background-color: #fffbe6; border-left: 3px solid #ffe58f; }
        .change_status { background-color: #fff0f0; border-left: 3px solid #ffccc7; }
        .change_status_publish { background-color: #f0fff0; border-left: 3px solid #95de64; }
        .change_title { background-color: #e6f7ff; border-left: 3px solid #91d5ff; }
        .change_skipped { background-color: #f5f5f5; border-left: 3px solid #d9d9d9; color: #888; }
        .demo { background-color: #ffc107; color: #333; padding: 10px; border-radius: 5px; text-align: center; font-weight: bold; margin-bottom: 20px; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 10px; border-radius: 5px; margin-bottom: 10px; }
        .warning { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 10px; border-radius: 5px; margin-bottom: 10px; }
        .prefix { font-weight: bold; color: #d9534f; }
        .setting-on { color: #28a745; font-weight: bold; }
        .setting-off { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
HTML;

echo $htmlHeader;
$htmlReport .= $htmlHeader;

// Префикс для демо-режима
$changePrefix = $isDemoMode ? '<span class="prefix">ПОТЕНЦИАЛЬНОЕ ИЗМЕНЕНИЕ: </span>' : '';

if ($isDemoMode) {
    echoReport('<p class="demo">РЕЖИМ ДЕМОНСТРАЦИИ: Изменения в базу данных вноситься не будут.</p>');
}

// Вывод текущих настроек
echoReport('<div class="settings-block">');
echoReport('<strong>Текущие настройки синхронизации:</strong><br>');
echoReport('Обновление названий: ' . ($updateTitle ? '<span class="setting-on">ВКЛ</span>' : '<span class="setting-off">ВЫКЛ</span>') . ' | ');
echoReport('Обновление цен: ' . ($updatePrice ? '<span class="setting-on">ВКЛ</span>' : '<span class="setting-off">ВЫКЛ</span>') . ' | ');
echoReport('Публикация существующих: ' . ($publishExisting ? '<span class="setting-on">ВКЛ</span>' : '<span class="setting-off">ВЫКЛ</span>') . ' | ');
echoReport('Снятие отсутствующих: ' . ($unpublishMissing ? '<span class="setting-on">ВКЛ</span>' : '<span class="setting-off">ВЫКЛ</span>'));
echoReport('<br><small>Таблица: <code>' . $tableConfig['pricelist'] . '</code></small>');
echoReport('</div>');

// ============================================================================
// СБОР ДАННЫХ С САЙТА (БД)
// ============================================================================

$itemsDB = [];
$query = "SELECT id, resource_id, doc_id, name, price, published FROM `{$tableConfig['pricelist']}`";
$data = mysqli_query($mysqli, $query);

if (!$data) {
    echoReport('<p class="error">Ошибка выполнения запроса к БД: ' . mysqli_error($mysqli) . '</p>');
    exit;
}

while ($row = mysqli_fetch_assoc($data)) {
    $itemsDB[$row['id']] = [
        'title'       => $row['name'],
        'resource_id' => $row['resource_id'],
        'article'     => $row['doc_id'],
        'price'       => $row['price'],
        'post_status' => $row['published'],
    ];
}

// ============================================================================
// СБОР ДАННЫХ ИЗ QMS (API)
// ============================================================================

$data1 = getData($qmsConfig);
$responseForJsonFile = $response;

if (!$data1 || !isset($data1->data->sections)) {
    echoReport('<p class="error">Не удалось получить или обработать данные из qMS API.</p>');
    exit;
}

$sections = $data1->data->sections;
$itemsJson = [];

foreach ($sections as $section) {
    foreach ($section->rows as $row) {
        $article = $row->Duv;
        $price = $row->Mr70;
        
        // Нормализация цены (удаление дробной части если она нулевая)
        if (preg_match('/(.+)(\.|\,)(.+)/', $price, $match)) {
            if ((int)$match[3] === 0) {
                $price = $match[1];
            }
        }
        
        $itemsJson[$article] = [
            'price' => $price,
            'title' => $row->u,
        ];
    }
}

// ============================================================================
// АНАЛИЗ И СРАВНЕНИЕ ДАННЫХ
// ============================================================================

// Услуги в qMS, но нет на сайте
$inJsonNotInWP = [];
foreach ($itemsJson as $article => $itemJson) {
    $isFound = false;
    foreach ($itemsDB as $itemDB) {
        if ($itemDB['article'] === $article) {
            $isFound = true;
            break;
        }
    }
    if (!$isFound) {
        $inJsonNotInWP[] = $article;
    }
}

// Инициализация счетчиков и строк для отчета
$counters = [
    'matched'                  => 0,  // Полностью совпадают
    'updated'                  => 0,  // Обновлены (любые изменения)
    'price_updated'            => 0,  // Обновлена цена
    'title_updated'            => 0,  // Обновлено название
    'published'                => 0,  // Опубликованы
    'unpublished_missing'      => 0,  // Сняты с публ. (нет в qMS)
    'unpublished_dash_price'   => 0,  // Сняты с публ. (цена "-")
    'no_article'               => 0,  // Без артикула
    'cyrillic_article'         => 0,  // Кириллица в артикуле
    'already_hidden'           => 0,  // Уже были скрыты
    'discrepancies_ignored'    => 0,  // Расхождения найдены, но настройки отключены
];

$strings = [
    'matched'               => '',
    'updated'               => '',
    'unpublished_missing'   => '',
    'no_article'            => '',
    'cyrillic_article'      => '',
    'already_hidden'        => '',
    'discrepancies_ignored' => '',
];

$notInJsonInWP = [];
$notInJsonInWPCyrillic = [];

// ============================================================================
// ОСНОВНОЙ ЦИКЛ СРАВНЕНИЯ
// ============================================================================

foreach ($itemsDB as $id => $itemDB) {
    $baseStr = '<li><a href="https://cispb.com/manager/?a=resource/update&id=' . $itemDB['resource_id'] . '" target="_blank">' . htmlspecialchars($itemDB['title']) . '</a> (Артикул: ' . htmlspecialchars($itemDB['article']) . ')</li>';
    
    // --- Услуги без артикула ---
    if (!$itemDB['article']) {
        $counters['no_article']++;
        $strings['no_article'] .= $baseStr;
        continue;
    }

    // --- Услуга есть на сайте, но НЕТ в qMS ---
    if (!array_key_exists($itemDB['article'], $itemsJson)) {
        $notInJsonInWP[] = $itemDB;
        
        // Проверка на кириллицу в артикуле
        if (preg_match('/[а-яё]/iu', $itemDB['article'])) {
            $notInJsonInWPCyrillic[] = $itemDB;
            $counters['cyrillic_article']++;
        }
        
        if ($itemDB['post_status'] == 1) {
            // Опубликована, но нет в qMS
            $str = $baseStr;
            if ($unpublishMissing) {
                $counters['unpublished_missing']++;
                $str .= '<p class="change_status">' . $changePrefix . 'Снято с публикации (услуга отсутствует в qMS).</p>';
                safeUpdate($mysqli, 
                    "UPDATE `{$tableConfig['pricelist']}` SET `published` = 0 WHERE `id` = " . intval($id),
                    "Снятие с публикации услуги ID=$id (нет в qMS)"
                );
            } else {
                $counters['discrepancies_ignored']++;
                $str .= '<p class="change_skipped">Услуга отсутствует в qMS, но настройка unpublishMissing отключена.</p>';
            }
            $strings['unpublished_missing'] .= $str;
        } else {
            // Уже была скрыта
            $counters['already_hidden']++;
            $strings['already_hidden'] .= $baseStr;
        }
        continue;
    }

    // --- Услуга есть и на сайте, и в qMS ---
    $itemJson = $itemsJson[$itemDB['article']];
    $changes = '';
    $actionTaken = false;
    $discrepancyFound = false;

    // Проверка статуса публикации
    if ($itemDB['post_status'] != 1 && $itemJson['price'] !== '-') {
        $discrepancyFound = true;
        if ($publishExisting) {
            $actionTaken = true;
            $counters['published']++;
            $changes .= '<p class="change_status_publish">' . $changePrefix . 'Опубликовано (ранее было скрыто).</p>';
            safeUpdate($mysqli,
                "UPDATE `{$tableConfig['pricelist']}` SET `published` = 1 WHERE `id` = " . intval($id),
                "Публикация услуги ID=$id"
            );
        } else {
            $changes .= '<p class="change_skipped">Услуга скрыта на сайте, но есть в qMS. Настройка publishExisting отключена.</p>';
        }
    }

    // Проверка цены
    if ($itemDB['price'] != $itemJson['price']) {
        $discrepancyFound = true;
        if ($updatePrice) {
            $actionTaken = true;
            if ($itemJson['price'] === '-') {
                $counters['unpublished_dash_price']++;
                $changes .= '<p class="change_status">' . $changePrefix . 'Снято с публикации (цена в qMS = "-").</p>';
                safeUpdate($mysqli,
                    "UPDATE `{$tableConfig['pricelist']}` SET `published` = 0 WHERE `id` = " . intval($id),
                    "Снятие с публикации услуги ID=$id (цена '-')"
                );
            } else {
                $counters['price_updated']++;
                $changes .= '<p class="change_price">' . $changePrefix . 'Цена изменена: ' . htmlspecialchars($itemDB['price']) . ' → ' . htmlspecialchars($itemJson['price']) . '</p>';
                safeUpdate($mysqli,
                    "UPDATE `{$tableConfig['pricelist']}` SET `price` = '" . mysqli_real_escape_string($mysqli, $itemJson['price']) . "' WHERE `id` = " . intval($id),
                    "Обновление цены услуги ID=$id"
                );
            }
        } else {
            $changes .= '<p class="change_skipped">Цена отличается (' . htmlspecialchars($itemDB['price']) . ' vs ' . htmlspecialchars($itemJson['price']) . '), но настройка updatePrice отключена.</p>';
        }
    }
    
    // Проверка названия
    if ($itemDB['title'] !== $itemJson['title']) {
        $discrepancyFound = true;
        if ($updateTitle) {
            $actionTaken = true;
            $counters['title_updated']++;
            $changes .= '<p class="change_title">' . $changePrefix . 'Название изменено: "' . htmlspecialchars($itemDB['title']) . '" → "' . htmlspecialchars($itemJson['title']) . '"</p>';
            safeUpdate($mysqli,
                "UPDATE `{$tableConfig['pricelist']}` SET `name` = '" . mysqli_real_escape_string($mysqli, $itemJson['title']) . "' WHERE `id` = " . intval($id),
                "Обновление названия услуги ID=$id"
            );
        } else {
            $changes .= '<p class="change_skipped">Название отличается, но настройка updateTitle отключена.</p>';
        }
    }

    // Распределение по категориям
    if ($actionTaken) {
        $counters['updated']++;
        $strings['updated'] .= $baseStr . $changes;
    } elseif ($discrepancyFound) {
        $counters['discrepancies_ignored']++;
        $strings['discrepancies_ignored'] .= $baseStr . $changes;
    } else {
        $counters['matched']++;
        $strings['matched'] .= $baseStr;
    }
}

// ============================================================================
// ФОРМИРОВАНИЕ HTML-ОТЧЕТА
// ============================================================================

echoReport('<div class="summary">');
echoReport('<p class="header">Сводный отчет по синхронизации</p>');
echoReport('<p class="header2">Всего услуг в qMS: ' . count($itemsJson) . '</p>');
echoReport('<p class="header2">Всего услуг на сайте: ' . count($itemsDB) . '</p>');

echoReport('<hr style="margin: 15px 0; border: none; border-top: 1px solid #ddd;">');

echoReport('<p class="header2">✓ Полностью совпадают: ' . $counters['matched'] . '</p>');
echoReport('<p class="comment">Эти услуги идентичны в обеих системах, изменения не требуются.</p>');

$updatedLabel = $isDemoMode ? 'Будет обновлено' : 'Обновлено';
echoReport('<p class="header2">⟳ ' . $updatedLabel . ': ' . $counters['updated'] . '</p>');
echoReport('<p class="comment">Детализация: цена обновлена — ' . $counters['price_updated'] . ', название — ' . $counters['title_updated'] . ', опубликовано — ' . $counters['published'] . '</p>');

$totalUnpublished = $counters['unpublished_missing'] + $counters['unpublished_dash_price'];
$unpubLabel = $isDemoMode ? 'Будет снято с публикации' : 'Снято с публикации';
echoReport('<p class="header2">✗ ' . $unpubLabel . ': ' . $totalUnpublished . '</p>');
echoReport('<p class="comment">Из них: отсутствует в qMS — ' . $counters['unpublished_missing'] . ', цена "-" — ' . $counters['unpublished_dash_price'] . '</p>');

if ($counters['discrepancies_ignored'] > 0) {
    echoReport('<p class="header2">⚠ Расхождения найдены, но не применены: ' . $counters['discrepancies_ignored'] . '</p>');
    echoReport('<p class="comment">Для этих услуг найдены отличия, но соответствующие настройки синхронизации отключены.</p>');
}

echoReport('<p class="header2">+ Есть в qMS, но нет на сайте: ' . count($inJsonNotInWP) . '</p>');
echoReport('<p class="comment">Эти услуги требуют ручного создания карточек на сайте.</p>');

echoReport('<p class="header2">○ Уже скрыты на сайте (и нет в qMS): ' . $counters['already_hidden'] . '</p>');
echoReport('<p class="comment">Эти услуги были сняты с публикации ранее, дополнительных действий не требуется.</p>');

echoReport('<p class="header2">⊘ Без артикула на сайте: ' . $counters['no_article'] . '</p>');
echoReport('<p class="comment">Автоматическая сверка для этих услуг невозможна.</p>');

echoReport('<p class="header2">⚡ Кириллица в артикуле: ' . $counters['cyrillic_article'] . '</p>');
echoReport('<p class="comment">Рекомендуется исправить — кириллические символы могут вызывать проблемы сопоставления.</p>');

echoReport('</div>');

// Вывод ошибок SQL, если были
if (!empty($sqlErrors)) {
    echoReport('<div class="error">');
    echoReport('<strong>⚠ Ошибки при выполнении SQL-запросов:</strong><br>');
    foreach ($sqlErrors as $err) {
        echoReport('<p>' . htmlspecialchars($err['description']) . ': ' . htmlspecialchars($err['error']) . '</p>');
    }
    echoReport('</div>');
}

// Функция создания сворачиваемого блока
function createCollapsibleBlock($title, $count, $content) {
    if ($count > 0 || !empty($content)) {
        echoReport('<details class="details-block">');
        echoReport('<summary>' . $title . ' (' . $count . ')</summary>');
        echoReport('<div class="details-content">' . $content . '</div>');
        echoReport('</details>');
    }
}

createCollapsibleBlock('✓ Полностью совпадают', $counters['matched'], '<ol>' . $strings['matched'] . '</ol>');
createCollapsibleBlock('⟳ Обновлено', $counters['updated'], '<ol>' . $strings['updated'] . '</ol>');
createCollapsibleBlock('✗ Снято с публикации (нет в qMS)', $counters['unpublished_missing'], '<ol>' . $strings['unpublished_missing'] . '</ol>');

if ($counters['discrepancies_ignored'] > 0) {
    createCollapsibleBlock('⚠ Расхождения найдены, но не применены', $counters['discrepancies_ignored'], '<ol>' . $strings['discrepancies_ignored'] . '</ol>');
}

// Формируем группированный список "Есть в qMS, но нет на сайте"
$groupedMissing = [];
$priceStats = ['with_price' => 0, 'no_price' => 0, 'total_sum' => 0];

foreach ($inJsonNotInWP as $article) {
    // Извлекаем префикс (например, B01.001 из B01.001.73)
    $parts = explode('.', $article);
    if (count($parts) >= 2) {
        $prefix = $parts[0] . '.' . $parts[1];
    } else {
        $prefix = 'Другие';
    }
    
    if (!isset($groupedMissing[$prefix])) {
        $groupedMissing[$prefix] = [];
    }
    
    $title = isset($itemsJson[$article]) ? $itemsJson[$article]['title'] : '—';
    $price = isset($itemsJson[$article]) ? $itemsJson[$article]['price'] : '—';
    
    // Статистика по ценам
    if ($price !== '-' && $price !== '—' && is_numeric(str_replace([' ', ','], ['', '.'], $price))) {
        $priceStats['with_price']++;
        $priceStats['total_sum'] += floatval(str_replace([' ', ','], ['', '.'], $price));
    } else {
        $priceStats['no_price']++;
    }
    
    $groupedMissing[$prefix][] = [
        'article' => $article,
        'title' => $title,
        'price' => $price,
    ];
}

// Сортируем группы по префиксу
ksort($groupedMissing);

// Формируем HTML — сворачиваемый блок
echoReport('<details class="details-block" style="margin-top: 20px; border: 2px solid #17a2b8; border-radius: 8px;">');

// Заголовок (summary) — всегда видно
echoReport('<summary style="background: #17a2b8; color: white; padding: 15px; border-radius: 6px;">');
echoReport('<span style="font-size: 1.2em;">📋 Есть в qMS, но нет на сайте (' . count($inJsonNotInWP) . ')</span>');
echoReport('</summary>');

// Содержимое — раскрывается при клике
echoReport('<div style="padding: 15px;">');

foreach ($groupedMissing as $prefix => $items) {
    $anchorId = 'group-' . htmlspecialchars(str_replace('.', '-', $prefix));
    
    // Берём название первой услуги и сокращаем до ~2.5 слов
    $firstTitle = isset($items[0]['title']) ? $items[0]['title'] : '';
    $sectionHint = '';
    if ($firstTitle) {
        // Убираем скобки и их содержимое
        $cleanTitle = preg_replace('/\s*\([^)]*\)/', '', $firstTitle);
        $cleanTitle = trim($cleanTitle);
        // Разбиваем на слова
        $words = preg_split('/\s+/', $cleanTitle);
        // Берём первые 2-3 слова (примерно 2.5)
        $sectionHint = implode(' ', array_slice($words, 0, 3));
        // Если получилось слишком длинно, обрезаем
        if (mb_strlen($sectionHint) > 30) {
            $sectionHint = mb_substr($sectionHint, 0, 27) . '...';
        }
    }
    
    echoReport('<div id="' . $anchorId . '" style="margin-bottom: 25px;">');
    echoReport('<div style="background: #495057; color: white; padding: 10px 15px; border-radius: 4px 4px 0 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">');
    echoReport('<div>');
    echoReport('<span style="font-weight: bold; font-size: 1.1em;">📁 ' . htmlspecialchars($prefix) . '</span>');
    if ($sectionHint) {
        echoReport('<span style="margin-left: 10px; opacity: 0.8; font-size: 0.9em;">— ' . htmlspecialchars($sectionHint) . '</span>');
    }
    echoReport('</div>');
    echoReport('<span style="background: #ffc107; color: #333; padding: 3px 12px; border-radius: 12px; font-size: 0.85em; font-weight: bold;">' . count($items) . ' услуг</span>');
    echoReport('</div>');
    
    echoReport('<table style="width: 100%; border-collapse: collapse; font-size: 0.9em; border: 1px solid #dee2e6; border-top: none;">');
    echoReport('<thead><tr style="background: #f8f9fa;">');
    echoReport('<th style="padding: 8px 12px; text-align: left; border: 1px solid #dee2e6; border-top: none; width: 140px;">Артикул</th>');
    echoReport('<th style="padding: 8px 12px; text-align: left; border: 1px solid #dee2e6; border-top: none;">Название в qMS</th>');
    echoReport('<th style="padding: 8px 12px; text-align: right; border: 1px solid #dee2e6; border-top: none; width: 110px;">Цена</th>');
    echoReport('</tr></thead><tbody>');
    
    // Сортируем услуги внутри группы
    usort($items, function($a, $b) {
        return strcmp($a['article'], $b['article']);
    });
    
    $rowNum = 0;
    foreach ($items as $item) {
        $rowNum++;
        $priceDisplay = ($item['price'] === '-' || $item['price'] === '—') 
            ? '<span style="color: #dc3545; font-weight: bold;">—</span>' 
            : number_format(floatval(str_replace([' ', ','], ['', '.'], $item['price'])), 0, ',', ' ') . ' ₽';
        
        $rowBg = ($item['price'] === '-' || $item['price'] === '—') 
            ? 'background: #fff5f5;' 
            : ($rowNum % 2 === 0 ? 'background: #fafafa;' : '');
        
        echoReport('<tr style="' . $rowBg . '">');
        echoReport('<td style="padding: 6px 12px; border: 1px solid #eee; font-family: \'Consolas\', monospace; font-size: 0.85em; color: #495057;">' . htmlspecialchars($item['article']) . '</td>');
        echoReport('<td style="padding: 6px 12px; border: 1px solid #eee;">' . htmlspecialchars($item['title']) . '</td>');
        echoReport('<td style="padding: 6px 12px; border: 1px solid #eee; text-align: right; white-space: nowrap; font-weight: 500;">' . $priceDisplay . '</td>');
        echoReport('</tr>');
    }
    
    echoReport('</tbody></table>');
    echoReport('</div>');
}

echoReport('</div>'); // Закрываем padding-контейнер
echoReport('</div>'); // Закрываем содержимое details
echoReport('</details>'); // Закрываем details
createCollapsibleBlock('○ Уже скрыты на сайте', $counters['already_hidden'], '<ol>' . $strings['already_hidden'] . '</ol>');
createCollapsibleBlock('⊘ Без артикула', $counters['no_article'], '<ol>' . $strings['no_article'] . '</ol>');

$cyrillicContent = '';
foreach ($notInJsonInWPCyrillic as $item) {
    $cyrillicContent .= '<p><a href="https://cispb.com/manager/?a=resource/update&id=' . $item['resource_id'] . '" target="_blank">' . htmlspecialchars($item['title']) . '</a> (Артикул: ' . htmlspecialchars($item['article']) . ')</p>';
}
createCollapsibleBlock('⚡ Кириллица в артикуле', $counters['cyrillic_article'], $cyrillicContent);

// ============================================================================
// ЗАВЕРШЕНИЕ РАБОТЫ
// ============================================================================

// Очистка кэша MODX
if (!$isDemoMode) {
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/core/cache';
    clear_dir($dir);
}

// Запись отчета и отправка email
if (!$isDemoMode && $emailConfig['enabled']) {
    if (!is_dir('reports')) {
        mkdir('reports', 0755, true);
    }
    $path = 'reports/' . date('Y-m-d_H-i-s') . '_report.html';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $path;
    
    file_put_contents($path, $htmlReport);
    
    $emailSubject = "Синхронизация: Обновлено {$counters['updated']}; Снято {$totalUnpublished}; Без арт. {$counters['no_article']}";
    
    foreach ($emailConfig['recipients'] as $recipient) {
        mail($recipient, $emailSubject, "Ссылка на отчет: " . $url);
    }
}

// Запись JSON-ответа для отладки
$responseForJsonFile = json_encode(json_decode($responseForJsonFile), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents('json.html', '<pre>' . $responseForJsonFile . '</pre>');
echo '<br><a href="json.html" target="_blank">Ссылка на полный JSON-ответ от qMS</a>';

mysqli_close($mysqli);
echoReport('</body></html>');