--TEST--
COPY TO: registrations, sessions and writers are released on every exit
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

$dir = copy_scratch();
$db = new DuckDB\Database();
/** The handler calls so far, with repeated writes collapsed, and abort reasons. */
function calls(LinesFormat $format): string
{
    $calls = [];
    foreach ($format->log as $call) {
        $last = count($calls) - 1;
        if ($call === 'write' && $last >= 0 && str_starts_with($calls[$last], 'write')) {
            $calls[$last] = 'write x' . ((int) substr($calls[$last], 7) + 1);
            continue;
        }
        $calls[] = $call === 'write' ? 'write x1' : $call;
    }
    $reasons = array_map(fn(Throwable $reason) => $reason->getMessage(), $format->reasons);
    return json_encode(['calls' => $calls, 'abort reasons' => $reasons]);
}

$copy = fn(string $name, int $rows = 5000)
    => "COPY (SELECT range AS i FROM range($rows)) TO '$dir/$name.lines' (FORMAT lines)";

// Freeing the registering Connection lets an in-flight COPY finish.
$conn = $db->connect();
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);
$statement = $conn->prepare($copy('survivor'));
$pending = $conn->queryPending($copy('in-flight'));
unset($conn);
$pending->await();
check(count(copy_lines("$dir/in-flight.lines")) === 5000 && in_array('close', $format->log, true), 'In-flight COPY: ' . calls($format));
$error = failure(fn() => $statement->execute());
check(str_contains($error->getMessage(), 'released after this statement was prepared'), $error->getMessage());
$error = failure(fn() => $db->connect()->query($copy('gone')));
check($error instanceof DuckDB\CatalogException, $error->getMessage());
unset($pending, $statement);
echo "connection freed\n";

// Discarding a started PendingQuery aborts its writer.
$conn = $db->connect();
$conn->query('SET threads = 2');
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);
$pending = $conn->queryPending($copy('discarded', 5000000));
while (!in_array('write', $format->log, true)) {
    check(!$pending->isReady(), 'The COPY is still running');
}
unset($pending);
check(in_array('abort', $format->log, true), 'abort() ran: ' . calls($format));
check(str_contains($format->reasons[0]->getMessage(), 'PendingQuery discarded'), $format->reasons[0]->getMessage());
check($conn->query('SELECT 1 AS ok')->fetchAll() === [['ok' => 1]], 'The connection is reusable');
echo "pending discarded\n";

// A newer statement supersedes an undriven COPY, which is then aborted.
$format->reset();
$pending = $conn->queryPending($copy('superseded', 5000000));
while (!in_array('write', $format->log, true)) {
    check(!$pending->isReady(), 'The COPY is still running: ' . calls($format));
}
$conn->query($copy('newer', 3));
check(count(array_keys($format->log, 'abort')) === 1 && count(array_keys($format->log, 'close')) === 1, 'Superseded: ' . calls($format));
check(failure(fn() => $pending->await()) instanceof DuckDB\Exception, 'The superseded COPY failed: ' . calls($format));
unset($pending);
echo "pending superseded\n";

// Cancelling a COPY aborts its writer.
$format->reset();
$pending = $conn->queryPending($copy('cancelled', 5000000));
while (!in_array('write', $format->log, true)) {
    check(!$pending->isReady(), 'The COPY is still running: ' . calls($format));
}
$pending->cancel();
check(failure(fn() => $pending->await()) instanceof DuckDB\InterruptedException, 'Cancelled: ' . calls($format));
check(in_array('abort', $format->log, true), 'abort() ran after cancel(): ' . calls($format));
unset($pending);
echo "pending cancelled\n";

// Fiber switches are blocked inside handlers.
$format->reset();
$format->hooks['write'] = fn() => Fiber::suspend();
$fiber = new Fiber(fn() => failure(fn() => $conn->query($copy('fiber', 3))));
$fiber->start();
check($fiber->isTerminated(), 'The fiber never suspended');
$error = $fiber->getReturn();
$format->hooks = [];
check($error->getPrevious() instanceof FiberError, get_class($error->getPrevious() ?? $error));
// The recorded abort reason and its trace form a cycle; release it here.
$format->reset();
unset($error, $fiber);
echo "fiber blocked\n";

// Registering, copying, failing and re-registering does not leak.
$loop = $db->connect();
for ($i = 0; $i < 200; $i++) {
    $format = new LinesFormat();
    $loop->registerCopyToFunction('loop', $format);
    $loop->query("COPY (SELECT $i AS i) TO '$dir/loop.lines' (FORMAT loop)");
    $format->hooks['write'] = function () {
        throw new RuntimeException('fail');
    };
    failure(fn() => $loop->query("COPY (SELECT $i AS i) TO '$dir/loop.lines' (FORMAT loop)"));
    // The recorded abort reason's trace leads back to $format.
    $format->reset();
}
check(copy_lines("$dir/loop.lines") === [[199]], 'A failed COPY leaves the previous file in place');
echo "leak loop\n";
?>
--EXPECT--
connection freed
pending discarded
pending superseded
pending cancelled
fiber blocked
leak loop
