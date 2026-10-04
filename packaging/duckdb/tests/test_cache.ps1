# Exercise the Windows SDK cache helpers without compiling the engine.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$builder = Join-Path $PSScriptRoot '../build-sdk.ps1'
$tokens = $null
$parseErrors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($builder, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count -ne 0) {
    throw ($parseErrors | Out-String)
}
foreach ($name in 'Test-DuckDBSdk', 'Copy-DuckDBSdk', 'Save-DuckDBSdk') {
    $function = $ast.Find({
        param($node)
        $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name
    }, $true)
    if (!$function) {
        throw "Missing SDK cache helper: $name"
    }
    Invoke-Expression $function.Extent.Text
}

function Assert-Cache {
    param([bool]$Condition, [string]$Message)

    if (!$Condition) {
        throw $Message
    }
}

$root = Join-Path ([IO.Path]::GetTempPath()) ('duckdb-cache-test-' + [guid]::NewGuid().ToString())
try {
    $Prefix = Join-Path $root 'sdk'
    $CacheDirectory = Join-Path $root 'cache'
    $cacheEntry = Join-Path $CacheDirectory 'fingerprint'
    $metadata = '{"compiler":"test","patch":"test"}'
    $artifactNames = @('include/duckdb.h', 'lib/duckdb.lib', 'bin/duckdb.dll',
        'share/duckdb-sdk/LICENSE.duckdb', 'share/duckdb-sdk/source.json',
        'share/duckdb-sdk/nullable-bitpacking.patch')
    $hashes = [ordered]@{}
    foreach ($name in $artifactNames) {
        $path = Join-Path $Prefix $name
        New-Item -ItemType Directory -Force (Split-Path -Parent $path) | Out-Null
        $name | Set-Content -LiteralPath $path
        $hashes[$name] = (Get-FileHash $path -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    $metadata | Set-Content (Join-Path $Prefix 'share/duckdb-sdk/build.txt')
    $hashes | ConvertTo-Json | Set-Content (Join-Path $Prefix 'share/duckdb-sdk/artifacts.json')
    'untrusted extra' | Set-Content (Join-Path $Prefix 'unlisted-file')
    Assert-Cache (Test-DuckDBSdk $Prefix) 'Valid SDK was rejected'
    Save-DuckDBSdk
    Assert-Cache (Test-DuckDBSdk $cacheEntry) 'Published cache failed verification'
    Assert-Cache (!(Test-Path (Join-Path $cacheEntry 'unlisted-file'))) 'Cache copied an unlisted file'
    Assert-Cache (@(Get-ChildItem $CacheDirectory -Force).Count -eq 1) 'Temporary cache directory was retained'

    Remove-Item -LiteralPath $Prefix -Recurse -Force
    Copy-DuckDBSdk $cacheEntry $Prefix
    Assert-Cache (Test-DuckDBSdk $Prefix) 'SDK restore failed verification'
    $metadata = '{"compiler":"changed","patch":"test"}'
    Assert-Cache (!(Test-DuckDBSdk $cacheEntry)) 'Changed build metadata was accepted'
    $metadata = '{"compiler":"test","patch":"test"}'
    'corruption' | Set-Content (Join-Path $cacheEntry 'bin/duckdb.dll')
    Assert-Cache (!(Test-DuckDBSdk $cacheEntry)) 'Corrupt cached artifact was accepted'
    Save-DuckDBSdk
    Assert-Cache (Test-DuckDBSdk $cacheEntry) 'Corrupt cache was not replaced'
    $hashes.Remove('bin/duckdb.dll')
    $hashes | ConvertTo-Json | Set-Content (Join-Path $cacheEntry 'share/duckdb-sdk/artifacts.json')
    Assert-Cache (!(Test-DuckDBSdk $cacheEntry)) 'Missing artifact hash was accepted'
    Remove-Item (Join-Path $Prefix 'bin/duckdb.dll')
    $rejected = $false
    try {
        Save-DuckDBSdk
    } catch {
        $rejected = $true
    }
    Assert-Cache $rejected 'Incomplete SDK was published'
    Assert-Cache (@(Get-ChildItem $CacheDirectory -Force).Count -eq 1) 'Failed publication retained a temporary directory'
    Write-Host 'Windows SDK cache helper tests passed'
} finally {
    if (Test-Path -LiteralPath $root) {
        Remove-Item -LiteralPath $root -Recurse -Force
    }
}
