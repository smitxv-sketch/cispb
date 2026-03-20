<?php
/**
 * Синхронизация цен qMS → Сайт
 * Версия 4.0 — Упрощённый интерфейс для отдела маркетинга
 */

error_reporting(0);
ini_set('display_errors', 0);








// ============================================================================
// ЗАЩИТА ПАРОЛЕМ
// ============================================================================

$secretPassword = 'ci74';  // ← Измените на свой пароль

session_start();

// Проверка авторизации
if (!isset($_SESSION['price_sync_auth']) || $_SESSION['price_sync_auth'] !== true) {
    // Проверка введённого пароля
    if (isset($_POST['password']) && $_POST['password'] === $secretPassword) {
        $_SESSION['price_sync_auth'] = true;
    } else {
        // Форма ввода пароля
        if (isset($_POST['password'])) {
            $error = '<p style="color: #dc3545; margin-bottom: 15px;">Неверный пароль</p>';
        } else {
            $error = '';
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Вход</title>
        <style>
            body { font-family: -apple-system, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                   min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
            .login-box { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); 
                         text-align: center; max-width: 320px; }
            h2 { margin: 0 0 20px; color: #333; }
            input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; 
                                     font-size: 1em; margin-bottom: 15px; box-sizing: border-box; }
            button { width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                     color: white; border: none; border-radius: 8px; font-size: 1em; cursor: pointer; }
            button:hover { opacity: 0.9; }
        </style></head><body>
        <div class="login-box">
            <h2>🔐 Синхронизация цен</h2>
            ' . $error . '
            <form method="POST">
                <input type="password" name="password" placeholder="Введите пароль" autofocus>
                <button type="submit">Войти</button>
            </form>
        </div></body></html>';
        exit;
    }
}

// Кнопка выхода (добавить ?logout в URL)
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}


// ============================================================================
// НАСТРОЙКИ (скрыты от пользователя)
// ============================================================================

$dbConfig = [
    'host'     => 'localhost',
    'username' => 'cistospb_new',
    'password' => 'pbmVWVxma2V',
    'dbname'   => 'cistospb_new',
    'charset'  => 'utf8',
];

$tableConfig = [
    'pricelist'  => 'modx_pricelist_items2',
    'content'    => 'modx_site_content',
    'exclusions' => 'modx_qms_exclusions',  // Таблица для скрытых услуг
];

$qmsConfig = [
    'url'          => 'https://back.cispb.ru/qms-api/getPr',
    'apikey'       => '86g4njnrWN6M8xTCsAfaBstR',
    'unauthorized' => 1,
    'qqc244'       => 'ТAIdAA]AFAA',
];

$emailConfig = [
    'enabled'    => true,
    'recipients' => ['n.karavaeva@cispb.ru'],
];

// ============================================================================
// ОПРЕДЕЛЕНИЕ РЕЖИМА
// ============================================================================

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : 'start');
$showAdvanced = isset($_POST['show_advanced']) || isset($_GET['advanced']);

// ============================================================================
// AJAX-ОБРАБОТЧИК ДЛЯ УПРАВЛЕНИЯ ИСКЛЮЧЕНИЯМИ
// ============================================================================

if ($action === 'toggle_exclusion' && isset($_POST['doc_id'])) {
    header('Content-Type: application/json');
    
    $mysqli = mysqli_connect($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['dbname']);
    if (!$mysqli) {
        echo json_encode(['success' => false, 'error' => 'DB connection failed']);
        exit;
    }
    mysqli_set_charset($mysqli, $dbConfig['charset']);
    
    // Создаём таблицу если её нет
    ensureExclusionsTable($mysqli, $tableConfig['exclusions']);
    
    $docId = $_POST['doc_id'];
    $title = isset($_POST['title']) ? $_POST['title'] : '';
    $price = isset($_POST['price']) ? $_POST['price'] : '';
    $mode = isset($_POST['mode']) ? $_POST['mode'] : 'add'; // add или remove
    
    if ($mode === 'add') {
        $result = addExclusion($mysqli, $tableConfig['exclusions'], $docId, $title, $price);
    } else {
        $result = removeExclusion($mysqli, $tableConfig['exclusions'], $docId);
    }
    
    mysqli_close($mysqli);
    
    echo json_encode([
        'success' => (bool)$result,
        'doc_id'  => $docId,
        'mode'    => $mode
    ]);
    exit;
}

// Массовое добавление в исключения
if ($action === 'bulk_exclude' && isset($_POST['doc_ids'])) {
    header('Content-Type: application/json');
    
    $mysqli = mysqli_connect($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['dbname']);
    if (!$mysqli) {
        echo json_encode(['success' => false, 'error' => 'DB connection failed']);
        exit;
    }
    mysqli_set_charset($mysqli, $dbConfig['charset']);
    
    ensureExclusionsTable($mysqli, $tableConfig['exclusions']);
    
    $docIds = json_decode($_POST['doc_ids'], true);
    $count = 0;
    
    if (is_array($docIds)) {
        foreach ($docIds as $item) {
            if (addExclusion($mysqli, $tableConfig['exclusions'], $item['doc_id'], $item['title'], $item['price'])) {
                $count++;
            }
        }
    }
    
    mysqli_close($mysqli);
    
    echo json_encode(['success' => true, 'count' => $count]);
    exit;
}

// ============================================================================
// РЕЖИМ CRON (автоматический запуск без UI)
// ============================================================================

$cronMode = isset($_GET['cron']) || (php_sapi_name() === 'cli');

if ($cronMode) {
    // В режиме cron отключаем вывод HTML
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
// Параметры для cron
    $updateTitles = false;
    $unpublishMissing = isset($_GET['unpublish']) && $_GET['unpublish'] == '1';    
    
    
    $cronLog = [];
    $cronLog[] = "[" . date('Y-m-d H:i:s') . "] Запуск синхронизации цен (cron)";
    
    // Подключение к БД
    $mysqli = mysqli_connect($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['dbname']);
    if (!$mysqli) {
        $cronLog[] = "ОШИБКА: Не удалось подключиться к БД";
        echo implode("\n", $cronLog);
        exit(1);
    }
    mysqli_set_charset($mysqli, $dbConfig['charset']);
    
    // Данные с сайта
    $itemsDB = [];
    $res = mysqli_query($mysqli, "SELECT doc_id, MIN(name) AS name, MIN(price) AS price, COUNT(*) AS rows_count FROM `{$tableConfig['pricelist']}` WHERE doc_id IS NOT NULL AND doc_id <> '' GROUP BY doc_id");
    while ($row = mysqli_fetch_assoc($res)) {
        $itemsDB[trim($row['doc_id'])] = [
            'title' => $row['name'],
            'price' => $row['price'],
            'rows'  => (int)$row['rows_count'],
        ];
    }
    $cronLog[] = "На сайте: " . count($itemsDB) . " услуг";
    
    // Данные из qMS
    $fields = ['apikey' => $qmsConfig['apikey'], 'unauthorized' => $qmsConfig['unauthorized'], 'qqc244' => $qmsConfig['qqc244']];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $qmsConfig['url'],
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => json_encode($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => 0,
    ]);
    $json = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        $cronLog[] = "ОШИБКА cURL: " . $curlError;
        echo implode("\n", $cronLog);
        exit(1);
    }
    
    $qmsData = json_decode($json);
    if (!$qmsData || !isset($qmsData->data->sections)) {
        $cronLog[] = "ОШИБКА: Не удалось получить данные из qMS";
        echo implode("\n", $cronLog);
        exit(1);
    }
    
    $itemsQMS = [];
    foreach ($qmsData->data->sections as $section) {
        foreach ($section->rows as $row) {
            $article = trim($row->Duv);
            $itemsQMS[$article] = [
                'price' => normalizePrice($row->Mr70),
                'title' => $row->u,
            ];
        }
    }
    $cronLog[] = "В qMS: " . count($itemsQMS) . " услуг";
    
    // Сравнение и обновление цен
    $priceUpdated = 0;
    $priceUnpublished = 0;
    
    foreach ($itemsDB as $docId => $dbItem) {
        if (!isset($itemsQMS[$docId])) continue;
        
        $qmsItem = $itemsQMS[$docId];
        $dbPrice = normalizePrice($dbItem['price']);
        $qmsPrice = $qmsItem['price'];
        
        if ($dbPrice !== $qmsPrice) {
            if ($qmsPrice === '-') {
                // Снять с публикации
                mysqli_query($mysqli, "UPDATE `{$tableConfig['pricelist']}` SET `published` = 0 WHERE `doc_id` = '" . mysqli_real_escape_string($mysqli, $docId) . "'");
                $priceUnpublished++;
                $cronLog[] = "  Скрыто: {$docId} ({$dbItem['title']})";
            } else {
                // Обновить цену
                mysqli_query($mysqli, "UPDATE `{$tableConfig['pricelist']}` SET `price` = '" . mysqli_real_escape_string($mysqli, $qmsPrice) . "' WHERE `doc_id` = '" . mysqli_real_escape_string($mysqli, $docId) . "'");
                $priceUpdated++;
                $cronLog[] = "  Цена: {$docId}: {$dbPrice} → {$qmsPrice}";
            }
        }
    }
    
    // Очистка кэша
    $cacheDir = dirname(__FILE__) . '/../core/cache';
    if (!is_dir($cacheDir)) {
        $cacheDir = $_SERVER['DOCUMENT_ROOT'] . '/core/cache';
    }
    if (is_dir($cacheDir)) {
        // Рекурсивная очистка
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        $cronLog[] = "Кэш очищен";
    }
    
    $cronLog[] = "---";
    $cronLog[] = "Обновлено цен: {$priceUpdated}";
    $cronLog[] = "Скрыто услуг: {$priceUnpublished}";
    $cronLog[] = "[" . date('Y-m-d H:i:s') . "] Завершено";
    
    // Отправка email если были изменения
    if (($priceUpdated > 0 || $priceUnpublished > 0) && $emailConfig['enabled']) {
        $subject = "Cron: обновлено {$priceUpdated} цен" . ($priceUnpublished > 0 ? ", скрыто {$priceUnpublished}" : "");
        $body = implode("\n", $cronLog);
        
        foreach ($emailConfig['recipients'] as $recipient) {
            mail($recipient, $subject, $body);
        }
        $cronLog[] = "Email отправлен";
    }
    
    mysqli_close($mysqli);
    
    // Вывод лога
    echo implode("\n", $cronLog) . "\n";
    exit(0);
}

