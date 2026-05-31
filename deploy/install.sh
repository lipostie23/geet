#!/usr/bin/env bash
# ====================================================================
# Автоматическая установка панели управления grats online на VDS.
# Поддерживает: Debian/Ubuntu (apt). Для CentOS/AlmaLinux — см. README.
#
# Запуск: sudo bash deploy/install.sh
# ====================================================================
set -euo pipefail

SITE_DIR="/var/www/grats-panel"
WEB_USER="www-data"
WEB_GROUP="www-data"
PANEL_PORT="8080"

# --- проверки ---
if [ "$(id -u)" -ne 0 ]; then
    echo "Запустите от root: sudo bash deploy/install.sh"
    exit 1
fi
if ! command -v apt-get >/dev/null; then
    echo "Этот скрипт рассчитан на Debian/Ubuntu (apt)."
    echo "Для CentOS/AlmaLinux установите пакеты вручную, см. README."
    exit 1
fi

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
echo "==> Источник: $REPO_DIR"
echo "==> Целевая директория: $SITE_DIR"

# --- зависимости ---
echo "==> Установка зависимостей (nginx, php-fpm, screen, sshpass)…"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    nginx \
    php-fpm php-cli php-mbstring php-sockets \
    screen sshpass curl

# Определяем версию php-fpm сокета (php8.1/8.2/8.3/8.4)
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
if [ ! -S "$PHP_SOCK" ]; then
    # fallback: найдём первый доступный
    PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
fi
if [ -z "$PHP_SOCK" ]; then
    echo "ОШИБКА: не найден сокет php-fpm в /run/php/. Установите php-fpm."
    exit 1
fi
echo "==> php-fpm сокет: $PHP_SOCK"

# --- копирование файлов ---
echo "==> Копирование сайта в $SITE_DIR…"
mkdir -p "$SITE_DIR"
cp -r "$REPO_DIR/public" "$SITE_DIR/"
cp -r "$REPO_DIR/src"    "$SITE_DIR/"
cp     "$REPO_DIR/config.example.php" "$SITE_DIR/config.example.php"

# config.php: не перезаписываем, если уже существует
if [ ! -f "$SITE_DIR/config.php" ]; then
    if [ -f "$REPO_DIR/config.php" ]; then
        echo "==> Копирую существующий config.php (с локального чекаута)"
        cp "$REPO_DIR/config.php" "$SITE_DIR/config.php"
    else
        echo "==> Создаю config.php из шаблона. ОТРЕДАКТИРУЙТЕ его перед использованием!"
        cp "$SITE_DIR/config.example.php" "$SITE_DIR/config.php"
    fi
else
    echo "==> $SITE_DIR/config.php уже существует — оставляю как есть."
fi

# --- права ---
chown -R "$WEB_USER:$WEB_GROUP" "$SITE_DIR"
# конфиг с секретами — только для веб-юзера
chmod 640 "$SITE_DIR/config.php"
find "$SITE_DIR/public" -type f -exec chmod 644 {} \;
find "$SITE_DIR/src"    -type f -exec chmod 644 {} \;

# --- nginx ---
NGINX_CONF="/etc/nginx/sites-available/grats-panel"
echo "==> Установка nginx-конфига…"
sed "s|fastcgi_pass unix:/run/php/php8.3-fpm.sock;|fastcgi_pass unix:${PHP_SOCK};|g" \
    "$REPO_DIR/deploy/nginx.conf" > "$NGINX_CONF"

ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/grats-panel
# на всякий случай уберём дефолтный сайт, если он висит на 8080 (обычно нет)
nginx -t
systemctl reload nginx

echo
echo "============================================================"
echo " ✓ Установка завершена."
echo
echo "  Панель:    http://$(hostname -I | awk '{print $1}'):${PANEL_PORT}/"
echo "  Конфиг:    $SITE_DIR/config.php"
echo "  Логи:      /var/log/nginx/grats-panel-*.log (если включены)"
echo
echo "  Что сделать дальше:"
echo "  1) Отредактируйте $SITE_DIR/config.php (логин, хеш пароля, пути)."
echo "  2) Сгенерируйте новый хеш пароля админки:"
echo "       php -r \"echo password_hash('новый_пароль', PASSWORD_BCRYPT) . PHP_EOL;\""
echo "  3) (опционально) Включите автозапуск SAMP-сервера:"
echo "       sudo cp deploy/grats-server.service /etc/systemd/system/"
echo "       sudo systemctl enable --now grats-server"
echo "============================================================"
