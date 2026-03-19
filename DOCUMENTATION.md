# 📘 Документация: Скрипт синхронизации цен qMS → Сайт

## 🎯 Назначение

Скрипт автоматически синхронизирует цены медицинских услуг между системой qMS (учётная система) и сайтом на MODX. Позволяет:

- Обновлять цены на сайте из qMS
- Снимать с публикации устаревшие услуги (которых больше нет в qMS)
- Обновлять названия услуг
- Управлять списком услуг, которые намеренно не публикуются
- Работать в ручном режиме (через веб-интерфейс) и автоматическом (cron)

---

## 🗄️ Архитектура данных

### База данных MODX

**Основная таблица:** `modx_pricelist_items2`

Структура строки:
```sql
id INT PRIMARY KEY
doc_id VARCHAR(50)         -- Артикул услуги (например: "A02.01.011")
name VARCHAR(500)          -- Название услуги
price VARCHAR(50)          -- Цена (может быть "-" для скрытых)
published TINYINT(1)       -- 0 = снято с публикации, 1 = опубликовано
resource_id INT            -- ID страницы MODX где показывается услуга
tab VARCHAR(255)           -- Вкладка на странице
category VARCHAR(255)      -- Категория
```

**Важно:** Одна услуга (doc_id) может иметь **несколько строк** в таблице, если она привязана к разным страницам или категориям.

**Таблица исключений:** `modx_qms_exclusions`

Хранит услуги, которые администратор намеренно скрыл (не публикуем):
```sql
doc_id VARCHAR(50) PRIMARY KEY
title VARCHAR(500)
price VARCHAR(50)
created_at DATETIME
comment VARCHAR(255)
```

**Таблица страниц:** `modx_site_content`
```sql
id INT PRIMARY KEY
pagetitle VARCHAR(255)
alias VARCHAR(255)
published TINYINT(1)
```

### API qMS

**Endpoint:** `https://back.cispb.ru/qms-api/getPr`

**Метод:** POST

**Аутентификация:** Через параметры в JSON:
```json
{
  "apikey": "86g4njnrWN6M8xTCsAfaBstR",
  "unauthorized": 1,
  "qqc244": "ТAIdAA]AFAA"
}
```

**Ответ:**
```json
{
  "data": {
    "sections": [
      {
        "rows": [
          {
            "Duv": "A02.01.011",    // Артикул
            "u": "Название услуги",  // Название
            "Mr70": "2475"          // Цена (или "-" если скрыта)
          }
        ]
      }
    ]
  }
}
```

---

## 🔄 Логика работы

### Режим 1: Ручная синхронизация (веб-интерфейс)

**Шаг 1: Главная страница (`action=start`)**

1. Подключается к БД
2. Делает быстрый запрос в qMS
3. Считает статистику:
   - Сколько услуг на сайте (опубликованных)
   - Сколько в qMS
   - Сколько новых в qMS (есть в qMS, но нет на сайте)
   - Сколько устаревших (есть на сайте, но нет в qMS)
4. Показывает кнопку "Проверить цены"

**Шаг 2: Предварительный просмотр (`action=preview`)**

Параметры из формы:
- `update_titles` — обновлять ли названия услуг
- `unpublish_missing` — снимать ли с публикации устаревшие услуги

Алгоритм:
```
1. Получить с сайта: список опубликованных услуг
   SQL: SELECT doc_id, name, price 
        FROM modx_pricelist_items2 
        WHERE published = 1 AND doc_id IS NOT NULL
        GROUP BY doc_id

2. Получить из qMS: список всех услуг (API запрос)

3. Сравнить:
   
   ДЛЯ КАЖДОЙ услуги на сайте:
     ЕСЛИ её нет в qMS:
       → Добавить в список toUnpublish[]
       → ПРОПУСТИТЬ дальнейшие проверки (continue)
     
     ЕСЛИ цена отличается:
       → Добавить в список priceChanges[]
     
     ЕСЛИ update_titles=true И название отличается:
       → Добавить в список titleChanges[]

4. Показать результаты:
   - Сколько цен изменится
   - Сколько услуг будет снято (если unpublish_missing=true)
   - Список изменений
   - Кнопка "Применить изменения"
```

**Шаг 3: Применение (`action=apply`)**

Те же параметры + те же проверки, но:
- `$isDemo = false`
- Выполняются реальные UPDATE запросы:

```php
// Обновление цен
UPDATE modx_pricelist_items2 
SET price = 'новая_цена' 
WHERE doc_id = 'артикул'

// Снятие с публикации
UPDATE modx_pricelist_items2 
SET published = 0 
WHERE doc_id = 'артикул' 
  AND doc_id IS NOT NULL 
  AND doc_id <> ''

// Очистка кэша MODX
Удаление всех файлов из /core/cache/
```

