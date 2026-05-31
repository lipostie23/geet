<?php
/**
 * Управление SA-MP/open.mp сервером через screen.
 *
 * Поддерживает два режима работы (config[control][mode]):
 *   - 'local' — панель установлена на тот же VDS. Команды через proc_open.
 *   - 'ssh'   — панель на отдельной машине, используется sshpass + ssh.
 *
 * Все аргументы оболочки экранируются shq() — никаких уязвимостей инъекции
 * через имя скрин-сессии или путь к серверу.
 */
declare(strict_types=1);

final class ServerControl
{
    /** @var array конфигурация секции 'control' */
    private array $cfg;

    public function __construct(array $controlCfg)
    {
        $this->cfg = $controlCfg;
    }

    // =========================== ПУБЛИЧНЫЙ API ============================

    /** Жив ли screen? */
    public function status(): array
    {
        $name = self::shq((string)$this->cfg['screen_name']);
        $r = $this->exec("screen -ls 2>/dev/null | grep -qw $name && echo RUNNING || echo STOPPED");
        return [
            'running' => str_contains($r['stdout'], 'RUNNING'),
            'raw'     => trim($r['stdout']),
            'stderr'  => trim($r['stderr']),
            'code'    => $r['code'],
        ];
    }

    /** Запустить сервер в detached screen-сессии. */
    public function start(): array
    {
        $name = self::shq((string)$this->cfg['screen_name']);
        $path = self::shq((string)$this->cfg['server_path']);
        $exe  = self::shq('./' . $this->cfg['executable']);

        $cmd =
            "cd $path 2>/dev/null && " .
            "chmod +x $exe 2>/dev/null; " .
            "if screen -ls 2>/dev/null | grep -qw $name; then " .
            "  echo ALREADY_RUNNING; " .
            "else " .
            "  screen -dmS $name $exe && echo STARTED || echo START_FAILED; " .
            "fi";

        $r = $this->exec($cmd, 30);
        return [
            'ok'     => $r['code'] === 0,
            'output' => trim($r['stdout'] . $r['stderr']),
        ];
    }

    /**
     * Корректно остановить: послать команду 'exit' в консоль, дать секунду на
     * graceful shutdown, после чего убить screen на всякий случай.
     */
    public function stop(): array
    {
        $name = self::shq((string)$this->cfg['screen_name']);
        // $'...\n' в bash интерпретирует управляющие последовательности.
        $cmd =
            "screen -S $name -p 0 -X stuff $'exit\\n' 2>/dev/null; " .
            "sleep 1; " .
            "screen -S $name -X quit 2>/dev/null; echo STOPPED";

        $r = $this->exec($cmd, 15);
        return [
            'ok'     => true,
            'output' => trim($r['stdout'] . $r['stderr']),
        ];
    }

    public function restart(): array
    {
        $this->stop();
        sleep(2);
        return $this->start();
    }

    /** Отправить команду в консоль работающего сервера. */
    public function sendCommand(string $command): array
    {
        $name = self::shq((string)$this->cfg['screen_name']);
        // \r — то же, что Enter в screen stuff.
        $payload = self::shq($command . "\r");
        $r = $this->exec("screen -S $name -p 0 -X stuff $payload");
        return [
            'ok'     => $r['code'] === 0,
            'output' => trim($r['stdout'] . $r['stderr']),
        ];
    }

