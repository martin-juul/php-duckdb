<?php
// Examples bootstrap: verifies the extension is loaded.

if (!extension_loaded('duckdb')) {
    fwrite(STDERR, "The duckdb extension is not loaded. Build and install it first (see README.md).\n");
    exit(1);
}
