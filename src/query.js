// src/query.js
// SA-MP / open.mp UDP query protocol client. Zero npm dependencies (node:dgram).
//
// Packet format (request):
//   "SAMP" (4 bytes) + ip(4 bytes) + port(2 bytes LE) + opcode(1 byte)
// Opcodes:
//   'i' = info   (server name, players, maxplayers, gamemode, language, password)
//   'r' = rules  (lagcomp, mapname, version, weather, weburl, worldtime, ...)
//   'c' = clients (list of player name + score)
//
// All multi-byte integers in the payload are little-endian.
// Strings are length-prefixed (the prefix width varies per field, see below).

import dgram from "node:dgram";

function buildPacket(ip, port, opcode) {
  const parts = ip.split(".").map((n) => parseInt(n, 10));
  const buf = Buffer.alloc(11);
  buf.write("SAMP", 0, "ascii");
  buf[4] = parts[0] & 0xff;
  buf[5] = parts[1] & 0xff;
  buf[6] = parts[2] & 0xff;
  buf[7] = parts[3] & 0xff;
  buf.writeUInt16LE(port & 0xffff, 8);
  buf.write(opcode, 10, "ascii");
  return buf;
}

/**
 * Send a single query opcode and resolve with the raw payload (after the
 * 11-byte echoed header).
 */
function rawQuery(ip, port, opcode, timeout = 1500) {
  return new Promise((resolve, reject) => {
    const socket = dgram.createSocket("udp4");
    const packet = buildPacket(ip, port, opcode);
    let done = false;

    const finish = (err, data) => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      try { socket.close(); } catch {}
      if (err) reject(err);
      else resolve(data);
    };

    const timer = setTimeout(() => finish(new Error("query timeout")), timeout);

    socket.on("error", (err) => finish(err));
    socket.on("message", (msg) => {
      // First 11 bytes echo the request header; payload follows.
      if (msg.length < 11) return finish(new Error("short response"));
      finish(null, msg.subarray(11));
    });

    socket.send(packet, port, ip, (err) => {
      if (err) finish(err);
    });
  });
}

/** Cursor helper for sequential little-endian reads of the payload buffer. */
class Reader {
  constructor(buf) {
    this.buf = buf;
    this.off = 0;
  }
  u8() {
    const v = this.buf.readUInt8(this.off);
    this.off += 1;
    return v;
  }
  u16() {
    const v = this.buf.readUInt16LE(this.off);
    this.off += 2;
    return v;
  }
  i32() {
    const v = this.buf.readInt32LE(this.off);
    this.off += 4;
    return v;
  }
  // String prefixed with a 4-byte length.
  str32() {
    const len = this.i32();
    const s = this.buf.subarray(this.off, this.off + len).toString("latin1");
    this.off += len;
    return s;
  }
  // String prefixed with a 1-byte length.
  str8() {
    const len = this.u8();
    const s = this.buf.subarray(this.off, this.off + len).toString("latin1");
    this.off += len;
    return s;
  }
}

/** Parse the 'i' (info) payload. */
function parseInfo(payload) {
  const r = new Reader(payload);
  const passworded = r.u8() === 1;
  const players = r.u16();
  const maxPlayers = r.u16();
  const hostname = r.str32();
  const gamemode = r.str32();
  const language = r.str32();
  return { passworded, players, maxPlayers, hostname, gamemode, language };
}

/** Parse the 'r' (rules) payload into a key/value object. */
function parseRules(payload) {
  const r = new Reader(payload);
  const count = r.u16();
  const rules = {};
  for (let i = 0; i < count; i++) {
    const key = r.str8();
    const val = r.str8();
    rules[key] = val;
  }
  return rules;
}

/**
 * High level: query a server and return a normalized status object.
 * Never throws — on failure returns { online:false, error }.
 */
export async function queryServer(ip, port, timeout = 1500) {
  const out = {
    online: false,
    hostname: null,
    players: 0,
    maxPlayers: 0,
    gamemode: null,
    language: null,
    passworded: false,
    rules: {},
    ping: null,
    error: null,
  };
  const t0 = Date.now();
  try {
    const info = parseInfo(await rawQuery(ip, port, "i", timeout));
    out.online = true;
    out.ping = Date.now() - t0;
    Object.assign(out, info);
    // Rules are best-effort; ignore failures.
    try {
      out.rules = parseRules(await rawQuery(ip, port, "r", timeout));
    } catch {}
    return out;
  } catch (err) {
    out.error = err.message || String(err);
    return out;
  }
}