    /**
     * Запустить tail -F на лог-файле сервера и для каждой строки вызывать $emit.
     * Блокирующий вызов: используется внутри SSE-эндпоинта console.php.
     * Завершается, когда отвалится клиент (connection_aborted) или умрёт tail.
     */
    public function tailLogStream(callable $emit, ?callable $shouldContinue = null): void
    {
        $logPath = rtrim((string)$this->cfg['server_path'], '/') . '/' . $this->cfg['log_file'];
        $tailCmd = "tail -n 200 -F " . self::shq($logPath) . " 2>/dev/null";

        // Собираем итоговую командную строку с учётом режима.
        $cmd = $this->wrapCommand($tailCmd, /*tty*/ false);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', '-c', $cmd], $descriptors, $pipes);
        if (!is_resource($proc)) {
            $emit("[panel] не удалось запустить tail");
            return;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer = '';
        try {
            while (true) {
                if (connection_aborted()) break;
                if ($shouldContinue !== null && !$shouldContinue()) break;

                $read   = [$pipes[1], $pipes[2]];
                $write  = null;
                $except = null;
                $ready  = @stream_select($read, $write, $except, 1, 0);
                if ($ready === false) break;

                if ($ready > 0) {
                    foreach ($read as $stream) {
                        $chunk = fread($stream, 8192);
                        if ($chunk === false || $chunk === '') continue;
                        $buffer .= $chunk;
                        // Разбираем по строкам, остаток оставляем в буфере.
                        while (($nlPos = strpos($buffer, "\n")) !== false) {
                            $line   = rtrim(substr($buffer, 0, $nlPos), "\r");
                            $buffer = substr($buffer, $nlPos + 1);
                            if ($line !== '') $emit($line);
                        }
                    }
                }

                $st = proc_get_status($proc);
                if (!$st['running']) break;
            }
        } finally {
            foreach ([$pipes[1] ?? null, $pipes[2] ?? null] as $p) {
                if (is_resource($p)) fclose($p);
            }
            @proc_terminate($proc, 9);
            @proc_close($proc);
        }
    }

    // =========================== ВНУТРЕННЕЕ ===============================

    /** Безопасное single-quote экранирование для POSIX-shell. */
    private static function shq(string $s): string
    {
        return "'" . str_replace("'", "'\\''", $s) . "'";
    }

    /**
     * Обернуть удалённую команду нужным префиксом в зависимости от режима:
     *   local + run_as_user        -> sudo -n -u <user> bash -c '<cmd>'
     *   local                      -> <cmd>  (как есть)
     *   ssh                        -> sshpass -p <pw> ssh ... <user>@<host> <cmd>
     */
    private function wrapCommand(string $remoteCmd, bool $tty = false): string
    {
        $mode = (string)($this->cfg['mode'] ?? 'local');

        if ($mode === 'ssh') {
            $ssh   = $this->cfg['ssh'] ?? [];
            $port  = (int)($ssh['port'] ?? 22);
            $user  = (string)($ssh['user'] ?? 'root');
            $host  = (string)($ssh['host'] ?? '127.0.0.1');
            $pwd   = (string)($ssh['password'] ?? '');

            $opts = '-o StrictHostKeyChecking=accept-new -o LogLevel=ERROR '
                  . '-o ConnectTimeout=10 -o ServerAliveInterval=15';
            $tFlag = $tty ? '-tt' : '-T';

            return sprintf(
                'sshpass -p %s ssh %s %s -p %d %s %s',
                self::shq($pwd),
                $opts,
                $tFlag,
                $port,
                self::shq($user . '@' . $host),
                self::shq($remoteCmd)
            );
        }

        // local-режим
        if (!empty($this->cfg['run_as_user'])) {
            $u = self::shq((string)$this->cfg['run_as_user']);
            return "sudo -n -u $u bash -c " . self::shq($remoteCmd);
        }
        return $remoteCmd;
    }

    /**
     * Выполнить команду с буферизацией stdout/stderr и таймаутом.
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function exec(string $remoteCmd, int $timeout = 15): array
    {
        $cmd = $this->wrapCommand($remoteCmd, /*tty*/ false);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', '-c', $cmd], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'failed to spawn'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;

        while (true) {
            if (microtime(true) > $deadline) {
                @proc_terminate($proc, 9);
                $stderr .= "\n[panel] timeout after {$timeout}s";
                break;
            }
            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;
            $ready  = @stream_select($read, $write, $except, 1, 0);
            if ($ready === false) break;
            if ($ready > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') continue;
                    if ($stream === $pipes[1]) $stdout .= $chunk;
                    else                       $stderr .= $chunk;
                }
            }
            $st = proc_get_status($proc);
            if (!$st['running']) {
                // дочитать остатки
                $stdout .= (string)stream_get_contents($pipes[1]);
                $stderr .= (string)stream_get_contents($pipes[2]);
                break;
            }
        }

        foreach ([$pipes[1] ?? null, $pipes[2] ?? null] as $p) {
            if (is_resource($p)) fclose($p);
        }
        $code = proc_close($proc);
        return ['code' => (int)$code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
