# Build release package for MaryLink MCP plugin
param(
    [string]$OutputDir = "C:\Users\herve\Downloads"
)

$sourceDir = $PSScriptRoot
$versionJson = Get-Content "$sourceDir\version.json" | ConvertFrom-Json
$version = $versionJson.version
$zipName = "marylink-mcp-v$version.zip"
$tempDir = Join-Path $env:TEMP "marylink-mcp-build"

Write-Host "Building MaryLink MCP v$version..."

# Clean and create temp dir
if (Test-Path $tempDir) { Remove-Item -Recurse -Force $tempDir }
New-Item -ItemType Directory -Path "$tempDir\marylink-mcp" -Force | Out-Null

# Copy files
Copy-Item -Recurse "$sourceDir\src" "$tempDir\marylink-mcp\"
if (Test-Path "$sourceDir\assets") { Copy-Item -Recurse "$sourceDir\assets" "$tempDir\marylink-mcp\" }
if (Test-Path "$sourceDir\templates") { Copy-Item -Recurse "$sourceDir\templates" "$tempDir\marylink-mcp\" }
Copy-Item "$sourceDir\marylink-mcp.php" "$tempDir\marylink-mcp\"
if (Test-Path "$sourceDir\mcp-no-headless.php") { Copy-Item "$sourceDir\mcp-no-headless.php" "$tempDir\marylink-mcp\" }
Copy-Item "$sourceDir\version.json" "$tempDir\marylink-mcp\"
if (Test-Path "$sourceDir\index.php") { Copy-Item "$sourceDir\index.php" "$tempDir\marylink-mcp\" }
if (Test-Path "$sourceDir\README.md") { Copy-Item "$sourceDir\README.md" "$tempDir\marylink-mcp\" }
if (Test-Path "$sourceDir\CHANGELOG.md") { Copy-Item "$sourceDir\CHANGELOG.md" "$tempDir\marylink-mcp\" }

# Create zip
$zipPath = "$OutputDir\$zipName"
if (Test-Path $zipPath) { Remove-Item $zipPath }
Compress-Archive -Path "$tempDir\marylink-mcp" -DestinationPath $zipPath

# Cleanup
Remove-Item -Recurse -Force $tempDir

Write-Host ""
Write-Host "========================================="
Write-Host "Release built successfully!"
Write-Host "========================================="
Write-Host "Version: $version"
Write-Host "Output: $zipPath"
Write-Host ""
Get-Item $zipPath | Format-Table Name, @{N='Size';E={"{0:N0} KB" -f ($_.Length/1KB)}}
