<?php
/**
 * Общий bootstrap для всех точек входа (api/*.php и index.php).
 * Загружает конфиг, стартует сессию, регистрирует утилиты.
 */
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ServerControl.php';
require_once __DIR__ . '/SampQuery.php';

// ---- Загрузка конфига ----
if (!file_exists(PROJECT_ROOT . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("config.php не найден. Скопируйте config.example.php в config.php и заполните его.");
}
$config = require PROJECT_ROOT . '/config.php';

// ---- Безопасные настройки сессии ----
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
if (!empty($config['panel']['session_lifetime'])) {
    ini_set('session.gc_maxlifetime', (string)$config['panel']['session_lifetime']);
    session_set_cookie_params([
        'lifetime' => (int)$config['panel']['session_lifetime'],
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
session_start();

/**
 * Отдать JSON-ответ и завершить выполнение.
 * @param mixed $data
 */
function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Прервать выполнение если пользователь не залогинен. */
function require_auth(): void {
    if (empty($_SESSION['user'])) {
        json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

/** Прочитать тело запроса как JSON-массив (или []). */
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
