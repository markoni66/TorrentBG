<?php
declare(strict_types=1);

// scrape.php - Предоставя статистики за торентите
error_reporting(0);

require_once __DIR__ . '/includes/Database.php';

$pdo = Database::getInstance();

// Помощна функция за настройки
function getSetting($pdo, $name, $default = '') {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = ?");
    $stmt->execute([$name]);
    return $stmt->fetchColumn() ?: $default;
}

// Проверка за info_hash
if (!isset($_GET['info_hash'])) {
    sendError(100, 'Missing info_hash parameter');
}

// Обработка на единичен или масив от info_hash
$infoHashes = [];
if (is_array($_GET['info_hash'])) {
    foreach ($_GET['info_hash'] as $hash) {
        if (is_string($hash) && strlen($hash) === 20) {
            $infoHashes[] = bin2hex($hash);
        }
    }
} else {
    if (is_string($_GET['info_hash']) && strlen($_GET['info_hash']) === 20) {
        $infoHashes[] = bin2hex($_GET['info_hash']);
    }
}

// Ограничение: максимум 74 торента според BEP 0003
if (empty($infoHashes) || count($infoHashes) > 74) {
    sendError(101, 'Invalid or too many info_hash values (max 74 allowed)');
}

$trackerMode = getSetting($pdo, 'tracker_mode', 'private');
$response = ['files' => []];

if ($trackerMode === 'private') {
    // Вземи torrent_id и info_hash за валидни торенти
    $placeholders = str_repeat('?,', count($infoHashes) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT id, info_hash FROM torrents WHERE info_hash IN ($placeholders)
    ");
    $stmt->execute($infoHashes);
    $validTorrents = [];
    foreach ($stmt->fetchAll() as $row) {
        $validTorrents[$row['info_hash']] = $row['id'];
    }

    // Събери статистика от peers за всички валидни info_hash наведнъж
    if (!empty($validTorrents)) {
        $validHashes = array_keys($validTorrents);
        $placeholders2 = str_repeat('?,', count($validHashes) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT 
                info_hash,
                SUM(seeder = 1) AS complete,
                SUM(seeder = 0) AS incomplete
            FROM peers 
            WHERE info_hash IN ($placeholders2) 
              AND last_announce >= NOW() - INTERVAL 30 MINUTE
            GROUP BY info_hash
        ");
        $stmt->execute($validHashes);
        $peerStats = [];
        foreach ($stmt->fetchAll() as $row) {
            $peerStats[$row['info_hash']] = [
                'complete' => (int)$row['complete'],
                'incomplete' => (int)$row['incomplete']
            ];
        }
    }

    // Формирай отговора
    foreach ($infoHashes as $hash) {
        if (isset($validTorrents[$hash])) {
            $stats = $peerStats[$hash] ?? ['complete' => 0, 'incomplete' => 0];
            // Ако искаш downloaded от torrents.completed, добави го от таблицата torrents
            $response['files'][$hash] = [
                'complete' => $stats['complete'],
                'incomplete' => $stats['incomplete'],
                'downloaded' => 0 // или вземи от torrents.completed
            ];
        } else {
            $response['files'][$hash] = [
                'complete' => 0,
                'incomplete' => 0,
                'downloaded' => 0
            ];
        }
    }
} else {
    // OPEN режим: броим директно от peers по info_hash
    $placeholders = str_repeat('?,', count($infoHashes) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT 
            info_hash,
            SUM(seeder = 1) AS complete,
            SUM(seeder = 0) AS incomplete
        FROM peers 
        WHERE info_hash IN ($placeholders) 
          AND last_announce >= NOW() - INTERVAL 30 MINUTE
        GROUP BY info_hash
    ");
    $stmt->execute($infoHashes);
    $peerStats = [];
    foreach ($stmt->fetchAll() as $row) {
        $peerStats[$row['info_hash']] = [
            'complete' => (int)$row['complete'],
            'incomplete' => (int)$row['incomplete']
        ];
    }

    foreach ($infoHashes as $hash) {
        $stats = $peerStats[$hash] ?? ['complete' => 0, 'incomplete' => 0];
        $response['files'][$hash] = [
            'complete' => $stats['complete'],
            'incomplete' => $stats['incomplete'],
            'downloaded' => 0
        ];
    }
}

// Изпращаме bencode отговор
header('Content-Type: text/plain');
echo bencode($response);
exit;

// Функции за bencode
function bencode($data) {
    if (is_array($data)) {
        if (isset($data[0]) || empty($data)) { // list
            $encoded = 'l';
            foreach ($data as $item) {
                $encoded .= bencode($item);
            }
            return $encoded . 'e';
        } else { // dict
            ksort($data, SORT_STRING);
            $encoded = 'd';
            foreach ($data as $key => $value) {
                $encoded .= bencode((string)$key) . bencode($value);
            }
            return $encoded . 'e';
        }
    }
    if (is_int($data)) {
        return 'i' . $data . 'e';
    }
    if (is_string($data)) {
        return strlen($data) . ':' . $data;
    }
    return '0:';
}

function sendError(int $code, string $message) {
    header('Content-Type: text/plain');
    echo bencode(['failure reason' => $message, 'error code' => $code]);
    exit;
}