// server.js
// Grats Online — SA-MP/open.mp web control panel.
// Pure Node.js (no external dependencies): http, crypto, fs, dgram, child_process.
//
// Features:
//   - Cookie session auth (single panel user from config.json)
//   - REST: /api/login, /api/logout, /api/status, /api/action, /api/command
//   - SSE: /api/console  -> live stream of the remote log.txt (tail -F over SSH)
//   - Static file server for /public
//
// Run:  node server.js   (after copying config.example.json -> config.json)

import http from "node:http";
import { readFileSync, existsSync, createReadStream, statSync } from "node:fs";
import { randomBytes, timingSafeEqual, createHmac } from "node:crypto";
import { fileURLToPath } from "node:url";
import { dirname, join, normalize, extname } from "node:path";

import { SSHManager } from "./src/ssh.js";
import { queryServer } from "./src/query.js";

const __dirname = dirname(fileURLToPath(import.meta.url));

// ---- Config -------------------------------------------------------------
const CONFIG_PATH = join(__dirname, "config.json");
if (!existsSync(CONFIG_PATH)) {
  console.error(
    "\n[grats-panel] config.json not found.\n" +
      "Copy config.example.json -> config.json and fill in your credentials.\n"
  );
  process.exit(1);
}
const config = JSON.parse(readFileSync(CONFIG_PATH, "utf8"));
const PANEL = config.panel;
const SERVER = config.server;

const ssh = new SSHManager(config.ssh);

// ---- Tiny signed-cookie session ----------------------------------------
const SECRET = PANEL.sessionSecret || randomBytes(32).toString("hex");
const sessions = new Map(); // token -> { created }

function sign(value) {
  return createHmac("sha256", SECRET).update(value).digest("hex");
}
function makeToken() {
  const id = randomBytes(24).toString("hex");
  sessions.set(id, { created: Date.now() });
  return `${id}.${sign(id)}`;
}
function validToken(token) {
  if (!token || !token.includes(".")) return false;
  const [id, sig] = token.split(".");
  if (!sessions.has(id)) return false;
  const expected = sign(id);
  const a = Buffer.from(sig);
  const b = Buffer.from(expected);
  return a.length === b.length && timingSafeEqual(a, b);
}
function parseCookies(req) {
  const out = {};
  const raw = req.headers.cookie;
  if (!raw) return out;
  for (const part of raw.split(";")) {
    const i = part.indexOf("=");
    if (i < 0) continue;
    out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}
function isAuthed(req) {
  const c = parseCookies(req);
  return validToken(c.gid);
}

// ---- HTTP helpers -------------------------------------------------------
function sendJSON(res, status, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
  });
  res.end(body);
}
function readBody(req, limit = 1e6) {
  return new Promise((resolve, reject) => {
    let data = "";
    req.on("data", (chunk) => {
      data += chunk;
      if (data.length > limit) {
        reject(new Error("payload too large"));
        req.destroy();
      }
    });
    req.on("end", () => resolve(data));
    req.on("error", reject);
  });
}
function constEq(a, b) {
  const ba = Buffer.from(String(a));
  const bb = Buffer.from(String(b));
  if (ba.length !== bb.length) return false;
  return timingSafeEqual(ba, bb);
}

// ---- Static file serving ------------------------------------------------
const MIME = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".svg": "image/svg+xml",
  ".png": "image/png",
  ".ico": "image/x-icon",
  ".woff2": "font/woff2",
  ".json": "application/json; charset=utf-8",
};
function serveStatic(req, res, urlPath) {
  let rel = urlPath === "/" ? "/index.html" : urlPath;
  rel = normalize(rel).replace(/^(\.\.[/\\])+/, "");
  const filePath = join(__dirname, "public", rel);
  if (!filePath.startsWith(join(__dirname, "public"))) {
    res.writeHead(403);
    return res.end("Forbidden");
  }
  if (!existsSync(filePath) || !statSync(filePath).isFile()) {
    res.writeHead(404);
    return res.end("Not found");
  }
  res.writeHead(200, {
    "Content-Type": MIME[extname(filePath)] || "application/octet-stream",
    "Cache-Control": "no-cache",
  });
  createReadStream(filePath).pipe(res);
}

// ---- SSE console clients ------------------------------------------------
const sseClients = new Set();
let tailStop = null;
let lastLines = []; // ring buffer of recent lines for new clients

function startTail() {
  if (tailStop) return;
  const push = (chunk) => {
    const text = chunk.toString();
    for (const line of text.split(/\r?\n/)) {
      if (line.length === 0) continue;
      lastLines.push(line);
      if (lastLines.length > 400) lastLines.shift();
      broadcast("line", line);
    }
  };
  tailStop = ssh.tailLog(push, () => {
    broadcast("status", "[panel] log stream closed, reconnecting in 3s…");
    tailStop = null;
    setTimeout(() => { if (sseClients.size > 0) startTail(); }, 3000);
  });
  broadcast("status", "[panel] connected to log stream");
}
function maybeStopTail() {
  if (sseClients.size === 0 && tailStop) {
    tailStop();
    tailStop = null;
  }
}
function broadcast(event, data) {
  const payload = `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`;
  for (const res of sseClients) {
    try { res.write(payload); } catch {}
  }
}

