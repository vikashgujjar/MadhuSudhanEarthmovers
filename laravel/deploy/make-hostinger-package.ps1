# Builds the upload package for Hostinger (or any PHP + MySQL shared hosting).
#
#   First install:  powershell -ExecutionPolicy Bypass -File deploy\make-hostinger-package.ps1
#   Later updates:  powershell -ExecutionPolicy Bypass -File deploy\make-hostinger-package.ps1 -Update
#
# Output (in the laravel folder):
#   me-erp-hostinger.zip         first install: includes a new .env with fresh secret keys
#   me-erp-hostinger-update.zip  update: no .env and no storage/ folder, so settings,
#                                sessions and uploaded files on the server are kept
# Needs PHP and Composer on this computer (XAMPP's PHP is fine).
param([switch]$Update)
$ErrorActionPreference = "Stop"

$app = Split-Path -Parent $PSScriptRoot
$stage = Join-Path $env:TEMP ("me-erp-package-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
New-Item -ItemType Directory -Path $stage | Out-Null

Write-Host "Copying application files..."
# Full paths: only the top-level folders (public\vendor holds the icon and chart libraries and must be kept).
$excludeDirs = @("vendor", "tests", "deploy", "node_modules", ".git") | ForEach-Object { Join-Path $app $_ }
$excludeFiles = @(".env", ".env.testing", "phpunit.xml", "me-erp-hostinger*.zip", "*.log", ".phpunit.result.cache")
robocopy $app $stage /E /NFL /NDL /NJH /NJS /NP /XD @excludeDirs /XF @excludeFiles | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }

# Clean runtime folders (keep the empty structure Laravel needs).
foreach ($dir in @("storage\logs", "storage\framework\sessions", "storage\framework\views", "storage\framework\cache\data", "bootstrap\cache")) {
    $path = Join-Path $stage $dir
    New-Item -ItemType Directory -Force -Path $path | Out-Null
    Get-ChildItem $path -File -Force | Where-Object { $_.Name -ne ".gitignore" } | Remove-Item -Force
}
Get-ChildItem (Join-Path $stage "storage\app\private") -Recurse -File -Force -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne ".gitignore" } | Remove-Item -Force

Write-Host "Installing production PHP libraries (composer --no-dev)..."
Push-Location $stage
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
if ($LASTEXITCODE -ne 0) { Pop-Location; throw "composer install failed" }
Pop-Location

Copy-Item (Join-Path $PSScriptRoot "root.htaccess") (Join-Path $stage ".htaccess")

if ($Update) {
    Remove-Item -Recurse -Force (Join-Path $stage "storage")
    $out = Join-Path $app "me-erp-hostinger-update.zip"
} else {
    Write-Host "Writing a production .env with new secret keys..."
    $appKey = php -r "echo 'base64:'.base64_encode(random_bytes(32));"
    $installKey = php -r "echo bin2hex(random_bytes(12));"
    $envText = (Get-Content (Join-Path $app ".env.example") -Raw) -replace "(?m)^APP_KEY=.*$", "APP_KEY=$appKey"
    $envText += "`n# One-time installer key: type it on /install. Delete this line after installing.`nERP_INSTALL_KEY=$installKey`n"
    [IO.File]::WriteAllText((Join-Path $stage ".env"), $envText.Replace("`r`n", "`n"))
    $out = Join-Path $app "me-erp-hostinger.zip"
}

if (Test-Path $out) { Remove-Item $out }
Write-Host "Creating zip..."
$names = Get-ChildItem $stage -Force | ForEach-Object { $_.Name }
& "$env:SystemRoot\System32\tar.exe" -a -cf $out -C $stage @names
if ($LASTEXITCODE -ne 0) { throw "zip failed" }
Remove-Item -Recurse -Force $stage

$size = [math]::Round((Get-Item $out).Length / 1MB, 1)
Write-Host "Created $out ($size MB)"
if (-not $Update) { Write-Host "Installer key: $installKey  (also inside the .env in the zip)" }
