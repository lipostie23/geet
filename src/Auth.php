<?php
declare(strict_types=1);

final class Auth
{
    /**
     * Проверка логина и пароля. Использует bcrypt-хеш из конфига.
     * Сравнение с подстановкой "ложного" хеша при неверном логине, чтобы
     * время выполнения не выдавало существование пользователя.
     */
    public static function login(array $panelCfg, string $user, string $password): bool
    {
        $expectedUser = (string)($panelCfg['user'] ?? '');
        $hash         = (string)($panelCfg['password_hash'] ?? '');

        // Защита от timing attack: всегда вызываем password_verify.
        $userOk = hash_equals($expectedUser, $user);
        $hashOk = $userOk && $hash !== '' && password_verify($password, $hash);

        if (!$userOk) {
            // Делаем такую же по времени проверку, чтобы не палить юзера.
            password_verify($password, '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidi.');
        }

        return $userOk && $hashOk;
    }
}
