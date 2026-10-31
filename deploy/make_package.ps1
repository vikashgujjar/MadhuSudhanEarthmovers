# Builds me-erp.tar.gz in the project folder: the application without local-only
# files (virtualenv, .env, local uploads, build tools). Upload it to the server.
#
#   powershell -ExecutionPolicy Bypass -File deploy\make_package.ps1
$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$out = Join-Path $root "me-erp.tar.gz"
if (Test-Path $out) { Remove-Item $out }

$exclude = @(
    "./venv", "./.venv", "./.env", "./tools", "./staticfiles", "./media", "./me-erp.tar.gz",
    "__pycache__", "*.pyc", "*.log", "db.sqlite3"
)
$tarArgs = @("-czf", $out) + ($exclude | ForEach-Object { "--exclude=$_" }) + @("-C", $root, ".")
& "$env:SystemRoot\System32\tar.exe" @tarArgs
if ($LASTEXITCODE -ne 0) { throw "tar failed" }

$size = [math]::Round((Get-Item $out).Length / 1MB, 1)
Write-Host "Created $out ($size MB)"
