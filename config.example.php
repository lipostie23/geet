<?php
/**
 * Шаблон конфигурации панели grats online.
 * Скопируйте этот файл в config.php и заполните своими значениями:
 *     cp config.example.php config.php
 *
 * Хеш пароля можно сгенерировать так:
 *     php -r "echo password_hash('ваш_пароль', PASSWORD_BCRYPT) . PHP_EOL;"
 */
return [
    // ----- Доступ к самой панели -----
    'panel' => [
        'user' => 'admin',
        // ВАЖНО: только bcrypt-хеш, никогда не храните пароль в открытом виде.
        // Сгенерировать: php -r "echo password_hash('пароль', PASSWORD_BCRYPT);"
        'password_hash'    => '$2y$10$REPLACE_ME_WITH_REAL_BCRYPT_HASH_OF_YOUR_PASSWORD',
        'session_lifetime' => 86400, // секунд
    ],

    // ----- Игровой сервер (для UDP query — счётчик игроков, режим, пинг) -----
    'server' => [
        'name' => 'grats online',
        'ip'   => '0.0.0.0',
        'port' => 7777,
    ],

    // ----- Управление сервером -----
    'control' => [
        // 'local' — панель установлена на тот же VDS, что и игровой сервер (РЕКОМЕНДУЕТСЯ).
        //          Команды выполняются напрямую — не нужно хранить SSH-пароль на диске.
        // 'ssh'   — панель на отдельной машине, подключается по SSH (требуется sshpass).
        'mode' => 'local',

        // SSH-параметры (используются только при mode = 'ssh')
        'ssh' => [
            'host'     => '0.0.0.0',
            'port'     => 22,
            'user'     => 'root',
            'password' => 'your-ssh-password',
        ],

        // Пути и имена — общие для local и ssh режимов
        'server_path' => '/home/omp/Server',
        'screen_name' => 'omp',
        'executable'  => 'server-13',
        'log_file'    => 'log.txt',

        // Если 'local' и панель работает не от пользователя владельца сервера,
        // укажите его имя — все команды будут выполняться через `sudo -u <user>`.
        // Требуется правило в /etc/sudoers.d/ (см. README).
        'run_as_user' => null,
    ],
];
