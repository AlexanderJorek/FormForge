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
$exclude = @('.git', '.claude', '.vscode', '.gitignore', 'build', 'node_modules', 'vendor', 'tests', '.phpcs.xml', '.phpcs-security.xml', 'build.ps1', 'CLAUDE.md')
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
    # Composer writes its normal progress output to stderr. With
    # $ErrorActionPreference = 'Stop' (set at the top of this script), PowerShell
    # 5.1 wraps each such line in a NativeCommandError and aborts the script even
    # though composer itself exits 0 — a real failure is still caught below via
    # $LASTEXITCODE, which composer sets correctly regardless of this quirk.
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        # --ignore-platform-req=ext-gd: your local CLI's php.ini doesn't have the gd
        # extension enabled. A normal WordPress host does, so this only affects
        # building the zip here, not the plugin at runtime.
        composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=ext-gd
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
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

# mpdf/mpdf ships ~80 TTF/OTF font files (DejaVu, FreeFont, and many others for
# scripts like Devanagari/Khmer/Syriac/etc, ~88MB total) covering every font it
# ever might need. FormForge only ever selects one of 4 families — see the
# font_family match in includes/PDF/templates/layout.php ('dejavusans' [default],
# 'dejavuserif', 'dejavusansmono', 'freemono') — and mPDF's autoScriptToLang/
# autoLangToFont/useSubstitutions are all left at their default of false in
# Generator.php's $mpdf_config, so mPDF never auto-switches to a different font
# family for unsupported scripts; it never touches any font file outside these 4
# families, regardless of what text a form submission contains. Trimming this
# ONLY in the staged release build (never the working vendor/, which a plain
# `composer install` would just restore anyway, and which stays full for local
# testing) cuts ~85MB of dead weight from what actually ships. If you add a fifth
# selectable PDF font, add its files to $keepFonts below or this step will delete
# them and PDF generation will fatal on "font file not found."
Write-Host "Trimming unused mPDF font files..." -ForegroundColor Cyan
$fontsDir = Join-Path $stageDir 'vendor\mpdf\mpdf\ttfonts'
$keepFonts = @(
    'DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'DejaVuSans-Oblique.ttf', 'DejaVuSans-BoldOblique.ttf',
    'DejaVuSerif.ttf', 'DejaVuSerif-Bold.ttf', 'DejaVuSerif-Italic.ttf', 'DejaVuSerif-BoldItalic.ttf',
    'DejaVuSansMono.ttf', 'DejaVuSansMono-Bold.ttf', 'DejaVuSansMono-Oblique.ttf', 'DejaVuSansMono-BoldOblique.ttf',
    'FreeMono.ttf', 'FreeMonoBold.ttf', 'FreeMonoOblique.ttf', 'FreeMonoBoldOblique.ttf',
    'DejaVuinfo.txt', 'GNUFreeFontinfo.txt'
)
if (Test-Path $fontsDir) {
    $before = (Get-ChildItem -Path $fontsDir -File | Measure-Object -Property Length -Sum).Sum
    Get-ChildItem -Path $fontsDir -File | Where-Object { $keepFonts -notcontains $_.Name } | Remove-Item -Force
    $after = (Get-ChildItem -Path $fontsDir -File | Measure-Object -Property Length -Sum).Sum
    Write-Host ("  {0:N1} MB -> {1:N1} MB" -f ($before / 1MB), ($after / 1MB)) -ForegroundColor Cyan
}

# Dev-only tooling bundled inside Composer packages themselves (not something
# `composer install --no-dev` strips, since they ship as regular package files, and
# `--no-dev` only controls OUR OWN require-dev, not what each dependency chooses to
# include in its own distributed files): CI workflows/issue templates, static-analysis
# (PHPStan/Psalm) configs, PHPUnit configs, a phpcs ruleset, .gitignore files, an unused
# scratch tmp/ dir (mPDF only falls back to vendor/mpdf/mpdf/tmp when no 'tempDir' is
# passed in config — Generator.php always passes its own, so this is never touched), and
# a phar-building shell script (WordPress.org's Plugin Check disallows shipping shell
# scripts) plus the build artifacts/PHP script it invoked. None of this is needed at
# runtime; none of it is excluded by composer.json's own `require-dev` (that only
# affects OUR dependency tree, not what upstream packages bundle in their own dist).
Write-Host "Removing dev-only tooling bundled inside dependencies..." -ForegroundColor Cyan
$vendorExclude = @(
    'vendor/paragonie/random_compat/build-phar.sh',
    'vendor/paragonie/random_compat/dist',
    'vendor/paragonie/random_compat/other',
    'vendor/paragonie/random_compat/psalm-autoload.php',
    'vendor/paragonie/random_compat/psalm.xml',
    'vendor/mpdf/mpdf/.github',
    'vendor/mpdf/mpdf/.gitignore',
    'vendor/mpdf/mpdf/tmp',
    'vendor/mpdf/mpdf/phpstan-baseline.neon',
    'vendor/mpdf/mpdf/phpstan.neon',
    'vendor/mpdf/mpdf/phpunit.xml',
    'vendor/mpdf/mpdf/ruleset.xml',
    'vendor/mpdf/psr-log-aware-trait/.gitignore'
)
foreach ($rel in $vendorExclude) {
    $path = Join-Path $stageDir $rel
    if (Test-Path $path) { Remove-Item -Recurse -Force $path }
}

Write-Host "Creating zip..." -ForegroundColor Cyan
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }
Compress-Archive -Path $stageDir -DestinationPath $zipPath

Write-Host ""
Write-Host "Done. Release build: $zipPath" -ForegroundColor Green
Write-Host "Unpacked copy for inspection: $stageDir" -ForegroundColor Green