### Режим 2: CRON (`?cron=1`)

**URL для запуска:**
```bash
# Только обновление цен
https://example.com/sync.php?cron=1

# С снятием устаревших
https://example.com/sync.php?cron=1&unpublish=1
```

**Отличия от ручного режима:**
- Нет HTML вывода
- Создаёт текстовый лог
- Отправляет email если были изменения
- Не показывает preview — сразу применяет

**Лог-файл не создаётся**, но лог выводится в stdout:
```
[2026-03-19 12:00:00] Запуск синхронизации цен (cron)
На сайте: 582 услуг
В qMS: 2043 услуг
  Цена: A02.01.011: 2475 → 2500
  Скрыто: B01.024.12 (Прием врача)
---
Обновлено цен: 5
Скрыто услуг: 2
[2026-03-19 12:00:15] Завершено
```

---

## 🚨 Критические особенности

### 1. **Множественные строки для одной услуги**

Одна услуга (doc_id) может быть в таблице **несколько раз**:
```
doc_id      | resource_id | tab        | category
------------|-------------|------------|-------------
A02.01.011  | 217         | Взрослым   | Консультации
A02.01.011  | 217         | Детям      | Консультации
```

**При снятии с публикации** скрипт снимает **ВСЕ** строки с этим doc_id:
```sql
UPDATE modx_pricelist_items2 
SET published = 0 
WHERE doc_id = 'A02.01.011'  -- Затронет обе строки!
```

### 2. **Защита от пустых doc_id**

**КРИТИЧНО:** Запрос без WHERE может снять ВСЁ:
```sql
-- ОПАСНО! Если doc_id пустой:
UPDATE modx_pricelist_items2 
SET published = 0 
WHERE doc_id = ''  -- Снимет ВСЕ услуги без doc_id!
```

**Защита в коде:**
```php
$docId = trim($item['docId']);
if ($docId !== '') {  // Проверка!
    mysqli_query($mysqli,
        "UPDATE ... WHERE doc_id = '$docId' 
         AND doc_id IS NOT NULL 
         AND doc_id <> ''"
    );
}
```

### 3. **Фильтр published=1**

При сборе данных с сайта **ОБЯЗАТЕЛЬНО** фильтровать по `published = 1`:
```sql
-- ПРАВИЛЬНО:
SELECT doc_id FROM modx_pricelist_items2 
WHERE doc_id IS NOT NULL 
  AND doc_id <> '' 
  AND published = 1  -- ← Это важно!

-- НЕПРАВИЛЬНО (увидит и снятые услуги):
SELECT doc_id FROM modx_pricelist_items2 
WHERE doc_id IS NOT NULL 
  AND doc_id <> ''
```

Это нужно делать в **двух местах**:
1. Главная страница (`action=start`) — быстрая статистика
2. Страница preview/apply — основная логика

### 4. **Нормализация цен**

Функция `normalizePrice()` удаляет `.00` и `,00`:
```php
"2475.00" → "2475"
"2475,0"  → "2475"
"2475"    → "2475"
"-"       → "-"
```

**Важно:** Сравнивать нужно ПОСЛЕ нормализации:
```php
$dbPrice = normalizePrice($dbItem['price']);    // "2475"
$qmsPrice = normalizePrice($qmsItem['price']);  // "2475"

if ($dbPrice !== $qmsPrice) {  // Строгое сравнение!
    // Цены отличаются
}
```

---

## 📂 Структура кода

### Основные секции

```
1. Защита паролем (строки 1-50)
   └─ Сессия, форма входа

2. Настройки (строки 51-100)
   └─ $dbConfig, $qmsConfig, $emailConfig, $tableConfig

3. AJAX-обработчики (строки 101-180)
   ├─ toggle_exclusion (добавить/убрать услугу из исключений)
   └─ bulk_exclude (массовое добавление в исключения)

4. Режим CRON (строки 181-340)
   └─ Автоматическая синхронизация

5. Вспомогательные функции (строки 341-400)
   ├─ normalizePrice()
   ├─ formatPrice()
   ├─ ensureExclusionsTable()
   ├─ addExclusion()
   ├─ removeExclusion()
   └─ getExclusions()

6. Стили CSS (строки 401-1200)
   └─ Весь UI-дизайн

7. Экран: Главная (строки 1201-1400)
   └─ action=start

8. Экран: Новые/Устаревшие услуги (строки 1401-1700)
   └─ action=new_in_qms, action=not_in_qms

9. Основная логика (строки 1701-2100)
   ├─ Подключение к БД
   ├─ Запрос в qMS
   ├─ Сравнение
   └─ Применение изменений

10. Вывод отчёта (строки 2101-2500)
    └─ HTML-таблицы с результатами
```

### Ключевые функции

