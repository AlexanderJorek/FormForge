<#
.SYNOPSIS
    Builds a clean, WordPress.org-ready copy of FormForge into build/form-forge/
    and zips it to build/form-forge.zip.

.DESCRIPTION
    Copies the plugin into build/form-forge/, then runs `composer install
    --no-dev` INSIDE that copy only. Your working vendor/ folder (with the dev
    tools like phpcs/wpcs) is never touched. Run this before every release.

.EXAMPLE
    ./build.ps1
#>

$ErrorActionPreference = 'Stop'

$root      = $PSScriptRoot
$buildDir  = Join-Path $root 'build'
$stageDir  = Join-Path $buildDir 'form-forge'
$zipPath   = Join-Path $buildDir 'form-forge.zip'

Write-Host "Cleaning previous build..." -ForegroundColor Cyan
if (Test-Path $buildDir) { Remove-Item -Recurse -Force $buildDir }
New-Item -ItemType Directory -Path $stageDir | Out-Null

Write-Host "Copying plugin files..." -ForegroundColor Cyan
$exclude = @('.git', '.claude', '.vscode', '.gitignore', 'build', 'node_modules', 'vendor', 'tests', '.phpcs.xml', 'build.ps1', 'CLAUDE.md')
Get-ChildItem -Path $root -Force | Where-Object { $exclude -notcontains $_.Name } | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $stageDir -Recurse -Force
}

# Dev-notes files that live inside otherwise-shipped folders (not excludable by
# top-level name above) — remove them individually from the staged copy.
$nestedExclude = @('includes/PDF/templates/HEADER-RENDERING.md', 'languages/compile-mo.php')
foreach ($rel in $nestedExclude) {
    $path = Join-Path $stageDir $rel
    if (Test-Path $path) { Remove-Item -Force $path }
}

Write-Host "Installing production-only dependencies (composer install --no-dev)..." -ForegroundColor Cyan
Push-Location $stageDir
try {
    # --ignore-platform-req=ext-gd: your local CLI's php.ini doesn't have the gd
    # extension enabled. A normal WordPress host does, so this only affects
    # building the zip here, not the plugin at runtime.
    composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-gd
    if ($LASTEXITCODE -ne 0) { throw "composer install failed with exit code $LASTEXITCODE" }
} finally {
    Pop-Location
}

# vendor/ above was excluded from the copy and rebuilt by composer install --no-dev,
# which only manages Composer-declared packages. vendor/pdfjs/ is placed there
# manually (pdf.js is an npm package, not installable via Composer), so it must
# be copied in separately or every release build would silently ship without it.
Write-Host "Copying manually-vendored pdf.js..." -ForegroundColor Cyan
Copy-Item -Path (Join-Path $root 'vendor\pdfjs') -Destination (Join-Path $stageDir 'vendor\pdfjs') -Recurse -Force

Write-Host "Creating zip..." -ForegroundColor Cyan
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }
Compress-Archive -Path $stageDir -DestinationPath $zipPath

Write-Host ""
Write-Host "Done. Release build: $zipPath" -ForegroundColor Green
Write-Host "Unpacked copy for inspection: $stageDir" -ForegroundColor Green
