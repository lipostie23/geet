<?php
require __DIR__ . '/../../src/bootstrap.php';
require_auth();

$ctrl   = new ServerControl($config['control']);
$screen = $ctrl->status();
$query  = SampQuery::query(
    (string)$config['server']['ip'],
    (int)$config['server']['port']
);

json_response([
    'ok'     => true,
    'server' => [
        'name' => $config['server']['name'],
        'ip'   => $config['server']['ip'],
        'port' => $config['server']['port'],
    ],
    'screen' => $screen,
    'query'  => $query,
    'ts'     => (int)round(microtime(true) * 1000),
]);
