<?php
// Background reporting with a deadline, cancellation and a minimal Fiber scheduler.

use DuckDB\{Database, InterruptedException};

require __DIR__ . '/bootstrap.php';

$database = new Database(':memory:', ['threads' => 2]);
$connection = $database->connect();
$pending = $connection->queryAsync('SELECT sum(i) AS total FROM range(1000000) t(i)');
$stream = $pending->getStream();
$deadline = microtime(true) + 10;
try {
    while (!$pending->isReady()) {
        if (microtime(true) >= $deadline) {
            $pending->cancel();
            break;
        }

        // Progress can be unavailable (-1); applications should show an indeterminate bar.
        $progress = $connection->queryProgress();
        if ($progress['percentage'] >= 0) {
            printf("report: %.1f%%\n", $progress['percentage']);
        }

        $read = [$stream];
        $write = $except = null;
        if (stream_select($read, $write, $except, 0, 100_000) === false) {
            throw new RuntimeException('Unable to wait for report completion');
        }
    }

    printf("report total: %s\n", $pending->await()->fetchColumn());
} catch (InterruptedException $error) {
    echo "Report exceeded its deadline\n";
} finally {
    fclose($stream);
}

// Cancellation is idempotent. Always drain the handle before reusing its connection.
foreach (['handle', 'connection'] as $method) {
    $pending = $connection->queryAsync('SELECT sum(sin(i)) FROM range(1000000000) t(i)');
    if ($method === 'handle') {
        $pending->cancel();
    } else {
        // Connection::interrupt() affects a running query, not one still starting.
        // Keep delivering the interruption until the completion signal is observed.
        while (!$pending->isReady()) {
            $connection->interrupt();

            usleep(1000);
        }
    }

    try {
        $pending->await();
        echo "Job completed before cancellation arrived\n";
    } catch (InterruptedException $error) {
        printf("Cancelled via %s (%s)\n", $method, $error->getErrorType()->name);
    }
}

// Polling mode has no completion descriptor; isReady() performs an execution slice.
// For worker mode prefer getStream(): getFd() duplicates a native handle that an
// FFI/native event loop must close using close() (Unix) or closesocket() (Windows).
$pending = $connection->queryPending('SELECT 42 AS answer');
printf("polling descriptor: %d\n", $pending->getFd());
while (!$pending->isReady()) {
    usleep(1000);
}
printf("polling answer: %d\n", $pending->await()->fetchColumn());

// A scheduler resumes the Fiber only after its yielded PendingQuery is ready.
// The framework-specific examples show integrations with production event loops.
$fiber = new Fiber(function () use ($connection): int {
    return $connection->prepare('SELECT ?::INTEGER + 1')->executeAsync([41])->suspend()->fetchColumn();
});
$waiting = $fiber->start();
while (!$fiber->isTerminated()) {
    while (!$waiting->isReady()) {
        usleep(1000);
    }

    $waiting = $fiber->resume();
}
printf("fiber answer: %d\n", $fiber->getReturn());