**`renderPlacements($docId, $placements)`**
- Показывает где на сайте находится услуга
- Вкладки → Категории → Страницы
- С кнопкой "Где на сайте (N)"

**`echoReport($str)`**
- Выводит HTML + сохраняет в переменную `$htmlReport`
- Используется для генерации отчёта

**`clear_dir($dir, $rmdir)`**
- Рекурсивно удаляет содержимое папки
- Используется для очистки кэша MODX

---

## 🛠️ Частые задачи

### Добавить новое поле из qMS

1. В API ответе есть новое поле, например `category`:
```json
{
  "Duv": "A02.01.011",
  "u": "Название",
  "Mr70": "2475",
  "category": "Консультации"  // ← Новое
}
```

2. Добавить в массив при получении данных (строка ~1900):
```php
$itemsQMS[$article] = [
    'price' => normalizePrice($row->Mr70),
    'title' => $row->u,
    'category' => $row->category,  // ← Добавить
];
```

3. Использовать при выводе или обновлении

### Изменить лимит показа услуг

В коде есть проверки типа:
```php
if ($shown >= 50) {
    echoReport('... и ещё ' . (count($items) - 50) . ' услуг');
    break;
}
```

Измените `50` на нужное значение.

### Добавить фильтрацию по категории

В SQL запросе добавить WHERE:
```php
$query = "SELECT doc_id, name, price 
          FROM modx_pricelist_items2 
          WHERE published = 1 
            AND doc_id IS NOT NULL 
            AND category = 'Консультации'  // ← Добавить
          GROUP BY doc_id";
```

### Изменить email-уведомления

В секции Email (строка ~2450):
```php
if ($emailConfig['enabled'] && count($priceChanges) > 0) {
    $subject = "Обновлено " . count($priceChanges) . " цен";
    
    // Изменить тему письма
    $subject = "🔔 [qMS Sync] " . $subject;
    
    // Добавить детали в тело
    $body = "Детальный отчёт: " . $url . "\n\n";
    $body .= "Изменения:\n";
    foreach ($priceChanges as $change) {
        $body .= "- {$change['docId']}: {$change['oldPrice']} → {$change['newPrice']}\n";
    }
    
    mail($recipient, $subject, $body);
}
```

---

## 🐛 Диагностика проблем

### Проблема: "Услуги не снимаются с публикации"

**Проверка 1:** Включена ли галочка?
```
Дополнительные настройки → 
☑ Скрывать устаревшие услуги
```

**Проверка 2:** В preview есть розовая плашка?
```
Должна быть плашка:
"97 К снятию с публикации"
```

**Проверка 3:** В SQL есть affected rows?
```php
$result = mysqli_query($mysqli, "UPDATE ...");
$count = mysqli_affected_rows($mysqli);
error_log("Снято строк: " . $count);
```

**Проверка 4:** doc_id не пустой?
```php
if ($docId === '') {
    error_log("Пустой doc_id!");
}
```

### Проблема: "Статистика на главной не совпадает с preview"

**Причина:** На главной НЕТ фильтра `published = 1`

**Исправление:** Добавить в SQL (строка ~418, ~420):
```sql
WHERE doc_id IS NOT NULL 
  AND doc_id <> '' 
  AND published = 1  -- ← Добавить
```

### Проблема: "Снялись ВСЕ услуги"

**Причина:** doc_id был пустой или содержал пробел

**Что произошло:**
```sql
UPDATE modx_pricelist_items2 
SET published = 0 
WHERE doc_id = ''  -- Снимает все услуги БЕЗ doc_id!
```

**Восстановление:**
```sql
UPDATE modx_pricelist_items2 SET published = 1;
```

**Предотвращение:** Уже добавлена защита в коде (строка ~2200):
```php
if ($docId !== '') {  // Проверка
    // UPDATE ...
}
```

### Проблема: "qMS не отвечает"

**Проверка:**
```bash
curl -X POST https://back.cispb.ru/qms-api/getPr \
  -H "Content-Type: application/json" \
  -d '{"apikey":"86g4njnrWN6M8xTCsAfaBstR","unauthorized":1,"qqc244":"ТAIdAA]AFAA"}'
```

**В коде:**
```php
$curlError = curl_error($ch);
if ($curlError) {
    error_log("cURL Error: " . $curlError);
}
```

---

## 🔐 Безопасность

### Аутентификация

**Пароль:** Хранится в переменной `$secretPassword` (строка ~15)

**Сессия:** `$_SESSION['price_sync_auth'] = true`

**Выход:** `?logout` в URL

### SQL-инъекции

**Все переменные экранируются:**
```php
$docId = mysqli_real_escape_string($mysqli, $docId);
```

**НО:** Используется конкатенация строк, а не prepared statements. Для усиления безопасности рекомендуется переписать на PDO:
```php
$stmt = $pdo->prepare("UPDATE modx_pricelist_items2 SET published = 0 WHERE doc_id = ?");
$stmt->execute([$docId]);
```

