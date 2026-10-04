param(
    [Parameter(Mandatory)][string]$Prefix,
    [Parameter(Mandatory)][string]$WorkDirectory,
    [ValidateRange(1, 2147483647)][int]$Jobs = 0,
    [string]$SourceArchive = $env:DUCKDB_SOURCE_ARCHIVE,
    [string]$CacheDirectory = $env:DUCKDB_SDK_CACHE_DIR,
    [switch]$Fingerprint,
    [ValidateSet('ON', 'OFF')][string]$DisableUnity = $(if ($env:DUCKDB_DISABLE_UNITY) { $env:DUCKDB_DISABLE_UNITY } else { 'OFF' })
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if ($env:OS -ne 'Windows_NT') {
    throw 'This builder requires Windows and Visual Studio 2022 x64'
}

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

$resolvedJobs = 0
if (!$PSBoundParameters.ContainsKey('Jobs') -and ![string]::IsNullOrEmpty($env:DUCKDB_BUILD_JOBS)) {
    if ($env:DUCKDB_BUILD_JOBS -notmatch '^[1-9][0-9]*$' -or
        ![int]::TryParse($env:DUCKDB_BUILD_JOBS, [ref]$resolvedJobs) -or $resolvedJobs -lt 1) {
        throw 'DUCKDB_BUILD_JOBS must be a positive integer'
    }
    $Jobs = $resolvedJobs
}

$python = Get-DuckDBPython3
if ($Jobs -eq 0) {
    $jobsHelper = Join-Path $PSScriptRoot '../resources/jobs.py'
    $selectedJobs = & $python $jobsHelper --profile sdk
    if ($LASTEXITCODE -ne 0 -or "$selectedJobs" -notmatch '^[1-9][0-9]*$' -or
        ![int]::TryParse("$selectedJobs", [ref]$resolvedJobs) -or $resolvedJobs -lt 1) {
        throw 'Cannot select DuckDB SDK workers from packaging/resources/jobs.py'
    }
    $Jobs = $resolvedJobs
}
Write-Host "DuckDB SDK build workers: $Jobs"

$manifestPath = Join-Path $PSScriptRoot 'source.json'
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ($manifest.sha256 -notmatch '^[0-9a-f]{64}$' -or $manifest.commit -notmatch '^[0-9a-f]{40}$') {
    throw 'Invalid DuckDB source pins'
}

$patchPath = Join-Path $PSScriptRoot $manifest.patch
$arrowPatchPath = Join-Path $PSScriptRoot 'patches/arrow-geometry.patch'
foreach ($requiredPatch in $patchPath, $arrowPatchPath) {
    if (!(Test-Path -LiteralPath $requiredPatch -PathType Leaf)) {
        throw "Missing DuckDB patch: $requiredPatch"
    }
}
foreach ($tool in 'cmake', 'git', 'tar.exe') {
    if (!(Get-Command $tool -ErrorAction SilentlyContinue)) {
        throw "Required tool missing: $tool"
    }
}

$Prefix = [IO.Path]::GetFullPath($Prefix)
$WorkDirectory = [IO.Path]::GetFullPath($WorkDirectory)
if ($WorkDirectory.TrimEnd('\', '/') -eq [IO.Path]::GetPathRoot($WorkDirectory).TrimEnd('\', '/')) {
    throw 'Work directory cannot be a drive root'
}
New-Item -ItemType Directory -Force $WorkDirectory | Out-Null

# Select the engine compiler independently of PHP's vs16/vs17 toolchain.
# Its DLL exposes the C ABI; allocations are freed through DuckDB APIs.
$vswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio/Installer/vswhere.exe'
if (!(Test-Path -LiteralPath $vswhere -PathType Leaf)) {
    throw 'vswhere.exe was not found'
}

$instances = @(& $vswhere -latest -version '[17.0,18.0)' -products '*' `
    -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -format json | ConvertFrom-Json)
if ($LASTEXITCODE -ne 0 -or $instances.Count -ne 1) {
    throw 'Visual Studio 2022 C++ x64 tools were not found'
}

$vs = $instances[0]
$toolsets = @(Get-ChildItem (Join-Path $vs.installationPath 'VC/Tools/MSVC') -Directory |
    Sort-Object { [version]$_.Name } -Descending)
if ($toolsets.Count -eq 0) {
    throw 'Visual Studio 2022 has no MSVC toolset'
}

$compiler = Join-Path $toolsets[0].FullName 'bin/Hostx64/x64/cl.exe'
if (!(Test-Path -LiteralPath $compiler -PathType Leaf)) {
    throw 'MSVC x64 compiler was not found'
}

$cmakeVersion = (& cmake --version | Select-Object -First 1)
if ($LASTEXITCODE -ne 0) {
    throw 'Cannot inspect CMake version'
}

$metadata = [ordered]@{
    version = $manifest.version
    source_sha256 = $manifest.sha256
    source_commit = $manifest.commit
    manifest_sha256 = (Get-FileHash $manifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    patch_sha256 = (Get-FileHash $patchPath -Algorithm SHA256).Hash.ToLowerInvariant()
    arrow_patch_sha256 = (Get-FileHash $arrowPatchPath -Algorithm SHA256).Hash.ToLowerInvariant()
    builder_sha256 = (Get-FileHash $PSCommandPath -Algorithm SHA256).Hash.ToLowerInvariant()
    platform = 'Windows/x64'
    visual_studio = $vs.installationVersion
    visual_studio_instance = $vs.installationPath
    compiler_sha256 = (Get-FileHash $compiler -Algorithm SHA256).Hash.ToLowerInvariant()
    toolset = $toolsets[0].Name
    compiler_flags = "CL=$env:CL; _CL_=${env:_CL_}; LINK=$env:LINK; _LINK_=${env:_LINK_}"
    cmake = $cmakeVersion
    build = 'Release; Visual Studio 17 2022; x64; host=x64; upstream static CRT'
    native_arch = 'OFF'
    disable_unity = $DisableUnity
    builtins = 'core_functions,parquet,json,icu,autocomplete'
    autoload = 'ON'
    autoinstall = 'ON'
} | ConvertTo-Json

$sdkMetadata = Join-Path $Prefix 'share/duckdb-sdk'
$buildMetadata = Join-Path $sdkMetadata 'build.txt'
$artifactNames = @('include/duckdb.h', 'lib/duckdb.lib', 'bin/duckdb.dll',
    'share/duckdb-sdk/LICENSE.duckdb', 'share/duckdb-sdk/source.json',
    'share/duckdb-sdk/nullable-bitpacking.patch', 'share/duckdb-sdk/arrow-geometry.patch')

function Test-DuckDBSdk {
    param([string]$Directory)

    $metadataPath = Join-Path $Directory 'share/duckdb-sdk/build.txt'
    try {
        if (!(Test-Path -LiteralPath $metadataPath -PathType Leaf) `
            -or (Get-Content -LiteralPath $metadataPath -Raw).TrimEnd() -ne $metadata.TrimEnd()) {
            return $false
        }
        $pins = Get-Content (Join-Path $Directory 'share/duckdb-sdk/artifacts.json') -Raw | ConvertFrom-Json
        $names = @($pins.PSObject.Properties.Name)
        if ($names.Count -ne $artifactNames.Count) {
            return $false
        }
        foreach ($name in $artifactNames) {
            $path = Join-Path $Directory $name
            if ($name -notin $names -or !(Test-Path -LiteralPath $path -PathType Leaf) `
                -or (Get-FileHash $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $pins.$name) {
                return $false
            }
        }
        return $true
    } catch {
        return $false
    }
}

function Copy-DuckDBSdk {
    param([string]$Source, [string]$Destination)

    foreach ($name in $artifactNames + @('share/duckdb-sdk/build.txt', 'share/duckdb-sdk/artifacts.json')) {
        $target = Join-Path $Destination $name
        New-Item -ItemType Directory -Force (Split-Path -Parent $target) | Out-Null
        Copy-Item -LiteralPath (Join-Path $Source $name) -Destination $target -Force
    }
}

$sha256 = [Security.Cryptography.SHA256]::Create()
try {
    $buildFingerprint = [BitConverter]::ToString($sha256.ComputeHash(
        [Text.Encoding]::UTF8.GetBytes($metadata.TrimEnd()))).Replace('-', '').ToLowerInvariant()
} finally {
    $sha256.Dispose()
}
if ($Fingerprint) {
    Write-Output $buildFingerprint
    return
}

$cacheEntry = $null
if (![string]::IsNullOrEmpty($CacheDirectory)) {
    $CacheDirectory = [IO.Path]::GetFullPath($CacheDirectory)
    $cacheEntry = Join-Path $CacheDirectory $buildFingerprint
}

function Save-DuckDBSdk {
    if (!$cacheEntry -or (Test-DuckDBSdk $cacheEntry)) {
        return
    }
    New-Item -ItemType Directory -Force $CacheDirectory | Out-Null
    $temporary = Join-Path $CacheDirectory ('.' + [guid]::NewGuid().ToString() + '.tmp')
    try {
        Copy-DuckDBSdk $Prefix $temporary
        if (!(Test-DuckDBSdk $temporary)) {
            throw 'Completed DuckDB SDK failed cache verification'
        }
        if (Test-Path -LiteralPath $cacheEntry) {
            Remove-Item -LiteralPath $cacheEntry -Recurse -Force
        }
        Move-Item -LiteralPath $temporary -Destination $cacheEntry
    } finally {
        if (Test-Path -LiteralPath $temporary) {
            Remove-Item -LiteralPath $temporary -Recurse -Force
        }
    }
    Write-Host "Cached verified DuckDB SDK at $cacheEntry"
}

if (Test-DuckDBSdk $Prefix) {
    Save-DuckDBSdk
    Write-Host "Reusing verified patched DuckDB SDK at $Prefix"
    return
}
if ($cacheEntry -and (Test-DuckDBSdk $cacheEntry)) {
    Copy-DuckDBSdk $cacheEntry $Prefix
    if (!(Test-DuckDBSdk $Prefix)) {
        throw 'Restored DuckDB SDK failed verification'
    }
    Write-Host "Reusing verified cached DuckDB SDK at $Prefix"
    return
}

if ([string]::IsNullOrEmpty($SourceArchive)) {
    $SourceArchive = Join-Path $WorkDirectory "duckdb-$($manifest.version).tar.gz"
    if (!(Test-Path -LiteralPath $SourceArchive -PathType Leaf)) {
        $partial = "$SourceArchive.part"
        Invoke-WebRequest $manifest.url -OutFile $partial
        Move-Item -LiteralPath $partial -Destination $SourceArchive -Force
    }
}
if (!(Test-Path -LiteralPath $SourceArchive -PathType Leaf)) {
    throw "Source archive missing: $SourceArchive"
}

$SourceArchive = (Resolve-Path -LiteralPath $SourceArchive).Path
if ((Get-FileHash $SourceArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $manifest.sha256) {
    throw 'DuckDB source SHA-256 mismatch'
}

$sourceDirectory = Join-Path $WorkDirectory 'source'
$buildDirectory = Join-Path $WorkDirectory 'build'
foreach ($directory in $sourceDirectory, $buildDirectory) {
    if (Test-Path -LiteralPath $directory) {
        Remove-Item -LiteralPath $directory -Recurse -Force
    }
}
New-Item -ItemType Directory $sourceDirectory | Out-Null
& tar.exe -xzf $SourceArchive -C $sourceDirectory --strip-components=1
if ($LASTEXITCODE -ne 0) {
    throw "DuckDB source extraction failed: $LASTEXITCODE"
}
# git apply works on an extracted source tree and checks exact context.
foreach ($requiredPatch in $patchPath, $arrowPatchPath) {
    & git -C $sourceDirectory apply --check --whitespace=error $requiredPatch
    if ($LASTEXITCODE -ne 0) {
        throw "Pinned DuckDB patch does not apply cleanly: $requiredPatch"
    }
    & git -C $sourceDirectory apply --whitespace=error $requiredPatch
    if ($LASTEXITCODE -ne 0) {
        throw "DuckDB patch application failed: $requiredPatch"
    }
}

$arguments = @('-S', $sourceDirectory, '-B', $buildDirectory,
    '-G', 'Visual Studio 17 2022', '-A', 'x64', '-T', "host=x64,version=$($toolsets[0].Name)",
    "-DCMAKE_GENERATOR_INSTANCE=$($vs.installationPath)",
    "-DOVERRIDE_GIT_DESCRIBE=v$($manifest.version)",
    "-DGIT_COMMIT_HASH=$($manifest.commit.Substring(0, 10))",
    '-DBUILD_EXTENSIONS=json;icu;autocomplete', '-DENABLE_EXTENSION_AUTOLOADING=ON',
    '-DENABLE_EXTENSION_AUTOINSTALL=ON', '-DNATIVE_ARCH=OFF', "-DDISABLE_UNITY=$DisableUnity",
    '-DBUILD_SHELL=OFF', '-DBUILD_UNITTESTS=OFF', '-DBUILD_BENCHMARKS=OFF')
& cmake @arguments
if ($LASTEXITCODE -ne 0) {
    throw "DuckDB CMake configuration failed: $LASTEXITCODE"
}
& cmake --build $buildDirectory --config Release --target duckdb --parallel $Jobs
if ($LASTEXITCODE -ne 0) {
    throw "DuckDB SDK build failed: $LASTEXITCODE"
}

$dll = Join-Path $buildDirectory 'src/Release/duckdb.dll'
$importLibrary = Join-Path $buildDirectory 'src/Release/duckdb.lib'
foreach ($file in $dll, $importLibrary) {
    if (!(Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing built DuckDB artifact: $file"
    }
}
New-Item -ItemType Directory -Force "$Prefix/include", "$Prefix/lib", "$Prefix/bin", $sdkMetadata | Out-Null
# Install only the C header, shared DLL and C ABI import library.
Copy-Item "$sourceDirectory/src/include/duckdb.h" "$Prefix/include/duckdb.h" -Force
Copy-Item $importLibrary "$Prefix/lib/duckdb.lib" -Force
Copy-Item $dll "$Prefix/bin/duckdb.dll" -Force
Copy-Item "$sourceDirectory/LICENSE" "$sdkMetadata/LICENSE.duckdb" -Force
Copy-Item $manifestPath "$sdkMetadata/source.json" -Force
Copy-Item $patchPath "$sdkMetadata/nullable-bitpacking.patch" -Force
Copy-Item $arrowPatchPath "$sdkMetadata/arrow-geometry.patch" -Force
$metadata | Set-Content -LiteralPath $buildMetadata -Encoding utf8
$hashes = [ordered]@{}
foreach ($name in $artifactNames) {
    $hashes[$name] = (Get-FileHash (Join-Path $Prefix $name) -Algorithm SHA256).Hash.ToLowerInvariant()
}

$hashes | ConvertTo-Json | Set-Content (Join-Path $sdkMetadata 'artifacts.json') -Encoding utf8
Save-DuckDBSdk
Write-Host "Installed patched DuckDB $($manifest.version) C API SDK at $Prefix"
