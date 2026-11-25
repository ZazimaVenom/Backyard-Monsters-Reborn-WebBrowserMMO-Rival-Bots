[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$GameSource,
    [string]$GameVersion
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$catalogPath = Join-Path ([IO.Path]::GetFullPath($GameSource)) 'app\Catalog.php'
$detected = $null
if (Test-Path -LiteralPath $catalogPath -PathType Leaf) {
    # Read the release value without requiring PHP on a client build machine.
    $pattern = '[''"]version[''"]\s*=>\s*[''"](?<version>[^''"]+)[''"]'
    $versions = @([regex]::Matches([IO.File]::ReadAllText($catalogPath), $pattern) |
        ForEach-Object { $_.Groups['version'].Value } | Select-Object -Unique)
    if ($versions.Count -gt 1) { throw 'The game catalog contains conflicting release versions.' }
    if ($versions.Count -eq 1) { $detected = $versions[0] }
}
if (-not [string]::IsNullOrWhiteSpace($GameVersion)) {
    if ($detected -and $GameVersion -cne $detected) {
        throw "The requested asset version '$GameVersion' differs from the game release '$detected'. Use the game release version."
    }
    return $GameVersion
}
if ($detected) { return $detected }
throw 'Could not read the game release from app\Catalog.php. Supply -GameVersion with the actual server asset version.'
