--TEST--
UTF-8 checks: Unicode scalar values, malformed sequences, embedded NULs and argument types
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$valid = [
    'empty' => '',
    'ASCII' => "DuckDB 42\n",
    'two-byte minimum' => "\xC2\x80",
    'three-byte minimum' => "\xE0\xA0\x80",
    'three-byte euro' => "\xE2\x82\xAC",
    'four-byte minimum' => "\xF0\x90\x80\x80",
    'four-byte emoji' => "\xF0\x9F\x98\x80",
    'combining mark' => "e\xCC\x81",
    'BOM' => "\xEF\xBB\xBF",
    'embedded NULs' => "\0A\0",
    'U+10FFFF' => "\xF4\x8F\xBF\xBF",
    'noncharacters' => "\xEF\xB7\x90\xEF\xBF\xBE\xEF\xBF\xBF",
];
foreach ($valid as $name => $value) {
    check(DuckDB\isValidUtf8($value) === true, "$name is valid");
    check(DuckDB\checkUtf8($value) === null, "$name check returns void");
}
echo "valid Unicode and binary-safe strings\n";

$invalid = [
    'lone continuation' => "\x80",
    'truncated two-byte' => "\xC2",
    'truncated three-byte' => "\xE2\x82",
    'truncated four-byte' => "\xF0\x90\x80",
    'ASCII continuation' => "\xE2A\xAC",
    'overlong two-byte' => "\xC0\xAF",
    'overlong two-byte upper lead' => "\xC1\xBF",
    'overlong three-byte' => "\xE0\x80\xAF",
    'overlong four-byte' => "\xF0\x80\x80\xAF",
    'high surrogate' => "\xED\xA0\x80",
    'low surrogate' => "\xED\xBF\xBF",
    'above U+10FFFF' => "\xF4\x90\x80\x80",
    'legacy five-byte' => "\xF8\x88\x80\x80\x80",
    'invalid lead' => "\xFF",
    'invalid after NUL' => "valid\0\x80",
];
foreach ($invalid as $name => $value) {
    check(DuckDB\isValidUtf8($value) === false, "$name is invalid");
    try {
        DuckDB\checkUtf8($value);
        throw new RuntimeException("$name did not throw");
    } catch (DuckDB\Exception $error) {
        check($error->getCode() === 32, "$name has InvalidInput code");
        check($error->getErrorType() === DuckDB\ErrorType::InvalidInput, "$name has InvalidInput type");
    }
}
echo "malformed sequences rejected consistently\n";

foreach (['DuckDB\\isValidUtf8' => 'bool', 'DuckDB\\checkUtf8' => 'void'] as $function => $returnType) {
    $reflection = new ReflectionFunction($function);
    check($reflection->getNumberOfRequiredParameters() === 1, "$function requires one argument");
    check((string) $reflection->getParameters()[0]->getType() === 'string', "$function accepts a string");
    check((string) $reflection->getReturnType() === $returnType, "$function return type");
    foreach ([null, 1, 1.5, true, [], new stdClass()] as $value) {
        try {
            $function($value);
            throw new RuntimeException("$function accepted a non-string argument");
        } catch (TypeError $error) {
        }
    }
}
echo "string arguments and public signatures\n";
?>
--EXPECT--
valid Unicode and binary-safe strings
malformed sequences rejected consistently
string arguments and public signatures