### XSS-защита

**Все выводы экранируются:**
```php
htmlspecialchars($item['title'])
```

---

## 📊 Структура данных в процессе работы

### Массивы в памяти

**$itemsDB** — Услуги с сайта:
```php
[
    'A02.01.011' => [
        'title' => 'Помощь при укусе клеща',
        'price' => '2475',
        'rows'  => 2  // Количество строк в БД
    ]
]
```

**$itemsQMS** — Услуги из qMS:
```php
[
    'A02.01.011' => [
        'title' => 'Помощь при укусе клеща',
        'price' => '2500'
    ]
]
```

**$priceChanges** — Изменения цен:
```php
[
    [
        'docId'    => 'A02.01.011',
        'title'    => 'Помощь при укусе клеща',
        'oldPrice' => '2475',
        'newPrice' => '2500',
        'rows'     => 2
    ]
]
```

**$toUnpublish** — К снятию:
```php
[
    [
        'docId' => '5500',
        'title' => 'Прием уролога',
        'price' => '7500',
        'rows'  => 1
    ]
]
```

**$placementsByDocId** — Где услуга на сайте:
```php
[
    'A02.01.011' => [
        'Взрослым' => [
            'Консультации' => [
                [
                    'resource_id' => 217,
                    'pagetitle' => 'Поликлиника',
                    'alias' => 'poliklinika',
                    'page_published' => 1,
                    'item_published' => 1
                ]
            ]
        ]
    ]
]
```

---

## 🚀 Настройка CRON

### Linux/Ubuntu

**Создать скрипт** `/var/www/html/cron_sync.sh`:
```bash
#!/bin/bash
/usr/bin/php /var/www/html/sync.php?cron=1 >> /var/log/qms-sync.log 2>&1
```

**Права:**
```bash
chmod +x /var/www/html/cron_sync.sh
```

**Добавить в crontab:**
```bash
crontab -e
```

```
# Ежедневно в 6:00 утра
0 6 * * * /var/www/html/cron_sync.sh

# С снятием устаревших (раз в неделю, понедельник 7:00)
0 7 * * 1 /usr/bin/php /var/www/html/sync.php?cron=1&unpublish=1 >> /var/log/qms-sync.log 2>&1
```

### Windows Task Scheduler

**Создать задачу:**
1. Действие: Запустить программу
2. Программа: `C:\php\php.exe`
3. Аргументы: `C:\www\sync.php?cron=1`
4. Расписание: Ежедневно в 06:00

---

## 📋 Чеклист перед запуском

- [ ] Создать резервную копию БД
- [ ] Проверить подключение к qMS API
- [ ] Проверить права на запись в `/core/cache/`
- [ ] Убедиться что в таблице `modx_pricelist_items2` заполнены `doc_id`
- [ ] Протестировать в режиме preview
- [ ] Проверить email-уведомления
- [ ] Настроить cron (если нужно)

---

## 🆘 Контакты и поддержка

**Email для уведомлений:** `n.karavaeva@cispb.ru` (настраивается в `$emailConfig`)

**Отчёты сохраняются:** `/reports/YYYY-MM-DD_HH-mm-ss_report.html`

**JSON отладки:** `/json.html` (последний ответ qMS)

---

## 📝 История изменений

**Версия 4.0** (текущая)
- Упрощённый интерфейс для отдела маркетинга
- Добавлена таблица исключений `modx_qms_exclusions`
- Добавлена защита от пустых `doc_id`
- Исправлена логика снятия с публикации
- Добавлен фильтр `published = 1` в статистику

**Версия 3.0**
- Поддержка CRON
- Email-уведомления
- Очистка кэша MODX

**Версия 2.0**
- Веб-интерфейс
- Режим preview/apply

**Версия 1.0**
- Базовая синхронизация через CLI

---

## 💡 Советы для ИИ-агентов

### При модификации кода:

1. **Всегда делайте бэкап БД** перед тестированием
2. **Используйте режим preview** для проверки изменений
3. **Проверяйте SQL запросы** на affected rows
4. **Логируйте критичные операции** через `error_log()`
5. **Тестируйте на копии таблицы** перед продакшеном

### При диагностике:

1. **Проверьте JSON** в `/json.html` — там последний ответ qMS
2. **Смотрите error_log** PHP
3. **Включите отладку SQL:**
```php
mysqli_query($mysqli, $query) or die(mysqli_error($mysqli));
```

### При добавлении функций:

1. **Следуйте структуре кода** (не мешайте секции)
2. **Используйте `echoReport()`** вместо `echo` для HTML
3. **Добавляйте комментарии** на русском языке
4. **Тестируйте оба режима** (веб + cron)

---

**Конец документации** 📘
