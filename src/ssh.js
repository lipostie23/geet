// src/ssh.js
// Lightweight SSH layer with ZERO npm dependencies.
// Uses the system OpenSSH client and the SSH_ASKPASS technique to feed the
// password non-interactively (works on OpenSSH >= 8.4 via SSH_ASKPASS_REQUIRE=force).
//
// Exposes:
//   - exec(cmd)            -> { code, stdout, stderr }
//   - tailLog(onLine, onErr) -> returns a stop() function (live `tail -F`)
//   - control actions: start / stop / restart / status / sendCommand
//
// All remote shell arguments are quoted with shellQuote() to avoid injection.

import { spawn } from "node:child_process";
import { mkdtempSync, writeFileSync, chmodSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

/** Single-quote a string for safe use inside a POSIX shell. */
export function shellQuote(s) {
  return `'${String(s).replace(/'/g, `'\\''`)}'`;
}

export class SSHManager {
  /** @param {object} sshCfg config.ssh section */
  constructor(sshCfg) {
    this.cfg = sshCfg;
    // Persistent ControlMaster socket dir so repeated calls reuse one connection.
    this._tmp = mkdtempSync(join(tmpdir(), "grats-ssh-"));
    this._askpass = join(this._tmp, "askpass.sh");
    this._knownHosts = join(this._tmp, "known_hosts");
    this._controlPath = join(this._tmp, "cm-%C");
    this._writeAskpass();
  }

  _writeAskpass() {
    // The askpass program just echoes the password to stdout.
    const script = `#!/bin/sh\ncat <<'__PW__'\n${this.cfg.password}\n__PW__\n`;
    writeFileSync(this._askpass, script, { mode: 0o700 });
    chmodSync(this._askpass, 0o700);
  }

  _baseArgs() {
    return [
      "-p", String(this.cfg.port || 22),
      "-o", "StrictHostKeyChecking=accept-new",
      "-o", `UserKnownHostsFile=${this._knownHosts}`,
      "-o", "PreferredAuthentications=password,keyboard-interactive",
      "-o", "PubkeyAuthentication=no",
      "-o", "NumberOfPasswordPrompts=1",
      "-o", "ConnectTimeout=10",
      "-o", "ServerAliveInterval=15",
      "-o", "ControlMaster=auto",
      "-o", `ControlPath=${this._controlPath}`,
      "-o", "ControlPersist=30",
      "-o", "LogLevel=ERROR",
      `${this.cfg.user}@${this.cfg.host}`,
    ];
  }

  _spawnSSH(extraArgs) {
    const env = {
      ...process.env,
      SSH_ASKPASS: this._askpass,
      SSH_ASKPASS_REQUIRE: "force", // OpenSSH >= 8.4: always use askpass
      DISPLAY: process.env.DISPLAY || ":0",
    };
    // detached + no stdin tty so ssh cannot grab the terminal for the prompt.
    return spawn("ssh", [...this._baseArgs(), ...extraArgs], {
      env,
      stdio: ["ignore", "pipe", "pipe"],
      detached: true,
    });
  }

  /**
   * Run a remote command, buffering output.
   * @param {string} remoteCmd
   * @returns {Promise<{code:number, stdout:string, stderr:string}>}
   */
  exec(remoteCmd) {
    return new Promise((resolve) => {
      const child = this._spawnSSH([remoteCmd]);
      let stdout = "";
      let stderr = "";
      child.stdout.on("data", (d) => (stdout += d.toString()));
      child.stderr.on("data", (d) => (stderr += d.toString()));
      child.on("error", (err) =>
        resolve({ code: -1, stdout, stderr: stderr + String(err.message) })
      );
      child.on("close", (code) => resolve({ code: code ?? -1, stdout, stderr }));
    });
  }

  /**
   * Live-tail the server log file. Calls onLine(string) per chunk.
   * Returns a stop() function that kills the remote tail.
   */
  tailLog(onLine, onClose) {
    const logPath = `${this.cfg.serverPath}/${this.cfg.logFile}`;
    // `tail -n 200 -F` => last 200 lines then follow, survives log rotation.
    const remote = `cd ${shellQuote(this.cfg.serverPath)} 2>/dev/null; tail -n 200 -F ${shellQuote(logPath)} 2>/dev/null`;
    const child = this._spawnSSH(["-T", remote]);
    child.stdout.on("data", (d) => onLine(d.toString()));
    child.stderr.on("data", (d) => onLine(d.toString()));
    child.on("close", () => onClose && onClose());
    return () => {
      try {
        if (child.pid) process.kill(-child.pid, "SIGKILL");
      } catch {
        try { child.kill("SIGKILL"); } catch {}
      }
    };
  }

  // ---- High level control actions ---------------------------------------

  _screenExists() {
    return this.exec(
      `screen -ls | grep -qw ${shellQuote(this.cfg.screenName)} && echo RUNNING || echo STOPPED`
    );
  }

  async status() {
    const r = await this._screenExists();
    const running = /RUNNING/.test(r.stdout);
    return { running, raw: r.stdout.trim(), code: r.code, stderr: r.stderr.trim() };
  }

  async start() {
    const { serverPath, screenName, executable } = this.cfg;
    // Create a detached screen running the server executable.
    const cmd = [
      `cd ${shellQuote(serverPath)}`,
      `chmod +x ${shellQuote("./" + executable)} 2>/dev/null`,
      `screen -ls | grep -qw ${shellQuote(screenName)} && echo ALREADY_RUNNING || ` +
        `screen -dmS ${shellQuote(screenName)} ${shellQuote("./" + executable)}`,
    ].join(" ; ");
    const r = await this.exec(cmd);
    return { ok: r.code === 0, output: (r.stdout + r.stderr).trim() };
  }

  async stop() {
    const { screenName } = this.cfg;
    // Send 'quit' to the console, then hard-kill the screen as a fallback.
    const cmd =
      `screen -S ${shellQuote(screenName)} -p 0 -X stuff "exit\\n" 2>/dev/null ; ` +
      `sleep 1 ; ` +
      `screen -S ${shellQuote(screenName)} -X quit 2>/dev/null ; echo STOPPED`;
    const r = await this.exec(cmd);
    return { ok: true, output: (r.stdout + r.stderr).trim() };
  }

  async restart() {
    await this.stop();
    await new Promise((res) => setTimeout(res, 2000));
    return this.start();
  }

  /**
   * Inject a command into the running server console (screen "stuff").
   * @param {string} command
   */
  async sendCommand(command) {
    const { screenName } = this.cfg;
    // Escape for screen's stuff: backslashes then double quotes, append CR.
    const payload = command.replace(/\\/g, "\\\\").replace(/"/g, '\\"');
    const cmd = `screen -S ${shellQuote(screenName)} -p 0 -X stuff ${shellQuote(payload + "\r")}`;
    const r = await this.exec(cmd);
    return { ok: r.code === 0, output: (r.stdout + r.stderr).trim() };
  }

  /** Best-effort cleanup of temp files + control socket. */
  dispose() {
    // Close the persistent ControlMaster connection (option must precede host).
    try {
      const child = spawn("ssh", ["-O", "exit", ...this._baseArgs()], {
        env: { ...process.env, SSH_ASKPASS: this._askpass, SSH_ASKPASS_REQUIRE: "force" },
        stdio: "ignore",
        detached: true,
      });
      child.unref();
    } catch {}
    try { rmSync(this._tmp, { recursive: true, force: true }); } catch {}
  }
}
