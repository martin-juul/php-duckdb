# Roadmap

The extension provides binding primitives for a future Doctrine DBAL driver
maintained in a separate repository. It has no Doctrine dependency. Native
classes such as `DuckDB\Decimal` and `DuckDB\ListValue` let custom DBAL types
return a typed value without opening a connection. They extend `DuckDB\Value`,
which remains an advanced declaration escape hatch. The consuming connection
resolves catalog types and casts values.

The intended PR sequence is:

1. Full typed binding, including geometry and CRS preservation.
2. Value string rendering.
3. Arrow schema/chunk conversion.
4. Standalone vectors.
5. Selection vectors.
6. Custom COPY functions.
7. Public scalar bind/init callbacks.
8. Table-function metadata.
9. Public expression APIs.
10. Custom logging.
11. UTF-8 checks.
12. Additional geometry CRS APIs.

Later APIs extend capabilities; complete type input support belongs in the first
change. The internal native capture callback is not a public PHP UDF API.

A DBAL adapter can pass wrappers to `Statement::bindValue()` and translate
extension exceptions to its driver exceptions. DuckDB's 1-based positions and
named binding work independently of DBAL's parameter-type identifiers. DBAL
parameter-list expansion produces multiple SQL placeholders; a typed DuckDB LIST
occupies one placeholder. See
[the adapter fixture](../examples/typed_adapter.php).

The
[DBAL driver architecture](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.5/reference/architecture.html)
places custom type conversion above the driver. Its
[Statement interface](https://github.com/doctrine/dbal/blob/4.4.x/src/Driver/Statement.php)
accepts an integer/string parameter, mixed value and `ParameterType`, returns
`void` from `bindValue()` and a driver `Result` from `execute()`. An adapter
therefore translates parameter types and fluent return values rather than
implementing those interfaces directly in this extension. Connection adaptation
also needs quoting, `exec`, last-insert IDs, native-connection access,
transactions and server-version reporting. Those belong to the separate driver.
