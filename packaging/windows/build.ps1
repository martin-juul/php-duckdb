param(
    [Parameter(Mandatory)][ValidateSet('8.2', '8.3', '8.4', '8.5')][string]$PhpVersion,
    [Parameter(Mandatory)][ValidateSet('ts', 'nts')][string]$ThreadSafety,
    [Parameter(Mandatory)][string]$BuilderPath,
    [Parameter(Mandatory)][string]$BuildRoot
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-DuckDBPython3 {
    foreach ($name in 'python3', 'python') {
        $candidate = Get-Command $name -ErrorAction SilentlyContinue
        if (!$candidate) {
            continue
        }
        try {
            $major = & $candidate -c 'import sys; print(sys.version_info.major)' 2>$null
            if ($LASTEXITCODE -eq 0 -and "$major".Trim() -eq '3') {
                return $candidate
            }
        } catch {
            # Try the other command, including when a Windows app alias fails.
        }
    }
    throw 'Python 3 is required; install python3 or python on PATH'
}

$python = Get-DuckDBPython3
$source = (Get-Location).Path
$build = Join-Path $BuildRoot 'extension'
$deps = Join-Path $BuildRoot 'deps'
New-Item -ItemType Directory -Force $build, "$deps/include", "$deps/lib", "$deps/bin" | Out-Null
# Keep the original checkout (including pull-request merge changes). Build tools
# live outside it, so robocopy cannot accidentally pick another config.w32.
& robocopy $source $build /E /XD "$source/.git" /XJ /R:2 /W:1 /NFL /NDL /NJH /NJS /NP
if ($LASTEXITCODE -ge 8) {
    throw "Source copy failed: $LASTEXITCODE"
}
Import-Module "$BuilderPath/extension/BuildPhpExtension/BuildPhpExtension.psd1" -Force
$env:AUTO_DETECT_ARGS = 'false'
$env:AUTO_DETECT_LIBS = 'false'
$env:RUN_TESTS = 'false'
$env:CONFIGURE_ARGS = "--with-duckdb=$deps"
$env:LIBRARIES = ''
Push-Location $build
try {
    # Reviewed exported functions from the SHA pinned in packaging.yml. Calling
    # them separately provides the dependency staging hook the composite action
    # does not expose, and avoids its automatic artifact upload.
    $vs = Get-VsVersion -PhpVersion $PhpVersion
    $expectedCompiler = if ($PhpVersion -in '8.2', '8.3') { 'vs16' } else { 'vs17' }
    if ($vs.vs -ne $expectedCompiler) {
        throw "Unexpected compiler: $($vs.vs)"
    }
    Get-PhpSdk
    $config = Get-ExtensionConfig -Extension duckdb -ExtensionRef $env:GITHUB_SHA `
        -PhpVersion $PhpVersion -Arch x64 -Ts $ThreadSafety -VsVersion $vs.vs -VsToolset $vs.toolset
    # Avoid a duplicate --with-duckdb=shared emitted from composer.json.
    $config.options = "--with-duckdb=$deps"
    $details = Get-PhpBuildDetails -Config $config
    $php = Get-PhpBuild -Config $config -BuildDetails $details
    Get-PhpDevelBuild -Config $config -BuildDetails $details | Out-Null
    Add-Dependencies -Config $config -Prefix $php

    # Build the hash-pinned source with all engine patches.
    # Engine VS2022 selection is independent of PHP's vs16/vs17 selection.
    $duck = Join-Path $BuildRoot 'duckdb-sdk'
    & "$source/packaging/duckdb/build-sdk.ps1" -Prefix $duck `
        -WorkDirectory (Join-Path $BuildRoot 'duckdb-sdk-build')
    $duckPins = Get-Content "$duck/share/duckdb-sdk/source.json" -Raw | ConvertFrom-Json
    $duckVersion = $duckPins.version
    foreach ($file in 'include/duckdb.h', 'lib/duckdb.lib', 'bin/duckdb.dll') {
        if (!(Test-Path "$duck/$file" -PathType Leaf)) {
            throw "Missing DuckDB dependency: $file"
        }
    }
    Copy-Item "$duck/include/duckdb.h" "$deps/include/duckdb.h"
    Copy-Item "$duck/lib/duckdb.lib" "$deps/lib/duckdb.lib"
    Copy-Item "$duck/bin/duckdb.dll" "$deps/bin/duckdb.dll"
    Invoke-Build -Config $config
    $extension = Join-Path $build "$($config.build_directory)/php_duckdb.dll"
    if (!(Test-Path $extension -PathType Leaf)) {
        throw 'Build did not produce php_duckdb.dll'
    }

    # Put the dependency next to the matching PHP executable, just as users do.
    Copy-Item "$duck/bin/duckdb.dll" "$php/duckdb.dll"
    foreach ($file in 'php_ffi.dll', 'php_sockets.dll') {
        if (!(Test-Path "$php/ext/$file")) {
            throw "Missing test extension: $file"
        }
    }

    $runner = Join-Path $build 'run-tests.php'
    Invoke-WebRequest "https://raw.githubusercontent.com/php/php-src/php-$($details.phpSemver)/run-tests.php" -OutFile $runner
    $env:DUCKDB_EXTENSION_PATH = $extension
    $env:TEST_PHP_EXECUTABLE = "$php/php.exe"
    $env:TEST_PHP_ARGS = "-n -d extension_dir=`"$php/ext`" -d extension=php_ffi.dll -d ffi.enable=true -d extension=php_sockets.dll -d extension=`"$extension`""
    $env:REPORT_EXIT_STATUS = '1'
    # Child processes launched by PHPT helpers inherit dependency search paths.
    $env:PATH = "$php;$env:PATH"
    $testJobs = 0
    if (![string]::IsNullOrEmpty($env:DUCKDB_JOBS)) {
        if ($env:DUCKDB_JOBS -notmatch '^[1-9][0-9]*$' -or
            ![int]::TryParse($env:DUCKDB_JOBS, [ref]$testJobs) -or $testJobs -lt 1) {
            throw 'DUCKDB_JOBS must be a positive integer'
        }
    } else {
        $selectedJobs = & $python (Join-Path $source 'packaging/resources/jobs.py') --profile test
        if ($LASTEXITCODE -ne 0 -or "$selectedJobs" -notmatch '^[1-9][0-9]*$' -or
            ![int]::TryParse("$selectedJobs", [ref]$testJobs) -or $testJobs -lt 1) {
            throw 'Cannot select PHPT workers from packaging/resources/jobs.py'
        }
    }
    Write-Host "PHPT workers: $testJobs"
    & "$php/php.exe" -n $runner -q "-j$testJobs" --offline --show-diff --set-timeout 120 tests
    if ($LASTEXITCODE -ne 0) {
        throw "PHPT suite failed: $LASTEXITCODE"
    }

    $versionMatch = [regex]::Match((Get-Content "$source/php_duckdb.h" -Raw), 'PHP_DUCKDB_VERSION\s+"([^"]+)"')
    if (!$versionMatch.Success) {
        throw 'Cannot read extension version'
    }

    $version = $versionMatch.Groups[1].Value
    $package = Join-Path $BuildRoot 'package'
    New-Item -ItemType Directory $package | Out-Null
    Copy-Item $extension "$package/php_duckdb.dll"
    Copy-Item "$duck/bin/duckdb.dll" "$package/duckdb.dll"
    Copy-Item "$source/LICENSE" "$package/LICENSE.php-duckdb"
    Copy-Item "$duck/share/duckdb-sdk/LICENSE.duckdb" "$package/LICENSE.duckdb"
    Copy-Item "$duck/share/duckdb-sdk" "$package/duckdb-sdk" -Recurse
    Copy-Item "$source/packaging/windows/README.md" "$package/INSTALL.md"
    @{
        extension_version = $version
        duckdb_version = $duckVersion
        php_version = $details.phpSemver
        php_minor = $PhpVersion
        thread_safety = $ThreadSafety
        compiler = $vs.vs
        architecture = 'x64'
        source_commit = $env:GITHUB_SHA
        duckdb_source_commit = $duckPins.commit
        duckdb_source_sha256 = $duckPins.sha256
        duckdb_patch_sha256 = (Get-FileHash "$duck/share/duckdb-sdk/nullable-bitpacking.patch" -Algorithm SHA256).Hash.ToLowerInvariant()
        duckdb_arrow_patch_sha256 = (Get-FileHash "$duck/share/duckdb-sdk/arrow-geometry.patch" -Algorithm SHA256).Hash.ToLowerInvariant()
        duckdb_copy_function_patch_sha256 = (Get-FileHash "$duck/share/duckdb-sdk/c-api-copy-functions.patch" -Algorithm SHA256).Hash.ToLowerInvariant()
        duckdb_dll_sha256 = (Get-FileHash "$duck/bin/duckdb.dll" -Algorithm SHA256).Hash.ToLowerInvariant()
    } | ConvertTo-Json | Set-Content "$package/build-info.json" -Encoding utf8
    $output = Join-Path $source 'dist'
    New-Item -ItemType Directory -Force $output | Out-Null
    $zip = Join-Path $output "php-duckdb-$version-php$PhpVersion-$ThreadSafety-$($vs.vs)-windows-x64.zip"
    Compress-Archive "$package/*" $zip

    # Smoke-test the actual ZIP contents in a fresh official PHP extraction.
    # Remove SDK/deps paths from PATH to prevent accidental dependency fallback.
    $clean = Join-Path $BuildRoot 'smoke'
    $tsPart = if ($ThreadSafety -eq 'nts') { 'nts-Win32' } else { 'Win32' }
    $phpArchive = Join-Path $build "php-$($details.phpSemver)-$tsPart-$($vs.vs)-x64.zip"
    Expand-Archive $phpArchive "$clean/php"
    Expand-Archive $zip "$clean/package"
    Copy-Item "$clean/package/php_duckdb.dll" "$clean/php/ext/php_duckdb.dll"
    Copy-Item "$clean/package/duckdb.dll" "$clean/php/duckdb.dll"
    $env:PATH = "$env:SystemRoot/System32;$env:SystemRoot"
    Push-Location "$clean/php"
    try {
        & './php.exe' -n -d extension_dir=ext -d extension=php_duckdb.dll "$source/packaging/windows/smoke.php" $PhpVersion $ThreadSafety $vs.vs $version $duckVersion
        if ($LASTEXITCODE -ne 0) {
            throw "Extracted package smoke failed: $LASTEXITCODE"
        }
    } finally {
        Pop-Location
    }
    Write-Host "Verified package: $zip"
} finally {
    Pop-Location
}
