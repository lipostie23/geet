# grats online — панель управления SA-MP / open.mp сервером

Веб-панель на чистом PHP для управления игровым сервером через `screen`.

- 🔐 Логин с bcrypt-хешем пароля и сессиями PHP
- 🖥️ Живая консоль из `log.txt` (Server-Sent Events + `tail -F`)
- ▶️ Кнопки **Start / Stop / Restart** для screen-сессии
- ⌨️ Поле для отправки команд в консоль сервера (RCON и т.п.)
- 📡 Онлайн/оффлайн, число игроков, режим, пинг — обновляются каждые 5 сек через UDP-query
- 🎨 Тёмная тема в стиле core-bonus.ru

Зависимости: PHP 8.0+, `screen`, `nginx` (или Apache), `sshpass` (только если панель работает на отдельной машине).

---

## 1. Быстрый старт (рекомендуемый: панель на том же VDS)

```bash
# на сервере
sudo apt update
sudo apt install -y git
git clone https://github.com/lipostie23/geet.git /opt/grats-panel
cd /opt/grats-panel

# отредактируйте config.php (см. секцию ниже)
cp config.example.php config.php
nano config.php

# автоустановка nginx + php-fpm + screen + копирование сайта
sudo bash deploy/install.sh
```

Откройте `http://<IP_ВАШЕГО_VDS>:8080/`.  
Логин по умолчанию из `config.example.php`: `admin` / **смените пароль перед использованием!**

---

## 2. Настройка `config.php`

```bash
cp config.example.php config.php
```

### 2.1. Сгенерируйте хеш пароля админки

```bash
php -r "echo password_hash('ваш_пароль', PASSWORD_BCRYPT) . PHP_EOL;"
```

Скопируйте полученный хеш в поле `password_hash`.

### 2.2. Заполните параметры сервера

```php
'server' => [
    'name' => 'grats online',
    'ip'   => '185.56.162.235',
    'port' => 7777,
],

'control' => [
    'mode'        => 'local',     // 'local' если панель на том же VDS
    'server_path' => '/home/omp/Server',
    'screen_name' => 'omp',
    'executable'  => 'server-13',
    'log_file'    => 'log.txt',
],
```

---

## 3. Если панель на отдельной машине (режим `ssh`)

В `config.php`:

```php
'control' => [
    'mode' => 'ssh',
    'ssh' => [
        'host'     => '185.56.162.235',
        'port'     => 22,
        'user'     => 'root',
        'password' => 'lT@?2mTI@O*s37h8',
    ],
    'server_path' => '/home/omp/Server',
    'screen_name' => 'omp',
    'executable'  => 'server-13',
    'log_file'    => 'log.txt',
],
```

И установите `sshpass`:

```bash
sudo apt install -y sshpass
```

> ⚠️ Парольный SSH небезопасен. Если можете — переключитесь на ключи: `ssh-copy-id`, и в `config.php` уберите `password` (sshpass не понадобится). В этом случае nginx-юзер должен иметь приватный ключ для подключения.

---

## 4. Если веб-сервер работает не от того пользователя, что игровой сервер

Например, nginx работает от `www-data`, а сервер — от `omp`. Тогда в `config.php`:

```php
'control' => [
    'mode'        => 'local',
    'run_as_user' => 'omp',
    ...
],
```

И добавьте sudoers-правило (без него sudo попросит пароль и панель повиснет):

```bash
sudo cp deploy/sudoers-grats-panel /etc/sudoers.d/grats-panel
sudo chmod 440 /etc/sudoers.d/grats-panel
sudo visudo -c   # проверить синтаксис
```

Содержимое:

```
www-data ALL=(omp) NOPASSWD: /usr/bin/bash -c *
```

> ⚠️ Это правило даёт `www-data` полный shell-доступ от имени `omp`. Если хотите ограничить — пропишите конкретные команды (`screen -ls`, `screen -S omp -X *`, `tail -F /home/omp/Server/log.txt` и т.п.).

---

## 5. Автозапуск SAMP-сервера после ребута VDS

```bash
sudo cp deploy/grats-server.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now grats-server
sudo systemctl status grats-server
```

После этого сервер будет автоматически подниматься после перезагрузки VDS.

---

## 6. Управление панелью (nginx)

```bash
sudo nginx -t                    # проверить конфиг
sudo systemctl reload nginx      # применить изменения
sudo systemctl restart php8.3-fpm  # если меняли php.ini
sudo tail -f /var/log/nginx/error.log
```

---

## 7. HTTPS (рекомендуется для прода)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d panel.your-domain.ru
```

Certbot сам добавит `listen 443 ssl;` и редирект с HTTP. После этого можно поменять `listen 8080` на `listen 80` в `nginx.conf`.

---

## 8. Что находится где

| Путь | Что |
|------|-----|
| `public/index.php` | Главная страница (логин + дашборд) |
| `public/api/*.php` | REST-эндпоинты: `login`, `logout`, `status`, `action`, `command`, `console` (SSE) |
| `public/style.css` | Тёмная тема в стиле core-bonus.ru |
| `public/app.js` | Логика интерфейса |
| `src/Auth.php` | Проверка логина (bcrypt + защита от timing attacks) |
| `src/SampQuery.php` | UDP-query для SA-MP/open.mp (счётчик игроков, режим, пинг) |
| `src/ServerControl.php` | Старт/стоп/рестарт + tail логов (local + ssh режимы) |
| `src/bootstrap.php` | Загрузка конфига и сессии |
| `config.example.php` | Шаблон конфига (в Git) |
| `config.php` | Реальный конфиг (в `.gitignore`, не попадает в Git) |
| `deploy/install.sh` | Автоустановка для Debian/Ubuntu |
| `deploy/nginx.conf` | nginx vhost (с правильными настройками SSE) |
| `deploy/apache.conf` | Apache vhost (альтернатива) |
| `deploy/grats-server.service` | systemd unit для автозапуска самого SAMP-сервера |
| `deploy/sudoers-grats-panel` | Правило sudo (если веб-юзер ≠ владелец сервера) |

---

## 9. Известные ограничения

- **SSE требует отключённого gzip и буферизации** — конфиги в `deploy/` это уже учитывают, но если используете другой веб-сервер / прокси (Cloudflare, например) — проверьте, что для `/api/console.php` отключён буфер.
- **PHP `max_execution_time`** для console.php снимается через `set_time_limit(0)`, но если у хостинга есть глобальный hard-limit (например, у shared-хостинга) — лог-стрим будет рваться. На своём VDS — не проблема.
- **php-fpm worker занят на всё время лог-стрима** — на 1 пользователя 1 worker. Если у вас будет 50 одновременных вкладок панели, увеличьте `pm.max_children` в `/etc/php/*/fpm/pool.d/www.conf`.

---

## 10. Безопасность

1. ✅ `config.php` уже в `.gitignore` — пароль SSH и хеш админки не попадут в Git.
2. ✅ Пароль админки хранится **только bcrypt-хешем**, никогда в открытом виде.
3. ✅ Сессионные cookie с `HttpOnly`, `SameSite=Strict`, `use_strict_mode=1`.
4. ⚠️ Все аргументы команд (имя screen, путь, команды) экранируются перед передачей в shell — инъекций нет.
5. ⚠️ **Обязательно поставьте HTTPS** перед публикацией наружу — иначе пароль уйдёт по сети открытым текстом.
6. ⚠️ **Закройте порт панели firewall'ом** для всех IP, кроме ваших, если нет необходимости в публичном доступе.
