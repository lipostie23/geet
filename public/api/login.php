<?php
require __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'method not allowed'], 405);
}

$body = read_json_body();
$user = (string)($body['user'] ?? '');
$pwd  = (string)($body['password'] ?? '');

if (Auth::login($config['panel'], $user, $pwd)) {
    session_regenerate_id(true);
    $_SESSION['user']       = $config['panel']['user'];
    $_SESSION['login_time'] = time();
    json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Неверный логин или пароль'], 401);
