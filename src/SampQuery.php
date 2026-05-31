<?php
/**
 * SA-MP / open.mp UDP query protocol client (чистый PHP, без расширений).
 *
 * Формат запроса:
 *   "SAMP" (4 байта) + ip(4 байта) + port(2 байта LE) + opcode(1 байт)
 *
 * Опкоды:
 *   'i' = info  — hostname, players, maxplayers, gamemode, language, password flag
 *   'r' = rules — мапа ключ/значение (mapname, version, weather, ...)
 *
 * Ответ начинается с 11-байтного эха заголовка, далее payload.
 * Все числа — little-endian. Строки префиксированы длиной (4 байта в info / 1 байт в rules).
 */
declare(strict_types=1);

final class SampQuery
{
    /**
     * Запросить статус сервера. При ошибке возвращает массив с online=false и заполненным error.
     */
    public static function query(string $ip, int $port, float $timeout = 1.5): array
    {
        $out = [
            'online'     => false,
            'hostname'   => null,
            'players'    => 0,
            'maxPlayers' => 0,
            'gamemode'   => null,
            'language'   => null,
            'passworded' => false,
            'rules'      => new stdClass(),
            'ping'       => null,
            'error'      => null,
        ];

        $ipParts = array_map('intval', explode('.', $ip));
        if (count($ipParts) !== 4) {
            $out['error'] = 'invalid ip';
            return $out;
        }

        try {
            $info = self::sendQuery($ip, $port, 'i', $timeout, $pingMs);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }

        try {
            self::parseInfo($info, $out);
            $out['online'] = true;
            $out['ping']   = $pingMs;
        } catch (Throwable $e) {
            $out['error'] = 'parse error: ' . $e->getMessage();
            return $out;
        }

        // Rules — best effort, не критичны.
        try {
            $rules = self::sendQuery($ip, $port, 'r', $timeout, $unused);
            $out['rules'] = self::parseRules($rules);
        } catch (Throwable $e) {
            // тихо игнорируем
        }

        return $out;
    }

    /** Отправить один UDP-запрос и вернуть payload (после эха заголовка). */
    private static function sendQuery(string $ip, int $port, string $opcode, float $timeout, ?int &$pingMs): string
    {
        $ipParts = array_map('intval', explode('.', $ip));
        $packet  = 'SAMP'
            . chr($ipParts[0] & 0xff) . chr($ipParts[1] & 0xff)
            . chr($ipParts[2] & 0xff) . chr($ipParts[3] & 0xff)
            . pack('v', $port & 0xffff) . $opcode;

        $errno = 0; $errstr = '';
        $sock = @stream_socket_client("udp://$ip:$port", $errno, $errstr, $timeout);
        if (!$sock) {
            throw new RuntimeException("connect: $errstr");
        }
        $secs  = (int)$timeout;
        $usecs = (int)(($timeout - $secs) * 1_000_000);
        stream_set_timeout($sock, $secs, $usecs);

        $t0 = microtime(true);
        @fwrite($sock, $packet);
        $resp = @fread($sock, 4096);
        $pingMs = (int)round((microtime(true) - $t0) * 1000);
        @fclose($sock);

        if ($resp === false || strlen($resp) < 11) {
            throw new RuntimeException('timeout / no response');
        }
        return substr($resp, 11);
    }

    /** Парсинг payload опкода 'i'. Заполняет hostname/players/etc прямо в $out. */
    private static function parseInfo(string $payload, array &$out): void
    {
        $offset = 0;
        $passworded = ord($payload[$offset]) === 1; $offset += 1;
        $players    = self::u16($payload, $offset);
        $maxPlayers = self::u16($payload, $offset);
        $hostname   = self::str32($payload, $offset);
        $gamemode   = self::str32($payload, $offset);
        $language   = self::str32($payload, $offset);

        $out['passworded'] = $passworded;
        $out['players']    = $players;
        $out['maxPlayers'] = $maxPlayers;
        $out['hostname']   = self::decode($hostname);
        $out['gamemode']   = self::decode($gamemode);
        $out['language']   = self::decode($language);
    }

    /** Парсинг payload опкода 'r' — пары ключ/значение со строками длины 1 байт. */
    private static function parseRules(string $payload): array
    {
        $offset = 0;
        $count  = self::u16($payload, $offset);
        $rules  = [];
        for ($i = 0; $i < $count; $i++) {
            $key = self::str8($payload, $offset);
            $val = self::str8($payload, $offset);
            $rules[self::decode($key)] = self::decode($val);
        }
        return $rules;
    }

    /** Прочитать unsigned int16 little-endian, продвинуть offset. */
    private static function u16(string $b, int &$offset): int
    {
        $v = unpack('v', substr($b, $offset, 2));
        $offset += 2;
        return $v[1];
    }

    /** Прочитать строку с 4-байтным префиксом длины (LE). */
    private static function str32(string $b, int &$offset): string
    {
        $len = unpack('V', substr($b, $offset, 4))[1];
        $offset += 4;
        $s = substr($b, $offset, $len);
        $offset += $len;
        return $s;
    }

    /** Прочитать строку с 1-байтным префиксом длины. */
    private static function str8(string $b, int &$offset): string
    {
        $len = ord($b[$offset]);
        $offset += 1;
        $s = substr($b, $offset, $len);
        $offset += $len;
        return $s;
    }

    /**
     * SA-MP сервера в РФ часто шлют hostname в Windows-1251.
     * Если это валидный UTF-8 — оставляем как есть, иначе конвертируем из CP1251.
     */
    private static function decode(string $s): string
    {
        if ($s === '') return $s;
        if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        if (function_exists('mb_convert_encoding')) {
            $r = @mb_convert_encoding($s, 'UTF-8', 'Windows-1251');
            if (is_string($r) && $r !== '') return $r;
        }
        if (function_exists('iconv')) {
            $r = @iconv('CP1251', 'UTF-8//IGNORE', $s);
            if (is_string($r) && $r !== '') return $r;
        }
        return $s;
    }
}
