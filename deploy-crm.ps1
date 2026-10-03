Write-Host ""
Write-Host "=== CRM Frontend Deploy Script ===" -ForegroundColor Cyan
Write-Host ""

$projectDir = $PSScriptRoot
Set-Location $projectDir

# 1) Build
Write-Host "[1/3] Build aliniyor..." -ForegroundColor Yellow
npx nuxt generate 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Host "BUILD HATASI! Tekrar deneyin." -ForegroundColor Red
    exit 1
}
Write-Host "  Build tamamlandi." -ForegroundColor Green

# 2) dist kontrolu
$distPath = Join-Path $projectDir "dist"
if (-not (Test-Path $distPath)) {
    $distPath = Join-Path $projectDir ".output\public"
}
if (-not (Test-Path $distPath)) {
    Write-Host "dist klasoru bulunamadi!" -ForegroundColor Red
    exit 1
}

# 3) ZIP olustur
$zipName = "crm-deploy-$(Get-Date -Format 'yyyyMMdd-HHmmss').zip"
$zipPath = Join-Path $projectDir $zipName

# Onceki deploy ZIP'lerini temizle
Get-ChildItem -Path $projectDir -Filter "crm-deploy-*.zip" | Remove-Item -Force

Write-Host "[2/3] ZIP olusturuluyor..." -ForegroundColor Yellow
Compress-Archive -Path "$distPath\*" -DestinationPath $zipPath -Force
Write-Host "  ZIP: $zipName" -ForegroundColor Green

# 4) Bilgi
$sizeMB = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
Write-Host ""
Write-Host "[3/3] Hazir!" -ForegroundColor Green
Write-Host ""
Write-Host "  Dosya : $zipPath" -ForegroundColor White
Write-Host "  Boyut : $sizeMB MB" -ForegroundColor White
Write-Host ""
Write-Host "  cPanel'e yuklemek icin:" -ForegroundColor Cyan
Write-Host "    1. File Manager -> crm2.sigortax.net klasoru" -ForegroundColor Gray
Write-Host "    2. $zipName yukle" -ForegroundColor Gray
Write-Host "    3. Extract et" -ForegroundColor Gray
Write-Host ""
