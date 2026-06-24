<?php

namespace Tests\Support;

use RuntimeException;

class FakeVimpServer
{
    private $process;
    private $pipes = [];
    private $port;
    private $logFile;

    public static function start($sourceBody = 'fake-video'): self
    {
        $server = new self();
        $server->port = self::reservePort();
        $server->logFile = tempnam(sys_get_temp_dir(), 'fake-vimp-requests-');

        $router = realpath(__DIR__ . '/../Fixtures/fake_vimp_server.php');
        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $server->port,
            escapeshellarg($router)
        );

        $server->process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $server->pipes,
            dirname($router),
            array_merge($_ENV, [
                'FAKE_VIMP_LOG' => $server->logFile,
                'FAKE_VIMP_SOURCE_BODY' => $sourceBody,
            ])
        );

        if (!is_resource($server->process)) {
            throw new RuntimeException('Could not start fake VIMP server.');
        }

        $server->waitUntilReady();

        return $server;
    }

    public function url(string $path = ''): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function requests(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        $lines = array_filter(explode(PHP_EOL, trim((string) file_get_contents($this->logFile))));

        return array_map(function ($line) {
            return json_decode($line, true);
        }, $lines);
    }

    public function requestsFor(string $path): array
    {
        return array_values(array_filter($this->requests(), function ($request) use ($path) {
            return isset($request['path']) && $request['path'] === $path;
        }));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if ($this->logFile && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private static function reservePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (!$socket) {
            throw new RuntimeException("Could not reserve port: {$errstr}", $errno);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($name, ':'), 1);
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 5;

        do {
            $context = stream_context_create(['http' => ['timeout' => 0.2]]);
            $response = @file_get_contents($this->url('/__ping'), false, $context);

            if ($response === 'ok') {
                return;
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        $stderr = isset($this->pipes[2]) && is_resource($this->pipes[2])
            ? stream_get_contents($this->pipes[2])
            : '';

        $this->stop();

        throw new RuntimeException('Fake VIMP server did not become ready. ' . $stderr);
    }
}
