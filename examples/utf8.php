<?php
require __DIR__ . '/bootstrap.php';

use function DuckDB\checkUtf8;
use function DuckDB\isValidUtf8;

// No database or connection is needed to check incoming text.
foreach (['Copenhagen', 'København', "\xF0\x9F\x8C\x8D", "bad\xFF"] as $text) {
    echo bin2hex($text), ': ', isValidUtf8($text) ? 'valid' : 'invalid', "\n";
}

checkUtf8('Valid text');
checkUtf8("a\0b"); // A NUL is valid UTF-8.

try {
    checkUtf8("a\0\xFF"); // Validation includes the bytes after a NUL.
} catch (DuckDB\Exception $error) {
    if ($error->getErrorType() !== DuckDB\ErrorType::InvalidInput) {
        throw $error;
    }

    echo 'Rejected invalid UTF-8: ', $error->getErrorType()->name, "\n";
}
