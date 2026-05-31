<?php
/**
 * Единая точка входа. Отдаёт ту же страницу всегда — JS сам решит,
 * показать ли логин или дашборд (по ответу /api/status.php).
 */
require __DIR__ . '/../src/bootstrap.php';

// Если дёрнули на index.php?logout — выкинем
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#0b0b14" />
  <title><?= htmlspecialchars($config['server']['name'] ?? 'grats online', ENT_QUOTES, 'UTF-8') ?> · Панель управления</title>
  <link rel="stylesheet" href="/style.css?v=2" />
  <link rel="icon" href='data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🎮</text></svg>' />
</head>
<body>
  <div class="bg-grid"></div>
  <div class="bg-glow"></div>

  <!-- ===== LOGIN ===== -->
  <section id="login" class="login-wrap">
    <form id="loginForm" class="login-card" autocomplete="off">
      <div class="login-logo">
        <span class="logo-mark">G</span>
        <div class="logo-text"><b>GRATS</b><span>ONLINE</span></div>
      </div>
      <h1>Панель управления</h1>
      <p class="login-sub">Авторизуйтесь для доступа к серверу</p>
      <label class="field">
        <span>Логин</span>
        <input id="user" type="text" name="user" placeholder="admin" autocomplete="username" required />
      </label>
      <label class="field">
        <span>Пароль</span>
        <input id="password" type="password" name="password" placeholder="••••••••" autocomplete="current-password" required />
      </label>
      <button type="submit" class="btn btn-primary btn-block" id="loginBtn"><span>Войти</span></button>
      <div id="loginError" class="login-error"></div>
    </form>
  </section>

  <!-- ===== DASHBOARD ===== -->
  <main id="dashboard" class="dash hidden">
    <header class="topbar">
      <div class="brand">
        <span class="logo-mark sm">G</span>
        <div class="brand-text">
          <b><?= htmlspecialchars(strtoupper($config['server']['name']), ENT_QUOTES, 'UTF-8') ?></b>
          <small id="serverAddr"><?= htmlspecialchars($config['server']['ip'] . ':' . $config['server']['port'], ENT_QUOTES, 'UTF-8') ?></small>
        </div>
      </div>
      <div class="topbar-right">
        <div id="connDot" class="conn-dot" title="Состояние сервера"></div>
        <span id="connText" class="conn-text">проверка…</span>
        <button id="logoutBtn" class="btn btn-ghost">Выйти</button>
      </div>
    </header>

    <section class="cards">
      <div class="card stat">
        <div class="stat-icon">📡</div>
        <div class="stat-body"><span class="stat-label">Статус</span><span class="stat-value" id="cardStatus">—</span></div>
      </div>
      <div class="card stat">
        <div class="stat-icon">👥</div>
        <div class="stat-body"><span class="stat-label">Игроки</span><span class="stat-value" id="cardPlayers">—</span></div>
      </div>
      <div class="card stat">
        <div class="stat-icon">🗺️</div>
        <div class="stat-body"><span class="stat-label">Режим</span><span class="stat-value" id="cardMode">—</span></div>
      </div>
      <div class="card stat">
        <div class="stat-icon">⚡</div>
        <div class="stat-body"><span class="stat-label">Пинг</span><span class="stat-value" id="cardPing">—</span></div>
      </div>
    </section>

    <section class="grid">
      <aside class="card control">
        <h2 class="card-title">Управление</h2>
        <div class="control-state">
          <span class="dot" id="procDot"></span>
          <span id="procText">screen: проверка…</span>
        </div>
        <div class="control-btns">
          <button class="btn btn-start"   data-action="start">▶ Запустить</button>
          <button class="btn btn-restart" data-action="restart">⟳ Рестарт</button>
          <button class="btn btn-stop"    data-action="stop">■ Остановить</button>
        </div>
        <div class="control-meta">
          <div><span>Сервер:</span><b><?= htmlspecialchars($config['server']['name'], ENT_QUOTES, 'UTF-8') ?></b></div>
          <div><span>Скрин:</span><b><?= htmlspecialchars($config['control']['screen_name'], ENT_QUOTES, 'UTF-8') ?></b></div>
          <div><span>Файл:</span><b><?= htmlspecialchars($config['control']['executable'], ENT_QUOTES, 'UTF-8') ?></b></div>
          <div><span>Язык:</span><b id="metaLang">—</b></div>
        </div>
      </aside>

      <div class="card console-card">
        <div class="console-head">
          <h2 class="card-title">Консоль сервера <small>· <?= htmlspecialchars($config['control']['log_file'], ENT_QUOTES, 'UTF-8') ?></small></h2>
          <div class="console-tools">
            <label class="switch">
              <input type="checkbox" id="autoscroll" checked /><span>Автопрокрутка</span>
            </label>
            <button id="clearBtn" class="btn btn-ghost btn-sm">Очистить</button>
          </div>
        </div>
        <div id="console" class="console" aria-live="polite"></div>
        <form id="cmdForm" class="cmd-bar">
          <span class="cmd-prompt">&gt;</span>
          <input id="cmdInput" type="text" placeholder="Введите команду сервера и нажмите Enter" autocomplete="off" />
          <button type="submit" class="btn btn-primary btn-sm">Отправить</button>
        </form>
      </div>
    </section>

    <footer class="foot">
      <?= htmlspecialchars($config['server']['name'], ENT_QUOTES, 'UTF-8') ?> control panel ·
      статус каждые 5 сек · лог в реальном времени
    </footer>
  </main>

  <div id="toast" class="toast"></div>
  <script src="/app.js?v=2"></script>
</body>
</html>
