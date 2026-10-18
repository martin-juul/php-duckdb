--TEST--
PHP scalar functions: each initialized worker has independent state and executions start fresh
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class StatefulScalar implements DuckDB\ScalarFunction
{
    public array $workers = [];
    public int $next = 0;
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        return new class($this) implements DuckDB\ScalarBinding {
            public function __construct(private StatefulScalar $owner)
            {
            }
            public function init(): DuckDB\ScalarExecutor
            {
                $id = $this->owner->next++;
                $this->owner->workers[$id] = [];
                return new class($this->owner, $id) implements DuckDB\ScalarExecutor {
                    private int $seen = 0;
                    public function __construct(private StatefulScalar $owner, private int $id)
                    {
                    }
                    public function execute(DuckDB\DataChunk $arguments): array
                    {
                        $out = [];
                        for ($i = 0; $i < $arguments->rowCount(); ++$i) {
                            $out[] = ++$this->seen;
                        }
                        array_push($this->owner->workers[$this->id], ...$out);
                        return $out;
                    }
                };
            }
        };
    }
}

$conn = (new DuckDB\Database())->connect();
$handler = new StatefulScalar();
$conn->registerScalarFunction('php_stateful', ['BIGINT'], 'BIGINT', $handler);
$conn->query('SET threads = 1');
$prepared = $conn->prepare('SELECT php_stateful(i) FROM range(5000) t(i)');
foreach ([1, 2] as $execution) {
    check(array_column($prepared->execute()->fetchAll(DuckDB\FetchMode::Num), 0) === range(1, 5000),
        'Prepared execution starts with fresh local state and carries state across batches');
    echo "execution $execution\n";
}

$conn->query('SET threads = 4');
$before = $handler->next;
$results = $conn->query('SELECT php_stateful(i) FROM range(20000) t(i)')->fetchAll(DuckDB\FetchMode::Num);
$workers = array_slice($handler->workers, $before, null, true);
check($workers !== [], 'At least one execution worker was initialized');
$count = 0;
$expected = [];
foreach ($workers as $values) {
    // DuckDB chooses the degree of parallelism. Each used executor must
    // maintain its own sequence even if this plan uses only one worker.
    if ($values !== []) {
        check($values === range(1, count($values)), 'Worker state begins at one and stays independent');
        $count += count($values);
        array_push($expected, ...$values);
    }
}
$actual = array_column($results, 0);
sort($actual);
sort($expected);
check($count === 20000 && $actual === $expected, 'Every result belongs to one independent worker state');
echo "worker-local sequences\n";
?>
--EXPECT--
execution 1
execution 2
worker-local sequences
