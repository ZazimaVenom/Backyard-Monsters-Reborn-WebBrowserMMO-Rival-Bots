[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$GameSource,
    [Parameter(Mandatory = $true)][string]$OutputDirectory,
    [string]$GameVersion = '0.13.0'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$gameRoot = [IO.Path]::GetFullPath($GameSource)
$assetSource = Join-Path $gameRoot 'public\assets'
if (-not (Test-Path -LiteralPath $assetSource -PathType Container)) {
    throw "No public\assets folder found in '$gameRoot'. Pass the full game source folder."
}
$output = [IO.Path]::GetFullPath($OutputDirectory)
if ($output.Equals([IO.Path]::GetPathRoot($output), [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Choose a folder for the asset pack, not a drive root.'
}
$output = $output.TrimEnd('\', '/')
$parent = Split-Path -Parent $output
if ([string]::IsNullOrWhiteSpace($parent) -or $output -eq $parent) { throw 'Choose a folder for the asset pack, not a drive root.' }
$separator = [IO.Path]::DirectorySeparatorChar
if ($output.StartsWith($assetSource.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase) -or
    $assetSource.StartsWith($output.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase) -or
    $output.Equals($assetSource, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Choose a separate output folder: it cannot contain, overwrite, or be inside the source assets.'
}
New-Item -ItemType Directory -Path $parent -Force | Out-Null

$mime = @{
    '.png' = 'image/png'; '.webp' = 'image/webp'; '.svg' = 'image/svg+xml'; '.jpg' = 'image/jpeg'; '.jpeg' = 'image/jpeg'
    '.gif' = 'image/gif'; '.ico' = 'image/x-icon'; '.avif' = 'image/avif'
    '.ogg' = 'audio/ogg'; '.wav' = 'audio/wav'; '.mp3' = 'audio/mpeg'; '.m4a' = 'audio/mp4'; '.aac' = 'audio/aac'; '.flac' = 'audio/flac'
    '.css' = 'text/css; charset=utf-8'; '.js' = 'text/javascript; charset=utf-8'; '.mjs' = 'text/javascript; charset=utf-8'
    '.json' = 'application/json; charset=utf-8'; '.woff' = 'font/woff'; '.woff2' = 'font/woff2'; '.ttf' = 'font/ttf'; '.otf' = 'font/otf'
    '.wasm' = 'application/wasm'; '.mp4' = 'video/mp4'; '.webm' = 'video/webm'
}
$staging = $output + '.building-' + [guid]::NewGuid().ToString('N')
$backup = $output + '.previous-' + [guid]::NewGuid().ToString('N')
$manifestPath = Join-Path $parent 'assets-manifest.json'
$manifestTemp = $manifestPath + '.new-' + [guid]::NewGuid().ToString('N')
try {
    New-Item -ItemType Directory -Path $staging | Out-Null
    $records = New-Object 'System.Collections.Generic.List[object]'
    $sourcePrefix = $assetSource.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    foreach ($file in (Get-ChildItem -LiteralPath $assetSource -Recurse -File | Sort-Object FullName)) {
        if (($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Linked asset is not allowed: $($file.FullName)" }
        $extension = $file.Extension.ToLowerInvariant()
        if (-not $mime.ContainsKey($extension)) { throw "Unsupported asset type '$extension' in $($file.Name). Add it explicitly to the asset pack allowlist before packaging." }
        $relative = $file.FullName.Substring($sourcePrefix.Length).Replace('\', '/')
        $destination = Join-Path $staging $relative
        New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
        Copy-Item -LiteralPath $file.FullName -Destination $destination
        $records.Add([ordered]@{
            path = $relative
            size = $file.Length
            sha256 = (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLowerInvariant()
            mimeType = $mime[$extension]
        })
    }
    if ($records.Count -eq 0) { throw 'The source contains no eligible assets.' }
    $manifest = [ordered]@{ gameVersion = $GameVersion; files = @($records.ToArray()) } | ConvertTo-Json -Depth 6
    [IO.File]::WriteAllText($manifestTemp, $manifest, (New-Object Text.UTF8Encoding($false)))
    if (Test-Path -LiteralPath $output) { Move-Item -LiteralPath $output -Destination $backup }
    try {
        Move-Item -LiteralPath $staging -Destination $output
        Move-Item -LiteralPath $manifestTemp -Destination $manifestPath -Force
    } catch {
        if (Test-Path -LiteralPath $output) { Remove-Item -LiteralPath $output -Recurse -Force }
        if (Test-Path -LiteralPath $backup) { Move-Item -LiteralPath $backup -Destination $output }
        throw
    }
    if (Test-Path -LiteralPath $backup) { Remove-Item -LiteralPath $backup -Recurse -Force }
    Write-Host "Prepared $($records.Count) local assets for game $GameVersion in $output"
} finally {
    if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $manifestTemp) { Remove-Item -LiteralPath $manifestTemp -Force -ErrorAction SilentlyContinue }
}
