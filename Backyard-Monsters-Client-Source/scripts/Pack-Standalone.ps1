[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Executable,
    [Parameter(Mandatory = $true)][string]$PackRoot,
    [Parameter(Mandatory = $true)][string]$OutputFile,
    [string]$NoticesDirectory
)

# Produces one executable: original .NET single-file application + ZIP + footer.
# Footer: UInt64 little-endian ZIP length, 32 raw SHA-256 bytes, ASCII BYMR_ASSETPACKV1.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$executablePath = [IO.Path]::GetFullPath($Executable)
$packPath = [IO.Path]::GetFullPath($PackRoot).TrimEnd('\', '/')
$outputPath = [IO.Path]::GetFullPath($OutputFile)
$separator = [IO.Path]::DirectorySeparatorChar
if (-not (Test-Path -LiteralPath $executablePath -PathType Leaf)) { throw 'The compiled executable was not found.' }
if ([IO.Path]::GetExtension($executablePath) -ne '.exe' -or [IO.Path]::GetExtension($outputPath) -ne '.exe') { throw 'The input and output must use .exe filenames.' }
if ($executablePath.Equals($outputPath, [StringComparison]::OrdinalIgnoreCase)) { throw 'Use a different output filename; the original compiled application must remain intact.' }
$assetsPath = Join-Path $packPath 'Assets'
$manifestPath = Join-Path $packPath 'assets-manifest.json'
if (-not (Test-Path -LiteralPath $assetsPath -PathType Container) -or -not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw 'PackRoot must contain Assets and assets-manifest.json.'
}
if ($outputPath.StartsWith($assetsPath.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'The executable output cannot be inside the source Assets folder.'
}
if ([string]::IsNullOrWhiteSpace($NoticesDirectory)) {
    $NoticesDirectory = Join-Path $packPath 'THIRD-PARTY'
}
$noticesPath = [IO.Path]::GetFullPath($NoticesDirectory)
if (Test-Path -LiteralPath $noticesPath -PathType Container) {
    if ($outputPath.StartsWith($noticesPath.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The executable output cannot be inside the source notices folder.'
    }
}
$magic = [Text.Encoding]::ASCII.GetBytes('BYMR_ASSETPACKV1')
$inputProbe = [IO.File]::OpenRead($executablePath)
try {
    if ($inputProbe.Length -lt 2 -or $inputProbe.ReadByte() -ne 77 -or $inputProbe.ReadByte() -ne 90) { throw 'The input is not a Windows executable (MZ header missing).' }
    if ($inputProbe.Length -ge $magic.Length) {
        $null = $inputProbe.Seek(-$magic.Length, [IO.SeekOrigin]::End)
        $tail = New-Object byte[] $magic.Length
        $null = $inputProbe.Read($tail, 0, $tail.Length)
        if ([Convert]::ToBase64String($tail) -eq [Convert]::ToBase64String($magic)) { throw 'The input already contains an asset pack. Use the clean published executable.' }
    }
} finally { $inputProbe.Dispose() }

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ([string]::IsNullOrWhiteSpace([string]$manifest.gameVersion) -or @($manifest.files).Count -eq 0) { throw 'The asset manifest is empty or invalid.' }
$parent = Split-Path -Parent $outputPath
New-Item -ItemType Directory -Path $parent -Force | Out-Null
$token = [guid]::NewGuid().ToString('N')
$zipPath = Join-Path $parent ('assetpack-' + $token + '.zip')
$stagedOutput = $outputPath + '.building-' + $token
$zipStream = $null
$archive = $null
$outputStream = $null
$sourceStream = $null
$payloadStream = $null
$payloadHash = $null
$hashAlgorithm = $null
$recordCount = 0
$seen = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)

function Add-ZipFile([IO.Compression.ZipArchive]$Zip, [string]$Source, [string]$EntryName) {
    $entry = $Zip.CreateEntry($EntryName, [IO.Compression.CompressionLevel]::Optimal)
    # Stable timestamps give identical asset packs a stable cache identity across rebuilds.
    $entry.LastWriteTime = [DateTimeOffset]'2000-01-01T00:00:00+00:00'
    $inputStream = [IO.File]::OpenRead($Source)
    $target = $null
    try {
        $target = $entry.Open()
        $inputStream.CopyTo($target)
    } finally {
        if ($target) { $target.Dispose() }
        $inputStream.Dispose()
    }
}

try {
    Write-Host 'Packing game assets into the standalone executable...'
    $zipStream = [IO.File]::Open($zipPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    $archive = New-Object IO.Compression.ZipArchive($zipStream, [IO.Compression.ZipArchiveMode]::Create, $true)
    Add-ZipFile $archive $manifestPath 'assets-manifest.json'
    foreach ($record in ($manifest.files | Sort-Object path)) {
        $relative = [string]$record.path
        if ([string]::IsNullOrWhiteSpace($relative) -or $relative.StartsWith('/') -or $relative.Contains('\') -or
            $relative.Contains(':') -or $relative.Contains('%') -or ($relative.Split('/') | Where-Object { $_ -eq '' -or $_ -eq '.' -or $_ -eq '..' }) -or
            $relative -match '[\x00-\x1F\x7F]') { throw "Unsafe manifest path: $relative" }
        if (-not $seen.Add($relative)) { throw "Duplicate manifest path: $relative" }
        $asset = Join-Path $assetsPath $relative
        $file = Get-Item -LiteralPath $asset
        if ($file.PSIsContainer -or ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Invalid asset file: $relative" }
        if ($file.Length -ne [long]$record.size) { throw "Asset size does not match the manifest: $relative" }
        if ((Get-FileHash -LiteralPath $asset -Algorithm SHA256).Hash -ine [string]$record.sha256) { throw "Asset checksum does not match the manifest: $relative" }
        Add-ZipFile $archive $asset ('Assets/' + $relative)
        $recordCount++
    }
    if (Test-Path -LiteralPath $noticesPath -PathType Container) {
        $noticePrefix = $noticesPath.TrimEnd('\', '/') + $separator
        foreach ($notice in (Get-ChildItem -LiteralPath $noticesPath -File -Recurse | Sort-Object FullName)) {
            if (($notice.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Linked notice files are not supported.' }
            $noticeRelative = $notice.FullName.Substring($noticePrefix.Length).Replace('\', '/')
            Add-ZipFile $archive $notice.FullName ('THIRD-PARTY/' + $noticeRelative)
        }
    }
    $archive.Dispose()
    $archive = $null
    $zipStream.Dispose()
    $zipStream = $null

    $payloadStream = [IO.File]::OpenRead($zipPath)
    $hashAlgorithm = [Security.Cryptography.SHA256]::Create()
    $payloadHash = $hashAlgorithm.ComputeHash($payloadStream)
    $payloadLength = [long]$payloadStream.Length
    $payloadStream.Position = 0
    $lengthBytes = [BitConverter]::GetBytes($payloadLength)
    if (-not [BitConverter]::IsLittleEndian) { [Array]::Reverse($lengthBytes) }
    $outputStream = [IO.File]::Open($stagedOutput, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $sourceStream = [IO.File]::OpenRead($executablePath)
    $sourceStream.CopyTo($outputStream)
    $sourceStream.Dispose()
    $sourceStream = $null
    $payloadStream.CopyTo($outputStream)
    $outputStream.Write($lengthBytes, 0, $lengthBytes.Length)
    $outputStream.Write($payloadHash, 0, $payloadHash.Length)
    $outputStream.Write($magic, 0, $magic.Length)
    $outputStream.Flush($true)
    $outputStream.Dispose()
    $outputStream = $null

    if (Test-Path -LiteralPath $outputPath) {
        $replacementBackup = $outputPath + '.previous-' + $token
        [IO.File]::Replace($stagedOutput, $outputPath, $replacementBackup)
        Remove-Item -LiteralPath $replacementBackup -Force
    } else {
        [IO.File]::Move($stagedOutput, $outputPath)
    }
    Write-Host "Standalone client ready: $outputPath"
    Write-Host "$recordCount game assets embedded; players can double-click this EXE directly."
} finally {
    if ($archive) { $archive.Dispose() }
    if ($zipStream) { $zipStream.Dispose() }
    if ($sourceStream) { $sourceStream.Dispose() }
    if ($payloadStream) { $payloadStream.Dispose() }
    if ($outputStream) { $outputStream.Dispose() }
    if ($hashAlgorithm) { $hashAlgorithm.Dispose() }
    if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $stagedOutput) { Remove-Item -LiteralPath $stagedOutput -Force -ErrorAction SilentlyContinue }
}
