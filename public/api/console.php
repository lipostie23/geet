<?php
/**
 * SSE-эндпоинт: стримит log.txt в реальном времени.
 * Использует tail -F через ServerControl::tailLogStream.
 *
 * Важные настройки сервера для корректного SSE:
 *   - php-fpm:    output_buffering = Off (или ob_*end* в коде)
 *   - nginx:      fastcgi_buffering off; gzip off для этого location;
 *                 X-Accel-Buffering: no (заголовок ниже)
 *   - apache:     mod_deflate выключить для этого location, либо подавить заголовком
 */
require __DIR__ . '/../../src/bootstrap.php';
require_auth();

// Освобождаем сессию — иначе она блокирует другие AJAX-запросы пока стрим жив.
session_write_close();

@set_time_limit(0);
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering',        '0');
@ini_set('implicit_flush',          '1');
ignore_user_abort(false);

while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // отключает буферизацию nginx
@header_remove('Content-Encoding');

// Браузеру: при разрыве переподключаться через 3 сек
echo "retry: 3000\n\n";

// Привет клиенту
echo "event: status\ndata: " . json_encode("[panel] подключение к лог-потоку…", JSON_UNESCAPED_UNICODE) . "\n\n";
@flush();

$ctrl = new ServerControl($config['control']);

$lastPing = time();
$ctrl->tailLogStream(
    function (string $line) use (&$lastPing) {
        echo "event: line\ndata: " . json_encode($line, JSON_UNESCAPED_UNICODE) . "\n\n";
        // keep-alive ping каждые 20 сек, чтобы прокси не разрывали соединение
        if (time() - $lastPing >= 20) {
            echo ": ping\n\n";
            $lastPing = time();
        }
        @flush();
    }
);

echo "event: status\ndata: " . json_encode("[panel] лог-поток закрыт", JSON_UNESCAPED_UNICODE) . "\n\n";
@flush();
