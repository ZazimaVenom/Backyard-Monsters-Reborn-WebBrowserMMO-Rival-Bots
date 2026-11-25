[CmdletBinding()]
param(
    [string]$GameSource,
    [string]$OutputDirectory,
    [string]$GameVersion,
    [switch]$Standalone
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$sourceRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
# This combined source release ships the web game beside the client. Always use
# that current web asset tree when present, even if an older Assets pack exists.
if ([string]::IsNullOrWhiteSpace($GameSource)) {
    $bundledGame = Split-Path -Parent $sourceRoot
    if (Test-Path -LiteralPath (Join-Path $bundledGame 'public\assets') -PathType Container) {
        $GameSource = $bundledGame
    }
}
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
    $OutputDirectory = Join-Path $sourceRoot 'out\Backyard-Monsters-Client'
}
$output = [IO.Path]::GetFullPath($OutputDirectory)
if ($output.Equals([IO.Path]::GetPathRoot($output), [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Choose a dedicated output folder, not a drive root.'
}
$output = $output.TrimEnd('\', '/')
$outputParent = Split-Path -Parent $output
$separator = [IO.Path]::DirectorySeparatorChar
if ([string]::IsNullOrWhiteSpace($outputParent) -or $output -eq $outputParent -or $output -eq $sourceRoot -or
    $sourceRoot.StartsWith($output.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Choose a dedicated output folder that does not contain the source project.'
}
if (-not [string]::IsNullOrWhiteSpace($GameSource)) {
    $GameSource = [IO.Path]::GetFullPath($GameSource)
    $GameVersion = & (Join-Path $PSScriptRoot 'Resolve-GameVersion.ps1') -GameSource $GameSource -GameVersion $GameVersion
    if ($output.Equals($GameSource, [StringComparison]::OrdinalIgnoreCase) -or
        $GameSource.StartsWith($output.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Choose an output folder that does not contain the game source.'
    }
    $gameAssetRoot = [IO.Path]::GetFullPath((Join-Path $GameSource 'public\assets'))
    if ($output.Equals($gameAssetRoot, [StringComparison]::OrdinalIgnoreCase) -or
        $output.StartsWith($gameAssetRoot.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase) -or
        $gameAssetRoot.StartsWith($output.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'The client output cannot overlap the game source assets.'
    }
}
$logDirectory = Join-Path $sourceRoot 'logs'
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
$logPath = Join-Path $logDirectory ('build-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
$transcriptStarted = $false
$staging = $null
$backup = $null
try {
    Start-Transcript -Path $logPath | Out-Null
    $transcriptStarted = $true
    if (-not [Environment]::Is64BitOperatingSystem) { throw 'The client build requires 64-bit Windows.' }
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

    # Prefer a .NET 10 SDK already installed; otherwise use a private SDK with no administrator requirement.
    $sdkDirectory = Join-Path $sourceRoot '.tools\dotnet'
    $dotnet = $null
    $installedCommand = Get-Command dotnet.exe -ErrorAction SilentlyContinue
    $candidates = @()
    if ($installedCommand) { $candidates += $installedCommand.Source }
    $candidates += (Join-Path $sdkDirectory 'dotnet.exe')
    foreach ($candidate in $candidates) {
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { continue }
        $sdkList = @(& $candidate --list-sdks 2>$null)
        if ($LASTEXITCODE -eq 0 -and ($sdkList | Where-Object { $_ -match '^10\.' })) {
            $dotnet = $candidate
            break
        }
    }
    if (-not $dotnet) {
        Write-Host 'Installing the Microsoft .NET 10 SDK for this build. Internet access is required.'
        New-Item -ItemType Directory -Path $sdkDirectory -Force | Out-Null
        $installer = Join-Path $sourceRoot '.tools\dotnet-install.ps1'
        Invoke-WebRequest -UseBasicParsing -Uri 'https://dot.net/v1/dotnet-install.ps1' -OutFile $installer
        & powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File $installer -Channel 10.0 -Architecture x64 -InstallDir $sdkDirectory -NoPath
        if ($LASTEXITCODE -ne 0) { throw "The .NET SDK installer failed with exit code $LASTEXITCODE." }
        $dotnet = Join-Path $sdkDirectory 'dotnet.exe'
        if (-not (Test-Path -LiteralPath $dotnet)) { throw 'The SDK installer completed without producing dotnet.exe.' }
    }

    $packSource = $null
    if ([string]::IsNullOrWhiteSpace($GameSource)) {
        foreach ($candidate in @($sourceRoot, (Split-Path -Parent $sourceRoot), $output)) {
            if ((Test-Path -LiteralPath (Join-Path $candidate 'Assets') -PathType Container) -and
                (Test-Path -LiteralPath (Join-Path $candidate 'assets-manifest.json') -PathType Leaf)) {
                $packSource = $candidate
                break
            }
        }
        if (-not $packSource) {
            throw 'No packaged Assets folder was found. Run Build-Client.cmd -GameSource "C:\path\to\Backyard-Monsters-Rehatched" or put this source folder beside the existing client Assets folder.'
        }
        $packAssetRoot = [IO.Path]::GetFullPath((Join-Path $packSource 'Assets'))
        # Replacing a previous complete output is supported; replacing its source Assets folder is not.
        if (-not $output.Equals($packSource, [StringComparison]::OrdinalIgnoreCase) -and
            ($output.Equals($packAssetRoot, [StringComparison]::OrdinalIgnoreCase) -or
             $packAssetRoot.StartsWith($output.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase) -or
             $output.StartsWith($packAssetRoot.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase))) {
            throw 'The output folder cannot overlap the existing source asset pack.'
        }
    } elseif (-not (Test-Path -LiteralPath (Join-Path $GameSource 'public\assets') -PathType Container)) {
        throw "The selected game source does not contain public\assets: $GameSource"
    }

    Write-Host 'Running the client core checks...'
    & $dotnet run --project (Join-Path $sourceRoot 'Client.Core.Tests\Client.Core.Tests.csproj') --configuration Release
    if ($LASTEXITCODE -ne 0) { throw "Client core checks failed with exit code $LASTEXITCODE." }

    New-Item -ItemType Directory -Path $outputParent -Force | Out-Null
    $staging = $output + '.building-' + [guid]::NewGuid().ToString('N')
    $backup = $output + '.previous-' + [guid]::NewGuid().ToString('N')
    $nativeOutput = $staging
    if ($Standalone) { $nativeOutput = Join-Path $staging 'native' }
    $embeddedPackTests = Join-Path $sourceRoot 'EmbeddedPack.Tests\EmbeddedPack.Tests.csproj'
    if (Test-Path -LiteralPath $embeddedPackTests) {
        & $dotnet run --project $embeddedPackTests --configuration Release
        if ($LASTEXITCODE -ne 0) { throw "Embedded asset checks failed with exit code $LASTEXITCODE." }
    }
    $bundle = 'false'
    if ($Standalone) { $bundle = 'true' }
    Write-Host 'Building the Windows x64 client...'
    & $dotnet publish (Join-Path $sourceRoot 'Client.Windows\Client.Windows.csproj') --configuration Release --runtime win-x64 --self-contained true -p:EnableWindowsTargeting=true "-p:PublishSingleFile=$bundle" "-p:IncludeNativeLibrariesForSelfExtract=$bundle" "-p:EnableCompressionInSingleFile=$bundle" -p:DebugType=None -p:DebugSymbols=false --output $nativeOutput
    if ($LASTEXITCODE -ne 0) { throw "Client publish failed with exit code $LASTEXITCODE." }

    if ($GameSource) {
        $assetPackRoot = $staging
        if ($Standalone) { $assetPackRoot = Join-Path $staging 'pack' }
        & (Join-Path $PSScriptRoot 'Prepare-Assets.ps1') -GameSource $GameSource -OutputDirectory (Join-Path $assetPackRoot 'Assets') -GameVersion $GameVersion
    } else {
        $assetPackRoot = $packSource
    }
    if ($Standalone) {
        & (Join-Path $PSScriptRoot 'Pack-Standalone.ps1') -Executable (Join-Path $nativeOutput 'BackyardMonstersClient.exe') -PackRoot $assetPackRoot -OutputFile (Join-Path $staging 'BackyardMonstersClient.exe') -NoticesDirectory (Join-Path $sourceRoot 'THIRD-PARTY')
        Remove-Item -LiteralPath $nativeOutput -Recurse -Force
        if ($GameSource) { Remove-Item -LiteralPath $assetPackRoot -Recurse -Force }
    } else {
        if (-not $GameSource) {
            Copy-Item -LiteralPath (Join-Path $assetPackRoot 'Assets') -Destination (Join-Path $staging 'Assets') -Recurse
            Copy-Item -LiteralPath (Join-Path $assetPackRoot 'assets-manifest.json') -Destination $staging
        }
        Copy-Item -LiteralPath (Join-Path $sourceRoot 'THIRD-PARTY') -Destination (Join-Path $staging 'THIRD-PARTY') -Recurse
        Copy-Item -LiteralPath (Join-Path $sourceRoot 'Client.Windows\Art\app.ico') -Destination (Join-Path $staging 'Client.ico')
    }
    if (-not (Test-Path -LiteralPath (Join-Path $staging 'BackyardMonstersClient.exe'))) { throw 'The build is missing BackyardMonstersClient.exe.' }
    Copy-Item -LiteralPath (Join-Path $sourceRoot 'CLIENT-README.txt') -Destination (Join-Path $staging 'READ-ME.txt')

    # Preserve the previous successful build until the complete replacement is ready.
    if (Test-Path -LiteralPath $output) { Move-Item -LiteralPath $output -Destination $backup }
    try { Move-Item -LiteralPath $staging -Destination $output }
    catch {
        if (Test-Path -LiteralPath $backup) { Move-Item -LiteralPath $backup -Destination $output }
        throw
    }
    if (Test-Path -LiteralPath $backup) { Remove-Item -LiteralPath $backup -Recurse -Force }
    Write-Host "Client ready: $output" -ForegroundColor Green
    if ($Standalone) { Write-Host 'Distribute BackyardMonstersClient.exe. The assets and .NET runtime are built in.' }
    else { Write-Host 'Distribute the complete output folder: the EXE, Assets folder and supporting files.' }
    Write-Host 'Players launch BackyardMonstersClient.exe directly.'
    exit 0
} catch {
    Write-Host "Build failed: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "Build log: $logPath"
    exit 1
} finally {
    if ($staging -and (Test-Path -LiteralPath $staging)) { Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue }
    if ($transcriptStarted) { Stop-Transcript | Out-Null }
}