// ---- Routes -------------------------------------------------------------
async function handleApi(req, res, url) {
  const path = url.pathname;

  // --- login (no auth required) ---
  if (path === "/api/login" && req.method === "POST") {
    try {
      const body = JSON.parse((await readBody(req)) || "{}");
      const okUser = constEq(body.user || "", PANEL.user);
      const okPass = constEq(body.password || "", PANEL.password);
      if (okUser && okPass) {
        const token = makeToken();
        res.setHeader(
          "Set-Cookie",
          `gid=${token}; HttpOnly; SameSite=Strict; Path=/; Max-Age=86400`
        );
        return sendJSON(res, 200, { ok: true });
      }
      return sendJSON(res, 401, { ok: false, error: "Неверный логин или пароль" });
    } catch {
      return sendJSON(res, 400, { ok: false, error: "bad request" });
    }
  }

  // Everything below requires auth.
  if (!isAuthed(req)) return sendJSON(res, 401, { ok: false, error: "unauthorized" });

  if (path === "/api/logout" && req.method === "POST") {
    const c = parseCookies(req);
    if (c.gid) sessions.delete(c.gid.split(".")[0]);
    res.setHeader("Set-Cookie", "gid=; HttpOnly; Path=/; Max-Age=0");
    return sendJSON(res, 200, { ok: true });
  }

  if (path === "/api/status" && req.method === "GET") {
    const [screen, q] = await Promise.all([
      ssh.status().catch((e) => ({ running: false, error: String(e) })),
      queryServer(SERVER.ip, SERVER.port).catch((e) => ({ online: false, error: String(e) })),
    ]);
    return sendJSON(res, 200, {
      ok: true,
      server: { name: SERVER.name, ip: SERVER.ip, port: SERVER.port },
      screen,
      query: q,
      ts: Date.now(),
    });
  }

  if (path === "/api/action" && req.method === "POST") {
    const body = JSON.parse((await readBody(req)) || "{}");
    const action = body.action;
    try {
      let result;
      if (action === "start") result = await ssh.start();
      else if (action === "stop") result = await ssh.stop();
      else if (action === "restart") result = await ssh.restart();
      else return sendJSON(res, 400, { ok: false, error: "unknown action" });
      broadcast("status", `[panel] action '${action}' -> ${result.output || "ok"}`);
      return sendJSON(res, 200, { ok: true, action, result });
    } catch (e) {
      return sendJSON(res, 500, { ok: false, error: String(e.message || e) });
    }
  }

  if (path === "/api/command" && req.method === "POST") {
    const body = JSON.parse((await readBody(req)) || "{}");
    const command = String(body.command || "").trim();
    if (!command) return sendJSON(res, 400, { ok: false, error: "empty command" });
    try {
      const result = await ssh.sendCommand(command);
      broadcast("status", `[panel] >> ${command}`);
      return sendJSON(res, 200, { ok: true, result });
    } catch (e) {
      return sendJSON(res, 500, { ok: false, error: String(e.message || e) });
    }
  }

  // --- SSE live console ---
  if (path === "/api/console" && req.method === "GET") {
    res.writeHead(200, {
      "Content-Type": "text/event-stream",
      "Cache-Control": "no-cache, no-transform",
      Connection: "keep-alive",
      "X-Accel-Buffering": "no",
    });
    res.write("retry: 3000\n\n");
    // Replay recent history to the new client.
    for (const line of lastLines) {
      res.write(`event: line\ndata: ${JSON.stringify(line)}\n\n`);
    }
    sseClients.add(res);
    startTail();
    const ping = setInterval(() => {
      try { res.write(": ping\n\n"); } catch {}
    }, 20000);
    req.on("close", () => {
      clearInterval(ping);
      sseClients.delete(res);
      maybeStopTail();
    });
    return;
  }

  return sendJSON(res, 404, { ok: false, error: "not found" });
}

// ---- Server -------------------------------------------------------------
const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://${req.headers.host || "localhost"}`);
    if (url.pathname.startsWith("/api/")) return await handleApi(req, res, url);
    return serveStatic(req, res, url.pathname);
  } catch (err) {
    sendJSON(res, 500, { ok: false, error: String(err.message || err) });
  }
});

server.listen(PANEL.port, PANEL.host, () => {
  console.log(
    `\n  ┌──────────────────────────────────────────────┐\n` +
      `  │   GRATS ONLINE — control panel                 │\n` +
      `  ├──────────────────────────────────────────────┤\n` +
      `  │   Panel:  http://${PANEL.host}:${PANEL.port}\n` +
      `  │   Target: ${SERVER.name} (${SERVER.ip}:${SERVER.port})\n` +
      `  │   SSH:    ${config.ssh.user}@${config.ssh.host}:${config.ssh.port}\n` +
      `  │   Screen: ${config.ssh.screenName}  Exe: ${config.ssh.executable}\n` +
      `  └──────────────────────────────────────────────┘\n`
  );
});

// Graceful shutdown.
function shutdown() {
  console.log("\n[grats-panel] shutting down…");
  try { ssh.dispose(); } catch {}
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 1500);
}
process.on("SIGINT", shutdown);
process.on("SIGTERM", shutdown);