// ============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================================

function normalizePrice($price) {
    $price = trim($price);
    if (preg_match('/^(.+?)[.,]0+$/', $price, $match)) {
        return $match[1];
    }
    return $price;
}

function formatPrice($price) {
    if ($price === '-' || $price === '') return '—';
    $num = floatval(str_replace([' ', ','], ['', '.'], $price));
    return number_format($num, 0, ',', ' ') . ' ₽';
}

/**
 * Создание таблицы исключений если не существует
 */
function ensureExclusionsTable($mysqli, $tableName) {
    $query = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
        `doc_id` VARCHAR(50) NOT NULL,
        `title` VARCHAR(500) DEFAULT NULL,
        `price` VARCHAR(50) DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `comment` VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (`doc_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    return mysqli_query($mysqli, $query);
}

/**
 * Добавить услугу в исключения
 */
function addExclusion($mysqli, $tableName, $docId, $title = '', $price = '') {
    $docId = mysqli_real_escape_string($mysqli, $docId);
    $title = mysqli_real_escape_string($mysqli, $title);
    $price = mysqli_real_escape_string($mysqli, $price);
    
    $query = "INSERT INTO `{$tableName}` (`doc_id`, `title`, `price`) 
              VALUES ('{$docId}', '{$title}', '{$price}')
              ON DUPLICATE KEY UPDATE `title` = '{$title}', `price` = '{$price}'";
    
    return mysqli_query($mysqli, $query);
}

/**
 * Удалить услугу из исключений
 */
function removeExclusion($mysqli, $tableName, $docId) {
    $docId = mysqli_real_escape_string($mysqli, $docId);
    return mysqli_query($mysqli, "DELETE FROM `{$tableName}` WHERE `doc_id` = '{$docId}'");
}

/**
 * Получить список исключений
 */
function getExclusions($mysqli, $tableName) {
    $result = mysqli_query($mysqli, "SELECT `doc_id` FROM `{$tableName}`");
    $exclusions = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $exclusions[$row['doc_id']] = true;
        }
    }
    return $exclusions;
}

// ============================================================================
// СТИЛИ
// ============================================================================

$styles = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Обновление цен на сайте</title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            line-height: 1.6; 
            color: #333; 
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        
        /* Карточка */
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 25px 30px;
        }
        .card-header h1 {
            margin: 0;
            font-size: 1.8em;
            font-weight: 600;
        }
        .card-header p {
            margin: 8px 0 0 0;
            opacity: 0.9;
            font-size: 1em;
        }
        .card-body { padding: 30px; }
        
        /* Стартовый экран */
        .start-screen {
            text-align: center;
            padding: 40px 20px;
        }
        .start-icon {
            font-size: 4em;
            margin-bottom: 20px;
        }
        .start-text {
            font-size: 1.2em;
            color: #666;
            margin-bottom: 30px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Кнопки */
        .btn {
            display: inline-block;
            padding: 15px 40px;
            font-size: 1.1em;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        .btn-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(17, 153, 142, 0.4);
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(17, 153, 142, 0.5);
        }
        .btn-outline {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 13px 38px;
        }
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9em;
        }
        
        /* Большие цифры итогов */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .stat-card.highlight {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .stat-card.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        .stat-card.warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .stat-number {
            font-size: 2.5em;
            font-weight: 700;
            line-height: 1;
        }
        .stat-label {
            font-size: 0.95em;
            margin-top: 8px;
            opacity: 0.9;
        }
        
        /* Таблица изменений */
        .changes-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .changes-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        .changes-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        .changes-table tr:hover {
            background: #f8f9fa;
        }
        .price-old {
            color: #dc3545;
            text-decoration: line-through;
            font-size: 0.9em;
        }
        .price-new {
            color: #28a745;
            font-weight: 600;
            font-size: 1.1em;
        }
        .price-arrow {
            color: #6c757d;
            margin: 0 8px;
        }
        .service-name {
            max-width: 400px;
        }
        .service-code {
            color: #6c757d;
            font-size: 0.85em;
            font-family: monospace;
        }
        
        /* Секции */
        .section {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .section-title {
            font-size: 1.2em;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title .badge {
            background: #6c757d;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: normal;
        }
        
        /* Алерты */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }
        .alert-info {
            background: #e7f3ff;
            border: 1px solid #b6d4fe;
            color: #084298;
        }
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        /* Расширенные настройки */
        .advanced-toggle {
            text-align: center;
            margin-top: 20px;
        }
        .advanced-toggle a {
            color: #6c757d;
            font-size: 0.9em;
            text-decoration: none;
        }
        .advanced-toggle a:hover {
            color: #495057;
            text-decoration: underline;
        }
        .advanced-options {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .advanced-options h3 {
            margin: 0 0 15px 0;
            font-size: 1em;
            color: #495057;
        }
        .option-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .option-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        .option-row label {
            flex: 1;
        }
        .option-hint {
            font-size: 0.85em;
            color: #6c757d;
            margin-left: 28px;
        }
        
        /* Баннер режима */
        .mode-banner {
            padding: 12px 20px;
            text-align: center;
            font-weight: 600;
        }
        .mode-preview {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            color: #8B4513;
        }
        .mode-live {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        
        /* Collapsible */
        .collapsible {
            margin-top: 15px;
        }
        .collapsible summary {
            cursor: pointer;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 6px;
            font-weight: 500;
            list-style: none;
        }
        .collapsible summary::-webkit-details-marker { display: none; }
        .collapsible summary::before {
            content: '▶ ';
            font-size: 0.8em;
        }
        .collapsible[open] summary::before {
            content: '▼ ';
        }
        .collapsible-content {
            padding: 15px;
            border: 1px solid #e9ecef;
            border-top: none;
            border-radius: 0 0 6px 6px;
        }
        
        /* Кнопки действий */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        /* Footer */
        .card-footer {
            background: #f8f9fa;
            padding: 15px 30px;
            text-align: center;
            font-size: 0.9em;
            color: #6c757d;
        }
        
        /* Проблемы требующие внимания */
        .attention-box {
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            border: 2px solid #fc8181;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .attention-box h3 {
            color: #c53030;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .attention-list {
            margin: 0;
            padding-left: 20px;
        }
        .attention-list li {
            margin-bottom: 8px;
        }
        
        /* Информационная панель */
        .info-panel {
            background: #f0f7ff;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .info-panel-icon {
            font-size: 2em;
        }
        .info-panel-text h4 {
            margin: 0 0 5px 0;
            font-size: 1em;
        }
        .info-panel-text p {
            margin: 0;
            font-size: 0.9em;
            color: #666;
        }
        
        /* Индикатор загрузки */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(102, 126, 234, 0.95);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .loading-overlay.active {
            display: flex;
        }
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .loading-text {
            color: white;
            font-size: 1.3em;
            margin-top: 20px;
            font-weight: 500;
        }
        .loading-subtext {
            color: rgba(255,255,255,0.8);
            font-size: 0.95em;
            margin-top: 8px;
        }
        .loading-dots::after {
            content: '';
            animation: dots 1.5s steps(4, end) infinite;
        }
        @keyframes dots {
            0%, 20% { content: ''; }
            40% { content: '.'; }
            60% { content: '..'; }
            80%, 100% { content: '...'; }
        }
        
        /* Привязки услуги */
        .service-placements {
            margin-top: 8px;
            margin-left: 0;
        }
        .placements-toggle {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #6c757d;
            font-size: 0.85em;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            background: #f8f9fa;
            border: none;
            transition: all 0.2s;
        }
        .placements-toggle:hover {
            background: #e9ecef;
            color: #495057;
        }
        .placements-toggle .arrow {
            transition: transform 0.2s;
            font-size: 0.8em;
        }
        .placements-toggle.open .arrow {
            transform: rotate(90deg);
        }
        .placements-content {
            display: none;
            margin-top: 10px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 0.9em;
        }
        .placements-content.open {
            display: block;
        }
        .placement-tab {
            margin-bottom: 10px;
        }
        .placement-tab-name {
            font-weight: 600;
            color: #495057;
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .placement-category {
            margin-left: 20px;
            padding: 4px 0;
            color: #666;
        }
        .placement-page {
            margin-left: 40px;
            padding: 3px 0;
            font-size: 0.9em;
        }
        .placement-page a {
            color: #007bff;
        }
        .placement-status {
            display: inline-block;
            font-size: 0.75em;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 8px;
        }
        .placement-status.visible {
            background: #d4edda;
            color: #155724;
        }
        .placement-status.hidden {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Сводка на главной */
        .status-summary {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .status-grid {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .status-item {
            background: white;
            border-radius: 8px;
            padding: 15px 25px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            min-width: 100px;
        }
        .status-item.warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
        }
        .status-item.muted {
            background: #f8f9fa;
        }
        .status-number {
            font-size: 1.8em;
            font-weight: 700;
            color: #333;
        }
        .status-item.warning .status-number {
            color: #856404;
        }
        .status-item.muted .status-number {
            color: #6c757d;
        }
        .status-label {
            font-size: 0.85em;
            color: #666;
            margin-top: 5px;
        }
        
        /* Дашборд - крупные плашки по центру */
        .dashboard-section {
            text-align: center;
            padding: 30px 0;
        }
        .dashboard-grid {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .dashboard-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 16px;
            padding: 25px 35px;
            min-width: 140px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .dashboard-card.accent {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .dashboard-card.accent .dashboard-label {
            color: rgba(255,255,255,0.9);
        }
        .dashboard-card.muted {
            background: #f1f1f1;
        }
        .dashboard-card.muted .dashboard-number {
            color: #888;
        }
        .dashboard-card.clickable {
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .dashboard-card.clickable:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .dashboard-card.accent.clickable:hover {
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }
        .dashboard-number {
            font-size: 2.8em;
            font-weight: 700;
            line-height: 1;
            color: #333;
        }
        .dashboard-label {
            font-size: 0.95em;
            color: #666;
            margin-top: 8px;
        }
        
        /* Главная секция действий */
        .main-action-section {
            text-align: center;
            padding: 20px 0 30px;
        }
        .main-title {
            font-size: 1.5em;
            font-weight: 600;
            color: #333;
            margin: 0 0 30px 0;
            line-height: 1.4;
        }
        .action-buttons-main {
            margin-bottom: 20px;
        }
        .btn-large {
            padding: 18px 50px;
            font-size: 1.2em;
        }
        .workflow-hint {
            color: #888;
            font-size: 0.9em;
            margin-top: 20px;
        }
        
        /* Разворачивающиеся настройки */
        .settings-toggle-section {
            margin-top: 25px;
        }
        .settings-toggle-btn {
            background: none;
            border: none;
            color: #667eea;
            font-size: 0.95em;
            cursor: pointer;
            padding: 8px 15px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .settings-toggle-btn:hover {
            background: #f0f0f0;
        }
        .toggle-arrow {
            display: inline-block;
            transition: transform 0.2s;
            margin-right: 5px;
        }
        .settings-panel {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            background: #f8f9fa;
            border-radius: 12px;
            margin-top: 10px;
        }
        .settings-panel.open {
            max-height: 300px;
            padding: 20px;
        }
        .setting-item {
            margin-bottom: 15px;
        }
        .setting-item:last-child {
            margin-bottom: 0;
        }
        .setting-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            cursor: pointer;
            text-align: left;
        }
        .setting-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
        }
        .setting-text {
            flex: 1;
        }
        .setting-text strong {
            display: block;
            color: #333;
            margin-bottom: 3px;
        }
        .setting-text small {
            color: #888;
            font-size: 0.85em;
        }
        
        /* Инструменты */
        .tools-section {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }
        .tools-section h3 {
            margin: 0 0 15px 0;
            font-size: 1.1em;
            color: #495057;
        }
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
        }
        .tool-card {
            display: block;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            position: relative;
        }
        .tool-card:hover {
            background: #fff;
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
            text-decoration: none;
        }
        .tool-icon {
            font-size: 2em;
            margin-bottom: 10px;
        }
        .tool-title {
            font-weight: 600;
            font-size: 1.05em;
            color: #333;
            margin-bottom: 5px;
        }
        .tool-desc {
            font-size: 0.9em;
            color: #666;
        }
        .tool-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .tool-badge.warning {
            background: #ffc107;
            color: #333;
        }
        
        /* Страница списка услуг */
        .services-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .services-search {
            display: flex;
            gap: 10px;
            flex: 1;
            max-width: 400px;
        }
        .services-search input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95em;
        }
        .services-search input:focus {
            outline: none;
            border-color: #667eea;
        }
        .group-section {
            margin-bottom: 25px;
        }
        .group-header-bar {
            background: linear-gradient(135deg, #495057 0%, #343a40 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .group-title {
            font-weight: 600;
            font-size: 1.05em;
        }
        .group-hint {
            opacity: 0.8;
            font-size: 0.9em;
            margin-left: 10px;
        }
        .group-count {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9em;
        }
        .services-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 8px 8px;
            overflow: hidden;
        }
        .services-table th {
            background: #f8f9fa;
            padding: 10px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9em;
            color: #495057;
            border-bottom: 1px solid #dee2e6;
        }
        .services-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            font-size: 0.9em;
        }
        .services-table tr:last-child td {
            border-bottom: none;
        }
        .services-table tr:hover {
            background: #f8f9fa;
        }
        .services-table .code {
            font-family: monospace;
            color: #6c757d;
            font-size: 0.85em;
        }
        .services-table .price {
            text-align: right;
            font-weight: 500;
            white-space: nowrap;
        }
        .services-table .price-dash {
            color: #dc3545;
        }
        
        /* Навигация */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #667eea;
            text-decoration: none;
            font-size: 0.95em;
            margin-bottom: 20px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        
        /* Фильтры-вкладки */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .filter-tab {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            text-decoration: none;
            color: #666;
            transition: all 0.2s;
        }
        .filter-tab:hover {
            background: #fff;
            border-color: #667eea;
            color: #667eea;
            text-decoration: none;
        }
        .filter-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: transparent;
            color: white;
        }
        .filter-tab.active .filter-count {
            background: rgba(255,255,255,0.3);
        }
        .filter-icon {
            font-size: 1.2em;
        }
        .filter-label {
            font-weight: 500;
        }
        .filter-count {
            background: #e9ecef;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        /* Панель инструментов */
        .services-toolbar {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .services-toolbar .services-search {
            flex: 1;
            min-width: 200px;
        }
        
        /* Кнопки действий в таблице */
        .action-cell {
            text-align: center;
        }
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.85em;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-action:disabled {
            opacity: 0.5;
            cursor: wait;
        }
        .btn-hide {
            background: #f8f9fa;
            color: #666;
            border: 1px solid #ddd;
        }
        .btn-hide:hover:not(:disabled) {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        .btn-restore {
            background: #e7f3ff;
            color: #0066cc;
            border: 1px solid #b6d4fe;
        }
        .btn-restore:hover:not(:disabled) {
            background: #cce5ff;
        }
        
        /* Скрытые услуги */
        .hidden-service {
            background: #f8f9fa;
        }
        .hidden-service td {
            color: #888;
        }
        
        /* Подсказка */
        .tip-box {
            background: #e7f3ff;
            border: 1px solid #b6d4fe;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 0.9em;
            color: #084298;
        }
        
        /* Пустое состояние */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        .empty-icon {
            font-size: 4em;
            margin-bottom: 15px;
        }
        .empty-title {
            font-size: 1.3em;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }
        .empty-text {
            color: #888;
        }
    </style>
</head>
<body>
<div class="container">
<div class="card">
HTML;

echo $styles;

// Оверлей загрузки
echo <<<HTML
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text" id="loadingText">Загрузка данных<span class="loading-dots"></span></div>
    <div class="loading-subtext" id="loadingSubtext">Получаем актуальные цены из qMS</div>
</div>

<script>
function showLoading(action) {
    var overlay = document.getElementById('loadingOverlay');
    var text = document.getElementById('loadingText');
    var subtext = document.getElementById('loadingSubtext');
    
    if (action === 'apply') {
        text.innerHTML = 'Применяем изменения<span class="loading-dots"></span>';
        subtext.innerHTML = 'Обновляем цены на сайте';
    } else {
        text.innerHTML = 'Загрузка данных<span class="loading-dots"></span>';
        subtext.innerHTML = 'Получаем актуальные цены из qMS';
    }
    
    overlay.classList.add('active');
}

function togglePlacements(btn, id) {
    btn.classList.toggle('open');
    var content = document.getElementById('placements-' + id);
    content.classList.toggle('open');
}
</script>
HTML;

// ============================================================================
// ФУНКЦИИ
// ============================================================================

$htmlReport = $styles;

function echoReport($str) {
    global $htmlReport;
    echo $str;
    $htmlReport .= $str . PHP_EOL;
}

function clear_dir($dir, $rmdir = false) {
    if ($objs = glob($dir . '/*')) {
        foreach ($objs as $obj) {
            is_dir($obj) ? clear_dir($obj, true) : unlink($obj);
        }
    }
    if ($rmdir) rmdir($dir);
}

function safeUpdate($mysqli, $query, $isDemo) {
    if ($isDemo) return true;
    return mysqli_query($mysqli, $query);
}

// ============================================================================
// ЭКРАН 1: СТАРТОВЫЙ
// ============================================================================

if ($action === 'start') {
    // Быстрая проверка статистики для сводки на главной
    $mysqli = mysqli_connect($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['dbname']);
    $quickStats = null;
    
    if ($mysqli) {
        mysqli_set_charset($mysqli, $dbConfig['charset']);
        
        // Количество услуг на сайте
        $res = mysqli_query($mysqli, "SELECT COUNT(DISTINCT doc_id) AS cnt FROM `{$tableConfig['pricelist']}` WHERE doc_id IS NOT NULL AND doc_id <> '' AND published = 1");
        $siteCount = $res ? mysqli_fetch_assoc($res)['cnt'] : 0;
        
        // Получаем данные из qMS для сравнения
        $fields = ['apikey' => $qmsConfig['apikey'], 'unauthorized' => $qmsConfig['unauthorized'], 'qqc244' => $qmsConfig['qqc244']];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $qmsConfig['url'],
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);
        $json = curl_exec($ch);
        curl_close($ch);
        
        $qmsData = json_decode($json);
        if ($qmsData && isset($qmsData->data->sections)) {
            $qmsCount = 0;
            $qmsArticles = [];
            foreach ($qmsData->data->sections as $section) {
                foreach ($section->rows as $row) {
                    $qmsCount++;
                    $qmsArticles[trim($row->Duv)] = true;
                }
            }
            
            // Считаем услуги на сайте
// Считаем услуги на сайте (только опубликованные)
$siteArticles = [];
$res = mysqli_query($mysqli, "SELECT DISTINCT doc_id FROM `{$tableConfig['pricelist']}` WHERE doc_id IS NOT NULL AND doc_id <> '' AND published = 1");
while ($row = mysqli_fetch_assoc($res)) {
    $siteArticles[trim($row['doc_id'])] = true;
}
            
            // Получаем исключения
            ensureExclusionsTable($mysqli, $tableConfig['exclusions']);
            $exclusions = getExclusions($mysqli, $tableConfig['exclusions']);
            
            // Статистика с учётом исключений
            $notOnSite = 0;
            $notOnSiteNew = 0;
            foreach ($qmsArticles as $art => $v) {
                if (!isset($siteArticles[$art])) {
                    $notOnSite++;
                    if (!isset($exclusions[$art])) {
                        $notOnSiteNew++; // Только необработанные
                    }
                }
            }
            
            $notInQms = 0;
            foreach ($siteArticles as $art => $v) {
                if (!isset($qmsArticles[$art])) $notInQms++;
            }
            
            $quickStats = [
                'site'         => count($siteArticles),
                'qms'          => $qmsCount,
                'notOnSite'    => $notOnSite,          // Всего (вкл. скрытые)
                'notOnSiteNew' => $notOnSiteNew,       // Только новые (без скрытых)
                'notInQms'     => $notInQms,
            ];
        }
        
        mysqli_close($mysqli);
    }
    
    echoReport('<div class="card-header" style="text-align: center;">');
    echoReport('<h1 style="font-size: 2em;">💰 Синхронизация цен</h1>');
    echoReport('<p style="font-size: 1.1em; opacity: 0.9;">qMS → Сайт</p>');
    echoReport('</div>');
    
    echoReport('<div class="card-body">');
    
    // Сводка состояния - по центру, крупнее
    if ($quickStats) {
        echoReport('<div class="dashboard-section">');
        echoReport('<div class="dashboard-grid">');
        
        echoReport('<div class="dashboard-card">');
        echoReport('<div class="dashboard-number">' . $quickStats['site'] . '</div>');
        echoReport('<div class="dashboard-label">Услуг на сайте</div>');
        echoReport('</div>');
        
        echoReport('<div class="dashboard-card">');
        echoReport('<div class="dashboard-number">' . $quickStats['qms'] . '</div>');
        echoReport('<div class="dashboard-label">Услуг в qMS</div>');
        echoReport('</div>');
        
        if ($quickStats['notOnSiteNew'] > 0) {
            echoReport('<a href="?action=new_in_qms" class="dashboard-card accent clickable" onclick="showLoading(\'preview\')">');
            echoReport('<div class="dashboard-number">' . $quickStats['notOnSiteNew'] . '</div>');
            echoReport('<div class="dashboard-label">Новых в qMS</div>');
            echoReport('</a>');
        } elseif ($quickStats['notOnSite'] > 0) {
            echoReport('<a href="?action=new_in_qms&filter=hidden" class="dashboard-card clickable" onclick="showLoading(\'preview\')">');
            echoReport('<div class="dashboard-number">✓</div>');
            echoReport('<div class="dashboard-label">Новых нет</div>');
            echoReport('</a>');
        }
        
        if ($quickStats['notInQms'] > 0) {
            echoReport('<a href="?action=not_in_qms" class="dashboard-card muted clickable" onclick="showLoading(\'preview\')">');
            echoReport('<div class="dashboard-number">' . $quickStats['notInQms'] . '</div>');
            echoReport('<div class="dashboard-label">Устаревших</div>');
            echoReport('</a>');
        }
        
        echoReport('</div>'); // dashboard-grid
        echoReport('</div>'); // dashboard-section
    }
    
    // Главный текст
    echoReport('<div class="main-action-section">');
    echoReport('<h2 class="main-title">Сравнить цены на сайте с данными из qMS<br>и обновить при необходимости</h2>');
    
    echoReport('<form method="POST" onsubmit="showLoading(\'preview\')" id="mainForm">');
    
    // Кнопки действий
    echoReport('<div class="action-buttons-main">');
    echoReport('<button type="submit" name="action" value="preview" class="btn btn-primary btn-large">');
    echoReport('🔍 Проверить цены');
    echoReport('</button>');
    echoReport('</div>');
    
    // Разворачивающиеся настройки
    echoReport('<div class="settings-toggle-section">');
    echoReport('<button type="button" class="settings-toggle-btn" onclick="toggleSettings()">');
    echoReport('<span class="toggle-arrow" id="settingsArrow">▶</span> Дополнительные настройки');
    echoReport('</button>');
    
    echoReport('<div class="settings-panel" id="settingsPanel">');
    
    echoReport('<div class="setting-item">');
    echoReport('<label class="setting-checkbox">');
    echoReport('<input type="checkbox" name="update_titles" id="update_titles">');
    echoReport('<span class="checkmark"></span>');
    echoReport('<span class="setting-text">');
    echoReport('<strong>Обновлять названия услуг</strong>');
    echoReport('<small>Если название в qMS отличается от названия на сайте</small>');
    echoReport('</span>');
    echoReport('</label>');
    echoReport('</div>');
    
    echoReport('<div class="setting-item">');
    echoReport('<label class="setting-checkbox">');
    echoReport('<input type="checkbox" name="unpublish_missing" id="unpublish_missing">');
    echoReport('<span class="checkmark"></span>');
    echoReport('<span class="setting-text">');
    echoReport('<strong>Скрывать устаревшие услуги</strong>');
    echoReport('<small>Если услуга удалена из qMS, она будет скрыта на сайте</small>');
    echoReport('</span>');
    echoReport('</label>');
    echoReport('</div>');
    
    echoReport('</div>'); // settings-panel
    echoReport('</div>'); // settings-toggle-section
    
    echoReport('</form>');
    
    echoReport('<p class="workflow-hint">');
    echoReport('💡 Сначала вы увидите все изменения в режиме просмотра, затем сможете применить их одной кнопкой');
    echoReport('</p>');
    
    echoReport('</div>'); // main-action-section
    
    // Дополнительные инструменты
    echoReport('<div class="tools-section">');
    echoReport('<h3>📋 Инструменты</h3>');
    echoReport('<div class="tools-grid">');
    
    echoReport('<a href="?action=new_in_qms" class="tool-card" onclick="showLoading(\'preview\')">');
    echoReport('<div class="tool-icon">🆕</div>');
    echoReport('<div class="tool-title">Новые услуги в qMS</div>');
    echoReport('<div class="tool-desc">Услуги, которые есть в qMS, но ещё не добавлены на сайт</div>');
    if ($quickStats && $quickStats['notOnSiteNew'] > 0) {
        echoReport('<div class="tool-badge">' . $quickStats['notOnSiteNew'] . ' новых</div>');
    } elseif ($quickStats && $quickStats['notOnSite'] > 0) {
        echoReport('<div class="tool-badge" style="background: #6c757d;">' . $quickStats['notOnSite'] . ' скрыто</div>');
    }
    echoReport('</a>');
    
    echoReport('<a href="?action=not_in_qms" class="tool-card" onclick="showLoading(\'preview\')">');
    echoReport('<div class="tool-icon">❓</div>');
    echoReport('<div class="tool-title">Устаревшие услуги</div>');
    echoReport('<div class="tool-desc">Услуги на сайте, которых больше нет в qMS</div>');
    if ($quickStats && $quickStats['notInQms'] > 0) {
        echoReport('<div class="tool-badge warning">' . $quickStats['notInQms'] . '</div>');
    }
    echoReport('</a>');
    
    echoReport('</div>'); // tools-grid
    echoReport('</div>'); // tools-section
    
    echoReport('</div>'); // card-body
    
    echoReport('<div class="card-footer">');
    echoReport('Данные актуальны на ' . date('d.m.Y H:i'));
    echoReport('</div>');
    
    // JavaScript для настроек
    echoReport('<script>');
    echoReport('function toggleSettings() {');
    echoReport('  var panel = document.getElementById("settingsPanel");');
    echoReport('  var arrow = document.getElementById("settingsArrow");');
    echoReport('  if (panel.classList.contains("open")) {');
    echoReport('    panel.classList.remove("open");');
    echoReport('    arrow.textContent = "▶";');
    echoReport('  } else {');
    echoReport('    panel.classList.add("open");');
    echoReport('    arrow.textContent = "▼";');
    echoReport('  }');
    echoReport('}');
    echoReport('</script>');
    
    echoReport('</div></div></body></html>');
    exit;
}

// ============================================================================
// СТРАНИЦА: НОВЫЕ УСЛУГИ В QMS
// ============================================================================

if ($action === 'new_in_qms' || $action === 'not_in_qms') {
    $mysqli = mysqli_connect($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['dbname']);
    if (!$mysqli) {
        die('<div class="alert alert-warning">Ошибка подключения к базе данных</div>');
    }
    mysqli_set_charset($mysqli, $dbConfig['charset']);
    
    // Создаём таблицу исключений если её нет
    ensureExclusionsTable($mysqli, $tableConfig['exclusions']);
    
    // Получаем список исключений
    $exclusions = getExclusions($mysqli, $tableConfig['exclusions']);
    
    // Услуги на сайте
    $siteServices = [];
    $res = mysqli_query($mysqli, "SELECT doc_id, MIN(name) AS name, MIN(price) AS price FROM `{$tableConfig['pricelist']}` WHERE doc_id IS NOT NULL AND doc_id <> '' GROUP BY doc_id");
    while ($row = mysqli_fetch_assoc($res)) {
        $siteServices[trim($row['doc_id'])] = [
            'title' => $row['name'],
            'price' => $row['price'],
        ];
    }
    
    // Услуги из qMS
    $fields = ['apikey' => $qmsConfig['apikey'], 'unauthorized' => $qmsConfig['unauthorized'], 'qqc244' => $qmsConfig['qqc244']];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $qmsConfig['url'],
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => json_encode($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => 0,
    ]);
    $json = curl_exec($ch);
    curl_close($ch);
    
    $qmsServices = [];
    $qmsData = json_decode($json);
    if ($qmsData && isset($qmsData->data->sections)) {
        foreach ($qmsData->data->sections as $section) {
            foreach ($section->rows as $row) {
                $article = trim($row->Duv);
                $price = normalizePrice($row->Mr70);
                $qmsServices[$article] = [
                    'title' => $row->u,
                    'price' => $price,
                ];
            }
        }
    }
    
    // Определяем что показывать
    $isNewInQms = ($action === 'new_in_qms');
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'new'; // new, hidden, all
    
    if ($isNewInQms) {
        $pageTitle = 'Новые услуги в qMS';
        $pageIcon = '🆕';
        
        // Разделяем на новые и скрытые
        $newItems = [];
        $hiddenItems = [];
        
        foreach ($qmsServices as $article => $service) {
            if (!isset($siteServices[$article])) {
                $service['article'] = $article;
                if (isset($exclusions[$article])) {
                    $hiddenItems[$article] = $service;
                } else {
                    $newItems[$article] = $service;
                }
            }
        }
        
        // Выбираем что показывать
        if ($filter === 'hidden') {
            $items = $hiddenItems;
            $pageDesc = 'Услуги, которые вы отметили как "Не публикуем"';
        } elseif ($filter === 'all') {
            $items = array_merge($newItems, $hiddenItems);
            $pageDesc = 'Все услуги из qMS, которых нет на сайте';
        } else {
            $items = $newItems;
            $pageDesc = 'Новые услуги, требующие обработки. Добавьте на сайт или отметьте "Не публикуем".';
        }
        
        $countNew = count($newItems);
        $countHidden = count($hiddenItems);
        $countAll = $countNew + $countHidden;
        
    } else {
        $pageTitle = 'Устаревшие услуги';
        $pageIcon = '❓';
        $pageDesc = 'Услуги, которые есть на сайте, но отсутствуют в qMS. Возможно, они устарели.';
        
        $items = [];
        foreach ($siteServices as $article => $service) {
            if (!isset($qmsServices[$article])) {
                $service['article'] = $article;
                $items[$article] = $service;
            }
        }
        
        $countNew = count($items);
        $countHidden = 0;
        $countAll = $countNew;
    }
    
    // Группировка по префиксу
    $grouped = [];
    foreach ($items as $article => $item) {
        $parts = explode('.', $article);
        $prefix = (count($parts) >= 2) ? $parts[0] . '.' . $parts[1] : 'Другие';
        
        if (!isset($grouped[$prefix])) {
            $grouped[$prefix] = [];
        }
        $grouped[$prefix][] = [
            'article'   => $article,
            'title'     => $item['title'],
            'price'     => $item['price'],
            'isHidden'  => isset($exclusions[$article]),
        ];
    }
    ksort($grouped);
    
    // Вывод
    echoReport('<div class="card-header" style="text-align: center;">');
    echoReport('<h1 style="font-size: 1.8em;">' . $pageIcon . ' ' . $pageTitle . '</h1>');
    echoReport('<p style="opacity: 0.9;">' . $pageDesc . '</p>');
    echoReport('</div>');
    
    echoReport('<div class="card-body">');
    
    echoReport('<a href="?" class="back-link">← Вернуться на главную</a>');
    
    // Фильтры для new_in_qms
    if ($isNewInQms) {
        echoReport('<div class="filter-tabs">');
        
        $activeNew = ($filter === 'new') ? 'active' : '';
        $activeHidden = ($filter === 'hidden') ? 'active' : '';
        $activeAll = ($filter === 'all') ? 'active' : '';
        
        echoReport('<a href="?action=new_in_qms&filter=new" class="filter-tab ' . $activeNew . '">');
        echoReport('<span class="filter-icon">📥</span>');
        echoReport('<span class="filter-label">Необработанные</span>');
        echoReport('<span class="filter-count">' . $countNew . '</span>');
        echoReport('</a>');
        
        echoReport('<a href="?action=new_in_qms&filter=hidden" class="filter-tab ' . $activeHidden . '">');
        echoReport('<span class="filter-icon">🚫</span>');
        echoReport('<span class="filter-label">Скрытые</span>');
        echoReport('<span class="filter-count">' . $countHidden . '</span>');
        echoReport('</a>');
        
        echoReport('<a href="?action=new_in_qms&filter=all" class="filter-tab ' . $activeAll . '">');
        echoReport('<span class="filter-icon">📋</span>');
        echoReport('<span class="filter-label">Все</span>');
        echoReport('<span class="filter-count">' . $countAll . '</span>');
        echoReport('</a>');
        
        echoReport('</div>');
    }
    
    // Панель инструментов
    echoReport('<div class="services-toolbar">');
    echoReport('<div class="services-search">');
    echoReport('<input type="text" id="searchInput" placeholder="Поиск по названию или артикулу..." onkeyup="filterServices()">');
    echoReport('</div>');
    
    if ($isNewInQms && $filter === 'new' && $countNew > 0) {
        echoReport('<button type="button" class="btn btn-sm btn-outline" onclick="hideAllVisible()">');
        echoReport('🚫 Скрыть все видимые');
        echoReport('</button>');
    }
    echoReport('</div>');
    
    // Подсказка
    if ($isNewInQms && $filter === 'new' && $countNew > 0) {
        echoReport('<div class="tip-box">');
        echoReport('💡 <strong>Подсказка:</strong> Нажмите "Не публикуем" рядом с услугой, чтобы скрыть её из этого списка. ');
        echoReport('Скрытые услуги можно посмотреть на вкладке "Скрытые".');
        echoReport('</div>');
    }
    
    if (count($items) === 0) {
        echoReport('<div class="empty-state">');
        if ($filter === 'new') {
            echoReport('<div class="empty-icon">🎉</div>');
            echoReport('<div class="empty-title">Всё обработано!</div>');
            echoReport('<div class="empty-text">Нет новых услуг, требующих внимания</div>');
        } elseif ($filter === 'hidden') {
            echoReport('<div class="empty-icon">📭</div>');
            echoReport('<div class="empty-title">Список пуст</div>');
            echoReport('<div class="empty-text">Вы ещё не скрывали услуги</div>');
        } else {
            echoReport('<div class="empty-icon">✅</div>');
            echoReport('<div class="empty-title">Нет данных</div>');
        }
        echoReport('</div>');
    } else {
        // Список по группам
        foreach ($grouped as $prefix => $items_in_group) {
            usort($items_in_group, function($a, $b) { return strcmp($a['article'], $b['article']); });
            
            $hint = '';
            if (!empty($items_in_group[0]['title'])) {
                $words = preg_split('/\s+/', preg_replace('/\s*\([^)]*\)/', '', $items_in_group[0]['title']));
                $hint = implode(' ', array_slice($words, 0, 3));
                if (mb_strlen($hint) > 35) $hint = mb_substr($hint, 0, 32) . '...';
            }
            
            echoReport('<div class="group-section service-group">');
            echoReport('<div class="group-header-bar">');
            echoReport('<div><span class="group-title">📁 ' . htmlspecialchars($prefix) . '</span>');
            if ($hint) echoReport('<span class="group-hint">— ' . htmlspecialchars($hint) . '</span>');
            echoReport('</div>');
            echoReport('<span class="group-count">' . count($items_in_group) . '</span>');
            echoReport('</div>');
            
            echoReport('<table class="services-table">');
            echoReport('<thead><tr>');
            echoReport('<th style="width: 130px;">Артикул</th>');
            echoReport('<th>Название</th>');
            echoReport('<th style="width: 90px; text-align: right;">Цена</th>');
            if ($isNewInQms) {
                echoReport('<th style="width: 120px; text-align: center;">Действие</th>');
            }
            echoReport('</tr></thead>');
            echoReport('<tbody>');
            
            foreach ($items_in_group as $item) {
                $priceDisplay = ($item['price'] === '-' || $item['price'] === '') 
                    ? '<span class="price-dash">—</span>' 
                    : formatPrice($item['price']);
                
                $rowClass = $item['isHidden'] ? 'service-row hidden-service' : 'service-row';
                $dataAttrs = 'data-search="' . htmlspecialchars(mb_strtolower($item['article'] . ' ' . $item['title'])) . '"';
                $dataAttrs .= ' data-docid="' . htmlspecialchars($item['article']) . '"';
                $dataAttrs .= ' data-title="' . htmlspecialchars($item['title']) . '"';
                $dataAttrs .= ' data-price="' . htmlspecialchars($item['price']) . '"';
                
                echoReport('<tr class="' . $rowClass . '" ' . $dataAttrs . ' id="row-' . htmlspecialchars($item['article']) . '">');
                echoReport('<td class="code">' . htmlspecialchars($item['article']) . '</td>');
                echoReport('<td>' . htmlspecialchars($item['title']) . '</td>');
                echoReport('<td class="price">' . $priceDisplay . '</td>');
                
                if ($isNewInQms) {
                    echoReport('<td class="action-cell">');
                    if ($item['isHidden']) {
                        echoReport('<button type="button" class="btn-action btn-restore" onclick="toggleExclusion(\'' . htmlspecialchars($item['article']) . '\', \'remove\')">');
                        echoReport('↩ Вернуть');
                        echoReport('</button>');
                    } else {
                        echoReport('<button type="button" class="btn-action btn-hide" onclick="toggleExclusion(\'' . htmlspecialchars($item['article']) . '\', \'add\')">');
                        echoReport('🚫 Не публикуем');
                        echoReport('</button>');
                    }
                    echoReport('</td>');
                }
                
                echoReport('</tr>');
            }
            
            echoReport('</tbody></table>');
            echoReport('</div>');
        }
    }
    
    // JavaScript
    echoReport('<script>');
    echoReport('function filterServices() {');
    echoReport('  var query = document.getElementById("searchInput").value.toLowerCase();');
    echoReport('  var rows = document.querySelectorAll(".service-row");');
    echoReport('  var groups = document.querySelectorAll(".service-group");');
    echoReport('  rows.forEach(function(row) {');
    echoReport('    var text = row.getAttribute("data-search");');
    echoReport('    row.style.display = text.includes(query) ? "" : "none";');
    echoReport('  });');
    echoReport('  groups.forEach(function(group) {');
    echoReport('    var visibleRows = group.querySelectorAll(".service-row:not([style*=\"display: none\"])");');
    echoReport('    group.style.display = visibleRows.length > 0 ? "" : "none";');
    echoReport('  });');
    echoReport('}');
    echoReport('');
    echoReport('function toggleExclusion(docId, mode) {');
    echoReport('  var row = document.getElementById("row-" + docId);');
    echoReport('  var title = row.getAttribute("data-title");');
    echoReport('  var price = row.getAttribute("data-price");');
    echoReport('  var btn = row.querySelector(".btn-action");');
    echoReport('  ');
    echoReport('  btn.disabled = true;');
    echoReport('  btn.textContent = "...";');
    echoReport('  ');
    echoReport('  var formData = new FormData();');
    echoReport('  formData.append("action", "toggle_exclusion");');
    echoReport('  formData.append("doc_id", docId);');
    echoReport('  formData.append("title", title);');
    echoReport('  formData.append("price", price);');
    echoReport('  formData.append("mode", mode);');
    echoReport('  ');
    echoReport('  fetch(window.location.pathname, { method: "POST", body: formData })');
    echoReport('    .then(function(response) { return response.json(); })');
    echoReport('    .then(function(data) {');
    echoReport('      if (data.success) {');
    echoReport('        row.style.transition = "opacity 0.3s";');
    echoReport('        row.style.opacity = "0";');
    echoReport('        setTimeout(function() { row.remove(); updateCounts(); }, 300);');
    echoReport('      } else {');
    echoReport('        btn.disabled = false;');
    echoReport('        btn.textContent = mode === "add" ? "🚫 Не публикуем" : "↩ Вернуть";');
    echoReport('        alert("Ошибка сохранения");');
    echoReport('      }');
    echoReport('    })');
    echoReport('    .catch(function() {');
    echoReport('      btn.disabled = false;');
    echoReport('      btn.textContent = mode === "add" ? "🚫 Не публикуем" : "↩ Вернуть";');
    echoReport('      alert("Ошибка сети");');
    echoReport('    });');
    echoReport('}');
    echoReport('');
    echoReport('function updateCounts() {');
    echoReport('  var groups = document.querySelectorAll(".service-group");');
    echoReport('  groups.forEach(function(group) {');
    echoReport('    var rows = group.querySelectorAll(".service-row");');
    echoReport('    var countEl = group.querySelector(".group-count");');
    echoReport('    if (countEl) countEl.textContent = rows.length;');
    echoReport('    if (rows.length === 0) group.style.display = "none";');
    echoReport('  });');
    echoReport('}');
    echoReport('');
    echoReport('function hideAllVisible() {');
    echoReport('  if (!confirm("Скрыть все видимые услуги? Это действие можно отменить на вкладке \\"Скрытые\\".")) return;');
    echoReport('  ');
    echoReport('  var rows = document.querySelectorAll(".service-row:not([style*=\"display: none\"])");');
    echoReport('  var items = [];');
    echoReport('  rows.forEach(function(row) {');
    echoReport('    items.push({');
    echoReport('      doc_id: row.getAttribute("data-docid"),');
    echoReport('      title: row.getAttribute("data-title"),');
    echoReport('      price: row.getAttribute("data-price")');
    echoReport('    });');
    echoReport('  });');
    echoReport('  ');
    echoReport('  if (items.length === 0) return;');
    echoReport('  ');
    echoReport('  var formData = new FormData();');
    echoReport('  formData.append("action", "bulk_exclude");');
    echoReport('  formData.append("doc_ids", JSON.stringify(items));');
    echoReport('  ');
    echoReport('  fetch(window.location.pathname, { method: "POST", body: formData })');
    echoReport('    .then(function(response) { return response.json(); })');
    echoReport('    .then(function(data) {');
    echoReport('      if (data.success) {');
    echoReport('        location.reload();');
    echoReport('      }');
    echoReport('    });');
    echoReport('}');
    echoReport('</script>');
    
    echoReport('</div>'); // card-body
    
    echoReport('<div class="card-footer">');
    echoReport('Данные на ' . date('d.m.Y H:i') . ' · ');
    echoReport('На сайте: ' . count($siteServices) . ' · ');
    echoReport('В qMS: ' . count($qmsServices));
    echoReport('</div>');
    
    echoReport('</div></div></body></html>');
    
    mysqli_close($mysqli);
    exit;
}

// ============================================================================
// ПОДКЛЮЧЕНИЕ К БД И СБОР ДАННЫХ
// ============================================================================

$mysqli = mysqli_connect($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['dbname']);
if (!$mysqli) {
    die('<div class="alert alert-warning">Ошибка подключения к базе данных</div>');
}
mysqli_set_charset($mysqli, $dbConfig['charset']);

// Настройки из формы
$updateTitles = isset($_POST['update_titles']);
$unpublishMissing = isset($_POST['unpublish_missing']);
$isDemo = ($action === 'preview');

// Для режима apply тоже проверяем параметры
if ($action === 'apply') {
    $updateTitles = isset($_POST['update_titles']);
    $unpublishMissing = isset($_POST['unpublish_missing']);
}

// Данные с сайта
$itemsDB = [];
$query = "SELECT doc_id, MIN(name) AS name, MIN(price) AS price, COUNT(*) AS rows_count
          FROM `{$tableConfig['pricelist']}`
          WHERE doc_id IS NOT NULL AND doc_id <> '' AND published = 1
          GROUP BY doc_id";
$data = mysqli_query($mysqli, $query);
while ($row = mysqli_fetch_assoc($data)) {
    $docId = trim($row['doc_id']);
    $itemsDB[$docId] = [
        'title' => $row['name'],
        'price' => $row['price'],
        'rows'  => (int)$row['rows_count'],
    ];
}

// Получаем привязки (Tab → Category → Page) для всех услуг
$placementsByDocId = [];
$placementsQuery = "SELECT 
    i.doc_id,
    i.tab,
    i.category,
    i.resource_id,
    i.published AS item_published,
    r.pagetitle,
    r.alias,
    r.published AS page_published
FROM `{$tableConfig['pricelist']}` i
LEFT JOIN `{$tableConfig['content']}` r ON r.id = i.resource_id
WHERE i.doc_id IS NOT NULL AND i.doc_id <> ''
ORDER BY i.doc_id, i.tab, i.category, r.pagetitle";

$placementsResult = mysqli_query($mysqli, $placementsQuery);
if ($placementsResult) {
    while ($row = mysqli_fetch_assoc($placementsResult)) {
        $docId = $row['doc_id'];
        $tab = $row['tab'] ?: '(без вкладки)';
        $category = $row['category'] ?: '(без категории)';
        
        if (!isset($placementsByDocId[$docId])) {
            $placementsByDocId[$docId] = [];
        }
        if (!isset($placementsByDocId[$docId][$tab])) {
            $placementsByDocId[$docId][$tab] = [];
        }
        if (!isset($placementsByDocId[$docId][$tab][$category])) {
            $placementsByDocId[$docId][$tab][$category] = [];
        }
        
        $placementsByDocId[$docId][$tab][$category][] = [
            'resource_id'    => (int)$row['resource_id'],
            'pagetitle'      => $row['pagetitle'] ?: '—',
            'alias'          => $row['alias'] ?: '',
            'page_published' => (int)$row['page_published'],
            'item_published' => (int)$row['item_published'],
        ];
    }
}

// Функция рендеринга привязок
function renderPlacements($docId, $placements) {
    if (!isset($placements[$docId]) || empty($placements[$docId])) {
        return '';
    }
    
    $tabs = $placements[$docId];
    $totalCount = 0;
    foreach ($tabs as $categories) {
        foreach ($categories as $pages) {
            $totalCount += count($pages);
        }
    }
    
    $safeId = preg_replace('/[^a-zA-Z0-9]/', '_', $docId);
    
    $html = '<div class="service-placements">';
    $html .= '<button type="button" class="placements-toggle" onclick="togglePlacements(this, \'' . $safeId . '\')">';
    $html .= '<span class="arrow">▶</span> Где на сайте (' . $totalCount . ')';
    $html .= '</button>';
    
    $html .= '<div class="placements-content" id="placements-' . $safeId . '">';
    
    foreach ($tabs as $tab => $categories) {
        $html .= '<div class="placement-tab">';
        $html .= '<div class="placement-tab-name">📁 ' . htmlspecialchars($tab) . '</div>';
        
        foreach ($categories as $category => $pages) {
            $html .= '<div class="placement-category">📂 ' . htmlspecialchars($category) . '</div>';
            
            foreach ($pages as $page) {
                $html .= '<div class="placement-page">';
                
                if ($page['resource_id'] > 0) {
                    $url = "https://cispb.com/manager/?a=resource/update&id={$page['resource_id']}";
                    $html .= '<a href="' . $url . '" target="_blank">' . htmlspecialchars($page['pagetitle']) . '</a>';
                    if ($page['alias']) {
                        $html .= ' <span style="color: #999;">/' . htmlspecialchars($page['alias']) . '</span>';
                    }
                } else {
                    $html .= '<span style="color: #999;">Общая привязка</span>';
                }
                
                // Статус видимости
                $isVisible = $page['page_published'] && $page['item_published'];
                if ($isVisible) {
                    $html .= '<span class="placement-status visible">✓ видна</span>';
                } else {
                    $reason = !$page['page_published'] ? 'страница скрыта' : 'услуга скрыта';
                    $html .= '<span class="placement-status hidden">' . $reason . '</span>';
                }
                
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div></div>';
    
    return $html;
}

// Данные из qMS
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
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$json = curl_exec($ch);
curl_close($ch);

$qmsData = json_decode($json);
if (!$qmsData || !isset($qmsData->data->sections)) {
    die('<div class="alert alert-warning">Не удалось получить данные из qMS</div>');
}

$itemsQMS = [];
foreach ($qmsData->data->sections as $section) {
    foreach ($section->rows as $row) {
        $article = trim($row->Duv);
        $itemsQMS[$article] = [
            'price' => normalizePrice($row->Mr70),
            'title' => $row->u,
        ];
    }
}

// ============================================================================
// АНАЛИЗ ИЗМЕНЕНИЙ
// ============================================================================

$priceChanges = [];      // Изменения цен
$titleChanges = [];      // Изменения названий
$toUnpublish = [];       // Снять с публикации
$notOnSite = [];         // Нет на сайте
$matched = 0;            // Совпадают

foreach ($itemsDB as $docId => $dbItem) {
    if (!isset($itemsQMS[$docId])) {
        // Нет в qMS
        $toUnpublish[] = [
            'docId' => $docId,
            'title' => $dbItem['title'],
            'price' => $dbItem['price'],
            'rows'  => $dbItem['rows'],
        ];
        continue;
    }
    
    
    
    $qmsItem = $itemsQMS[$docId];
    $dbPrice = normalizePrice($dbItem['price']);
    $qmsPrice = $qmsItem['price'];
    
    // Проверка цены
    if ($dbPrice !== $qmsPrice) {
        $priceChanges[] = [
            'docId'    => $docId,
            'title'    => $dbItem['title'],
            'oldPrice' => $dbPrice,
            'newPrice' => $qmsPrice,
            'rows'     => $dbItem['rows'],
        ];
        
        // Применяем изменение
        if (!$isDemo && $qmsPrice !== '-') {
            safeUpdate($mysqli, 
                "UPDATE `{$tableConfig['pricelist']}` SET `price` = '" . mysqli_real_escape_string($mysqli, $qmsPrice) . "' WHERE `doc_id` = '" . mysqli_real_escape_string($mysqli, $docId) . "'",
                $isDemo
            );
        }
        if (!$isDemo && $qmsPrice === '-') {
            safeUpdate($mysqli,
                "UPDATE `{$tableConfig['pricelist']}` SET `published` = 0 WHERE `doc_id` = '" . mysqli_real_escape_string($mysqli, $docId) . "'",
                $isDemo
            );
        }
    } else {
        $matched++;
    }
    
    // Проверка названия
    if ($updateTitles && $dbItem['title'] !== $qmsItem['title']) {
        $titleChanges[] = [
            'docId'    => $docId,
            'oldTitle' => $dbItem['title'],
            'newTitle' => $qmsItem['title'],
        ];
        if (!$isDemo) {
            safeUpdate($mysqli,
                "UPDATE `{$tableConfig['pricelist']}` SET `name` = '" . mysqli_real_escape_string($mysqli, $qmsItem['title']) . "' WHERE `doc_id` = '" . mysqli_real_escape_string($mysqli, $docId) . "'",
                $isDemo
            );
        }
    }
}

// Услуги в qMS, но нет на сайте
foreach ($itemsQMS as $article => $qmsItem) {
    if (!isset($itemsDB[$article])) {
        $notOnSite[] = [
            'docId' => $article,
            'title' => $qmsItem['title'],
            'price' => $qmsItem['price'],
        ];
    }
}


// Снятие с публикации (выполняется если параметр включен)
if ($unpublishMissing && !$isDemo) {
    $unpublished_count = 0;
    foreach ($toUnpublish as $item) {
        $docId = trim($item['docId']);
        
        // Защита: снимаем только если doc_id не пустой
        if ($docId !== '') {
            $result = mysqli_query($mysqli,
                "UPDATE `{$tableConfig['pricelist']}` 
                 SET `published` = 0 
                 WHERE `doc_id` = '" . mysqli_real_escape_string($mysqli, $docId) . "' 
                 AND doc_id IS NOT NULL 
                 AND doc_id <> ''"
            );
            
            if ($result) {
                $unpublished_count += mysqli_affected_rows($mysqli);
            }
        }
    }
    
    // Логируем результат
    error_log("Снято с публикации строк: " . $unpublished_count);
}

// Очистка кэша
if (!$isDemo) {
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/core/cache';
    if (is_dir($dir)) clear_dir($dir);
}

// ============================================================================
// ВЫВОД ОТЧЁТА
// ============================================================================

echoReport('<div class="card-header">');
echoReport('<h1>💰 ' . ($isDemo ? 'Предварительный просмотр' : 'Результаты обновления') . '</h1>');
echoReport('<p>Сравнение цен на сайте с данными qMS</p>');
echoReport('</div>');

// Баннер режима
if ($isDemo) {
    echoReport('<div class="mode-banner mode-preview">');
    echoReport('👁 Режим просмотра — изменения ещё не применены');
    echoReport('</div>');
} else {
    echoReport('<div class="mode-banner mode-live">');
    echoReport('✅ Изменения успешно применены');
    echoReport('</div>');
}

echoReport('<div class="card-body">');

// Статистика крупными цифрами
echoReport('<div class="stats-grid">');

echoReport('<div class="stat-card highlight">');
echoReport('<div class="stat-number">' . count($priceChanges) . '</div>');
echoReport('<div class="stat-label">' . ($isDemo ? 'Цен к обновлению' : 'Цен обновлено') . '</div>');
echoReport('</div>');

if ($unpublishMissing && count($toUnpublish) > 0) {
    echoReport('<div class="stat-card warning">');
    echoReport('<div class="stat-number">' . count($toUnpublish) . '</div>');
    echoReport('<div class="stat-label">' . ($isDemo ? 'К снятию с публикации' : 'Снято с публикации') . '</div>');
    echoReport('</div>');
}

echoReport('<div class="stat-card success">');
echoReport('<div class="stat-number">' . $matched . '</div>');
echoReport('<div class="stat-label">Цены актуальны</div>');
echoReport('</div>');

if (count($notOnSite) > 0) {
    echoReport('<div class="stat-card warning">');
    echoReport('<div class="stat-number">' . count($notOnSite) . '</div>');
    echoReport('<div class="stat-label">Новых в qMS</div>');
    echoReport('</div>');
}

echoReport('<div class="stat-card">');
echoReport('<div class="stat-number">' . count($itemsDB) . '</div>');
echoReport('<div class="stat-label">Всего на сайте</div>');
echoReport('</div>');

echoReport('</div>'); // stats-grid

// Проблемы, требующие внимания
$hasAttention = count($notOnSite) > 0 || count($toUnpublish) > 0;

if ($hasAttention) {
    echoReport('<div class="attention-box">');
    echoReport('<h3>⚠️ Требует внимания</h3>');
    echoReport('<ul class="attention-list">');
    
    if (count($notOnSite) > 0) {
        echoReport('<li><strong>' . count($notOnSite) . ' услуг</strong> есть в qMS, но нет на сайте — нужно добавить вручную</li>');
    }
    if (count($toUnpublish) > 0) {
        echoReport('<li><strong>' . count($toUnpublish) . ' услуг</strong> есть на сайте, но нет в qMS — возможно, устарели</li>');
    }
    
    echoReport('</ul>');
    echoReport('</div>');
}

// Таблица изменений цен
if (count($priceChanges) > 0) {
    echoReport('<div class="section">');
    echoReport('<div class="section-title">');
    echoReport('💰 Изменения цен <span class="badge">' . count($priceChanges) . '</span>');
    echoReport('</div>');
    
    echoReport('<table class="changes-table">');
    echoReport('<thead><tr>');
    echoReport('<th>Услуга</th>');
    echoReport('<th style="text-align: right; width: 200px;">Изменение цены</th>');
    echoReport('</tr></thead>');
    echoReport('<tbody>');
    
    foreach ($priceChanges as $change) {
        echoReport('<tr>');
        echoReport('<td class="service-name">');
        echoReport('<div>' . htmlspecialchars($change['title']) . '</div>');
        echoReport('<div class="service-code">' . htmlspecialchars($change['docId']) . '</div>');
        // Добавляем привязки
        echoReport(renderPlacements($change['docId'], $placementsByDocId));
        echoReport('</td>');
        echoReport('<td style="text-align: right; vertical-align: top; padding-top: 15px;">');
        
        if ($change['newPrice'] === '-') {
            echoReport('<span class="price-old">' . formatPrice($change['oldPrice']) . '</span>');
            echoReport('<span class="price-arrow">→</span>');
            echoReport('<span style="color: #dc3545; font-weight: 600;">Скрыта</span>');
        } else {
            echoReport('<span class="price-old">' . formatPrice($change['oldPrice']) . '</span>');
            echoReport('<span class="price-arrow">→</span>');
            echoReport('<span class="price-new">' . formatPrice($change['newPrice']) . '</span>');
        }
        
        echoReport('</td>');
        echoReport('</tr>');
    }
    
    echoReport('</tbody></table>');
    echoReport('</div>');
}

// Таблица изменений названий
if (count($toUnpublish) > 0) {
    $statusText = $unpublishMissing 
        ? ($isDemo ? '⚠️ Будут сняты с публикации' : '✅ Сняты с публикации') 
        : '❓ На сайте, но нет в qMS';
    
    echoReport('<div class="section">');
    echoReport('<details class="collapsible" ' . ($unpublishMissing ? 'open' : '') . '>');
    echoReport('<summary>' . $statusText . ' (' . count($toUnpublish) . ')</summary>');
    echoReport('<div class="collapsible-content">');
    
    echoReport('<table class="changes-table">');
    echoReport('<thead><tr>');
    echoReport('<th style="width: 150px;">Артикул</th>');
    echoReport('<th>Название</th>');
    echoReport('<th style="width: 120px; text-align: right;">Цена</th>');
    echoReport('</tr></thead>');
    echoReport('<tbody>');
    
    $shown = 0;
    foreach ($toUnpublish as $item) {
        if ($shown >= 50) {
            echoReport('<tr><td colspan="3" style="text-align: center; color: #6c757d; padding: 15px;">... и ещё ' . (count($toUnpublish) - 50) . ' услуг</td></tr>');
            break;
        }
        
        $priceDisplay = formatPrice($item['price']);
        $titleDisplay = $item['title'] ? htmlspecialchars($item['title']) : '<span style="color: #999;">Без названия</span>';
        
        echoReport('<tr>');
        echoReport('<td class="service-code">' . htmlspecialchars($item['docId']) . '</td>');
        echoReport('<td>');
        echoReport($titleDisplay);
        
        // Привязки
        $placements = renderPlacements($item['docId'], $placementsByDocId);
        if ($placements) {
            echoReport($placements);
        }
        
        echoReport('</td>');
        echoReport('<td style="text-align: right; white-space: nowrap;">' . $priceDisplay . '</td>');
        echoReport('</tr>');
        $shown++;
    }
    
    // Если массив пустой (на всякий случай)
    if ($shown === 0) {
        echoReport('<tr><td colspan="3" style="text-align: center; padding: 20px; color: #999;">Нет данных</td></tr>');
    }
    
    echoReport('</tbody></table>');
    echoReport('</div>'); // collapsible-content
    echoReport('</details>');
    echoReport('</div>'); // section
}

// Новые услуги в qMS
if (count($notOnSite) > 0) {
    echoReport('<details class="collapsible">');
    echoReport('<summary>🆕 Новые услуги в qMS (' . count($notOnSite) . ')</summary>');
    echoReport('<div class="collapsible-content">');
    echoReport('<table class="changes-table">');
    echoReport('<thead><tr><th>Артикул</th><th>Название</th><th style="text-align: right;">Цена</th></tr></thead><tbody>');
    
    $shown = 0;
    foreach ($notOnSite as $item) {
        if ($shown >= 50) {
            echoReport('<tr><td colspan="3" style="text-align: center; color: #6c757d;">... и ещё ' . (count($notOnSite) - 50) . '</td></tr>');
            break;
        }
        echoReport('<tr>');
        echoReport('<td><code>' . htmlspecialchars($item['docId']) . '</code></td>');
        echoReport('<td>' . htmlspecialchars($item['title']) . '</td>');
        echoReport('<td style="text-align: right;">' . formatPrice($item['price']) . '</td>');
        echoReport('</tr>');
        $shown++;
    }
    
    echoReport('</tbody></table>');
    echoReport('</div></details>');
}

// Устаревшие услуги
if (count($toUnpublish) > 0) {
    echoReport('<details class="collapsible">');
    echoReport('<summary>❓ На сайте, но нет в qMS (' . count($toUnpublish) . ')</summary>');
    echoReport('<div class="collapsible-content">');
    echoReport('<table class="changes-table">');
    echoReport('<thead><tr><th>Услуга</th><th style="text-align: right; width: 120px;">Цена</th></tr></thead><tbody>');
    
  foreach ($toUnpublish as $item) {
    if ($shown >= 50) {  // Увеличил лимит до 50
        echoReport('<tr><td colspan="2" style="text-align: center; color: #6c757d; padding: 15px;">... и ещё ' . (count($toUnpublish) - 50) . ' услуг</td></tr>');
        break;
    }
    
    $priceDisplay = formatPrice($item['price']);
    $titleDisplay = $item['title'] ? htmlspecialchars($item['title']) : '<span style="color: #999;">Без названия</span>';
    
    echoReport('<tr>');
    echoReport('<td style="vertical-align: top; padding-top: 12px;">');
    echoReport('<div>' . $titleDisplay . '</div>');
    echoReport('<div class="service-code">' . htmlspecialchars($item['docId']) . '</div>');
    
    // Привязки только если есть
    $placements = renderPlacements($item['docId'], $placementsByDocId);
    if ($placements) {
        echoReport($placements);
    }
    
    echoReport('</td>');
    echoReport('<td style="text-align: right; vertical-align: top; padding-top: 12px; white-space: nowrap;">' . $priceDisplay . '</td>');
    echoReport('</tr>');
    $shown++;
}

// Если ничего не вывели - сообщение
if ($shown === 0) {
    echoReport('<tr><td colspan="2" style="text-align: center; padding: 20px; color: #999;">Нет данных для отображения</td></tr>');
}
    
    echoReport('</tbody></table>');
    echoReport('</div></details>');
}

// Кнопки действий
echoReport('<div class="action-buttons">');

// Показываем кнопку если есть ЧТО применять
$hasChanges = count($priceChanges) > 0 || ($unpublishMissing && count($toUnpublish) > 0) || count($titleChanges) > 0;

if ($isDemo && $hasChanges) {
    echoReport('<form method="POST" style="display: inline;" onsubmit="showLoading(\'apply\')">');
    echoReport('<input type="hidden" name="action" value="apply">');
    if ($updateTitles) echoReport('<input type="hidden" name="update_titles" value="1">');
    if ($unpublishMissing) echoReport('<input type="hidden" name="unpublish_missing" value="1">');
    
    // Текст кнопки в зависимости от того что будет применено
    $buttonText = '✅ Применить изменения (';
    $parts = [];
    if (count($priceChanges) > 0) $parts[] = count($priceChanges) . ' цен';
    if ($unpublishMissing && count($toUnpublish) > 0) $parts[] = count($toUnpublish) . ' снять';
    if (count($titleChanges) > 0) $parts[] = count($titleChanges) . ' названий';
    $buttonText .= implode(', ', $parts) . ')';
    
    echoReport('<button type="submit" class="btn btn-success">');
    echoReport($buttonText);
    echoReport('</button>');
    echoReport('</form>');
}

echoReport('<a href="' . strtok($_SERVER['REQUEST_URI'], '?') . '" class="btn btn-outline">');
echoReport($isDemo ? '← Назад' : '🔄 Проверить снова');
echoReport('</a>');

echoReport('</div>');

echoReport('</div>'); // card-body

// Footer с инфо
echoReport('<div class="card-footer">');
echoReport('Проверено: ' . date('d.m.Y H:i') . ' · ');
echoReport('На сайте: ' . count($itemsDB) . ' услуг · ');
echoReport('В qMS: ' . count($itemsQMS) . ' услуг');
echoReport('</div>');

echoReport('</div></div></body></html>');

// Сохранение отчёта
if (!$isDemo) {
    if (!is_dir('reports')) mkdir('reports', 0755, true);
    $path = 'reports/' . date('Y-m-d_H-i-s') . '_report.html';
    file_put_contents($path, $htmlReport);
    
    // Email
    if ($emailConfig['enabled'] && count($priceChanges) > 0) {
        $subject = "Обновлено " . count($priceChanges) . " цен на сайте";
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $path;
        
        foreach ($emailConfig['recipients'] as $recipient) {
            mail($recipient, $subject, "Отчёт: " . $url);
        }
    }
}

// JSON для отладки
file_put_contents('json.html', '<pre>' . json_encode(json_decode($json), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>');

mysqli_close($mysqli);