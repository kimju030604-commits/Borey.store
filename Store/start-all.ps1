# Borey Store — Start All Services
# Run this script from: C:\xampp\htdocs\Store\
# Usage: .\start-all.ps1

$ROOT = $PSScriptRoot

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Borey Store — Starting All Services   " -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan

# ── 1. MySQL ─────────────────────────────────────────────────────────────────
$mysqlRunning = netstat -ano | Select-String ":3306"
if (-not $mysqlRunning) {
    Write-Host "`n[1/4] Starting MySQL..." -ForegroundColor Yellow
    Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" `
        -ArgumentList "--defaults-file=C:\xampp\mysql\bin\my.ini" `
        -WindowStyle Hidden
    Start-Sleep -Seconds 4
    Write-Host "      MySQL started" -ForegroundColor Green
} else {
    Write-Host "`n[1/4] MySQL already running" -ForegroundColor Green
}

# ── 2. Laravel API (port 8001) ───────────────────────────────────────────────
$laravelRunning = netstat -ano | Select-String ":8001"
if (-not $laravelRunning) {
    Write-Host "`n[2/4] Starting Laravel API on port 8001..." -ForegroundColor Yellow
    Start-Process -FilePath "C:\xampp\php\php.exe" `
        -ArgumentList "artisan", "serve", "--port=8001" `
        -WorkingDirectory "$ROOT\laravel-backend" `
        -WindowStyle Normal
    Start-Sleep -Seconds 2
    Write-Host "      Laravel API started → http://127.0.0.1:8001" -ForegroundColor Green
} else {
    Write-Host "`n[2/4] Laravel already on port 8001" -ForegroundColor Green
}

# ── 3. FastAPI Bakong Gateway (port 8000) ────────────────────────────────────
$fastapiRunning = netstat -ano | Select-String ":8000"
if (-not $fastapiRunning) {
    Write-Host "`n[3/4] Starting FastAPI Bakong Gateway on port 8000..." -ForegroundColor Yellow
    Start-Process -FilePath "python" `
        -ArgumentList "main.py" `
        -WorkingDirectory "$ROOT\python_services\bakong_fastapi" `
        -WindowStyle Normal
    Start-Sleep -Seconds 2
    Write-Host "      FastAPI started → http://127.0.0.1:8000" -ForegroundColor Green
} else {
    Write-Host "`n[3/4] FastAPI already on port 8000" -ForegroundColor Green
}

# ── 4. React Dev Server (port 5173) ─────────────────────────────────────────
Write-Host "`n[4/4] Starting React Dev Server on port 5173..." -ForegroundColor Yellow
Start-Process -FilePath "cmd" `
    -ArgumentList "/k", "cd /d `"$ROOT\react-frontend`" && npm run dev" `
    -WindowStyle Normal
Start-Sleep -Seconds 2
Write-Host "      React dev server starting → http://localhost:5173" -ForegroundColor Green

# ── Summary ──────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Services started!                     " -ForegroundColor Cyan
Write-Host "  React Store  → http://localhost:5173  " -ForegroundColor White
Write-Host "  Laravel API  → http://127.0.0.1:8001  " -ForegroundColor White
Write-Host "  Bakong API   → http://127.0.0.1:8000  " -ForegroundColor White
Write-Host "                                        " -ForegroundColor Cyan
Write-Host "  Telegram bot → run separately:        " -ForegroundColor White
Write-Host "    cd python_services\telegram_bot     " -ForegroundColor White
Write-Host "    python bot.py                       " -ForegroundColor White
Write-Host "========================================" -ForegroundColor Cyan
