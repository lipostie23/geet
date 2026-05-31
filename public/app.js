/* GRATS ONLINE control panel — frontend (vanilla JS, без зависимостей) */
(() => {
  "use strict";

  const $ = (sel) => document.querySelector(sel);
  const els = {
    login: $("#login"),
    loginForm: $("#loginForm"),
    loginError: $("#loginError"),
    loginBtn: $("#loginBtn"),
    dashboard: $("#dashboard"),
    logoutBtn: $("#logoutBtn"),
    connDot: $("#connDot"),
    connText: $("#connText"),
    serverAddr: $("#serverAddr"),
    cardStatus: $("#cardStatus"),
    cardPlayers: $("#cardPlayers"),
    cardMode: $("#cardMode"),
    cardPing: $("#cardPing"),
    procDot: $("#procDot"),
    procText: $("#procText"),
    metaLang: $("#metaLang"),
    console: $("#console"),
    autoscroll: $("#autoscroll"),
    clearBtn: $("#clearBtn"),
    cmdForm: $("#cmdForm"),
    cmdInput: $("#cmdInput"),
    toast: $("#toast"),
  };

  let evtSource = null;
  let statusTimer = null;
  const MAX_LINES = 600;

  // ---------- helpers ----------
  async function api(path, opts = {}) {
    const res = await fetch(path, {
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      ...opts,
    });
    let data = {};
    try { data = await res.json(); } catch {}
    return { status: res.status, data };
  }

  function toast(msg, kind = "") {
    els.toast.textContent = msg;
    els.toast.className = "toast show " + kind;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => (els.toast.className = "toast " + kind), 3200);
  }

  const pad = (n) => String(n).padStart(2, "0");
  function stamp() {
    const d = new Date();
    return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  }

  function classifyLine(text) {
    const t = text.toLowerCase();
    if (/\[panel\]|^\s*\[server\]|loaded|started|init/.test(t)) return "sys";
    if (/error|fail|exception|cannot|denied/.test(t))            return "err";
    if (/warn|deprecat/.test(t))                                  return "warn";
    return "";
  }

  function appendLine(text, forceKind) {
    const line = document.createElement("span");
    line.className = "console-line " + (forceKind || classifyLine(text));
    const ts = document.createElement("span");
    ts.className = "tstamp";
    ts.textContent = `[${stamp()}] `;
    line.appendChild(ts);
    line.appendChild(document.createTextNode(text));
    els.console.appendChild(line);
    while (els.console.children.length > MAX_LINES) {
      els.console.removeChild(els.console.firstChild);
    }
    if (els.autoscroll.checked) {
      els.console.scrollTop = els.console.scrollHeight;
    }
  }

  // ---------- auth ----------
  els.loginForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    els.loginError.textContent = "";
    els.loginBtn.disabled = true;
    const { status, data } = await api("/api/login.php", {
      method: "POST",
      body: JSON.stringify({
        user: $("#user").value,
        password: $("#password").value,
      }),
    });
    els.loginBtn.disabled = false;
    if (status === 200 && data.ok) {
      enterDashboard();
    } else {
      els.loginError.textContent = data.error || "Ошибка входа";
    }
  });

  els.logoutBtn.addEventListener("click", async () => {
    await api("/api/logout.php", { method: "POST" });
    leaveDashboard();
  });

  // ---------- dashboard lifecycle ----------
  function enterDashboard() {
    els.login.classList.add("hidden");
    els.dashboard.classList.remove("hidden");
    els.console.innerHTML = "";
    appendLine("[panel] подключение к серверу…", "sys");
    openConsoleStream();
    refreshStatus();
    statusTimer = setInterval(refreshStatus, 5000);
  }

  function leaveDashboard() {
    els.dashboard.classList.add("hidden");
    els.login.classList.remove("hidden");
    if (evtSource)  { evtSource.close();        evtSource = null; }
    if (statusTimer){ clearInterval(statusTimer); statusTimer = null; }
    $("#password").value = "";
  }

  // ---------- live console (SSE) ----------
  function openConsoleStream() {
    if (evtSource) evtSource.close();
    evtSource = new EventSource("/api/console.php");
    evtSource.addEventListener("line", (e) => {
      try { appendLine(JSON.parse(e.data)); } catch { appendLine(e.data); }
    });
    evtSource.addEventListener("status", (e) => {
      try { appendLine(JSON.parse(e.data), "sys"); } catch {}
    });
    evtSource.onerror = () => {
      appendLine("[panel] переподключение к лог-потоку…", "warn");
    };
  }

  // ---------- status polling ----------
  async function refreshStatus() {
    const { status, data } = await api("/api/status.php");
    if (status === 401) return leaveDashboard();
    if (!data.ok) return;

    els.serverAddr.textContent = `${data.server.ip}:${data.server.port}`;

    const q = data.query || {};
    const online = !!q.online;
    els.connDot.className = "conn-dot " + (online ? "on" : "off");
    els.connText.textContent = online ? "Сервер онлайн" : "Сервер оффлайн";

    els.cardStatus.textContent = online ? "Онлайн" : "Оффлайн";
    els.cardStatus.style.color = online ? "var(--green)" : "var(--red)";
    els.cardPlayers.textContent = online ? `${q.players} / ${q.maxPlayers}` : "—";
    els.cardMode.textContent    = online ? (q.gamemode || "—") : "—";
    els.cardPing.textContent    = online && q.ping != null ? `${q.ping} мс` : "—";
    els.metaLang.textContent    = online ? (q.language || "—") : "—";

    const sc = data.screen || {};
    const running = !!sc.running;
    els.procDot.className = "dot " + (running ? "on" : "off");
    els.procText.textContent = running ? "screen: запущен" : "screen: остановлен";
  }

  // ---------- control actions ----------
  document.querySelectorAll(".control-btns .btn").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const action = btn.dataset.action;
      const labels = { start: "Запуск", stop: "Остановка", restart: "Рестарт" };
      if (action === "stop"    && !confirm("Остановить сервер?"))   return;
      if (action === "restart" && !confirm("Перезапустить сервер?")) return;

      const all = document.querySelectorAll(".control-btns .btn");
      all.forEach((b) => (b.disabled = true));
      toast(`${labels[action]}…`);
      appendLine(`[panel] выполняется: ${labels[action]}`, "sys");

      const { status, data } = await api("/api/action.php", {
        method: "POST",
        body: JSON.stringify({ action }),
      });
      all.forEach((b) => (b.disabled = false));

      if (status === 401) return leaveDashboard();
      if (data.ok) {
        toast(`${labels[action]}: готово`, "ok");
        if (data.result && data.result.output) {
          appendLine(`[panel] ${data.result.output}`, "sys");
        }
      } else {
        toast(`Ошибка: ${data.error || "неизвестно"}`, "err");
        appendLine(`[panel] ошибка: ${data.error || ""}`, "err");
      }
      setTimeout(refreshStatus, 1500);
    });
  });

  // ---------- command input ----------
  els.cmdForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const command = els.cmdInput.value.trim();
    if (!command) return;
    appendLine(`> ${command}`, "sys");
    els.cmdInput.value = "";
    const { status, data } = await api("/api/command.php", {
      method: "POST",
      body: JSON.stringify({ command }),
    });
    if (status === 401) return leaveDashboard();
    if (!data.ok) toast(`Команда не отправлена: ${data.error || ""}`, "err");
  });

  els.clearBtn.addEventListener("click", () => {
    els.console.innerHTML = "";
    appendLine("[panel] консоль очищена (локально)", "sys");
  });

  // ---------- boot: проверить, не залогинены ли уже ----------
  (async function boot() {
    const { status } = await api("/api/status.php");
    if (status === 200) enterDashboard();
  })();
})();
