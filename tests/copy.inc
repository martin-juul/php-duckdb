<?php
/**
 * Shared COPY TO formats for the tests/11x_copy_*.phpt suite.
 *
 * LinesFormat writes one JSON-encoded row per line and records every
 * handler call in $log, so tests can assert both the file and the call
 * sequence. Hooks let a test throw from, or act inside, any method.
 */

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Run $operation and return the Throwable it must throw. */
function failure(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }

    throw new RuntimeException('Expected a failure');
}

final class LinesWriter implements DuckDB\CopyToWriter
{
    /** @var resource */
    private $handle;
    public int $rows = 0;

    public function __construct(private LinesFormat $format, public readonly string $path)
    {
        $this->handle = fopen($path, 'w');
    }

    public function write(DuckDB\DataChunk $batch): void
    {
        $this->format->log[] = 'write';
        $this->format->hook('write', $batch, $this);
        foreach ($batch->toRows(DuckDB\FetchMode::Num) as $row) {
            fwrite($this->handle, json_encode($row) . "\n");
            $this->rows++;
        }
    }

    public function close(): void
    {
        $this->format->log[] = 'close';
        $this->format->hook('close', null, $this);
        fclose($this->handle);
    }

    public function abort(Throwable $reason): void
    {
        $this->format->log[] = 'abort';
        $this->format->reasons[] = $reason;
        $this->format->hook('abort', $reason, $this);
        fclose($this->handle);
    }
}

final class LinesFormat implements DuckDB\CopyToFunction
{
    /** @var list<string> */
    public array $log = [];
    /** @var list<array{array, array}> */
    public array $binds = [];
    /** @var list<string> */
    public array $paths = [];
    /** @var list<Throwable> */
    public array $reasons = [];
    /** @var array<string, callable> */
    public array $hooks = [];

    public function bind(array $columnTypes, array $options): void
    {
        $this->log[] = 'bind';
        $this->binds[] = [$columnTypes, $options];
        $this->hook('bind', null, null);
    }

    public function open(string $path, array $columnTypes, array $options): DuckDB\CopyToWriter
    {
        $this->log[] = 'open';
        $this->paths[] = $path;
        $this->hook('open', null, null);
        return new LinesWriter($this, $path);
    }

    public function hook(string $method, mixed $argument, ?LinesWriter $writer): void
    {
        if (isset($this->hooks[$method])) {
            ($this->hooks[$method])($argument, $writer);
        }
    }

    public function reset(): void
    {
        $this->log = [];
        $this->binds = [];
        $this->paths = [];
        $this->reasons = [];
    }
}

/** A scratch directory removed at shutdown. */
function copy_scratch(): string
{
    $dir = sys_get_temp_dir() . '/duckdb-copy-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($dir);
    register_shutdown_function(function () use ($dir) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    });
    return $dir;
}

/** @return list<string> the decoded lines of a LinesFormat file */
function copy_lines(string $path): array
{
    return array_map(fn($line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES));
}
