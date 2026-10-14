<#
.SYNOPSIS
    Automated Build & Push tool for OGIOS / iOS Project
.DESCRIPTION
    Stages changes, pushes to GitHub, triggers/monitors GitHub Actions macOS runner,
    and optionally downloads the resulting unsigned .ipa artifact directly to your PC.
#>

[CmdletBinding()]
param (
    [string]$CommitMessage = "",
    [switch]$DownloadArtifact,
    [string]$Token = $env:GITHUB_TOKEN
)

function Write-Step([string]$msg) {
    Write-Host "`n>>> $msg" -ForegroundColor Cyan
}

function Write-Success([string]$msg) {
    Write-Host "[OK] $msg" -ForegroundColor Green
}

function Write-Warn([string]$msg) {
    Write-Host "[WARN] $msg" -ForegroundColor Yellow
}

# 1. Ensure git is available
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    $possible = @(
        'C:\Program Files\Git\cmd\git.exe',
        'C:\Program Files (x86)\Git\cmd\git.exe'
    )
    $found = $null
    foreach ($p in $possible) { if (Test-Path $p) { $found = Split-Path $p -Parent; break } }
    if ($found) { $env:PATH = "$found;$env:PATH" } else { 
        Write-Host "Git not found in PATH. Please install Git." -ForegroundColor Red
        return 
    }
}

# 2. Get repository info
$repoRoot = (& git rev-parse --show-toplevel 2>$null)
if (-not $repoRoot) { 
    Write-Host "Not inside a Git repository." -ForegroundColor Red
    return 
}

$remoteUrl = (& git config --get remote.origin.url 2>$null)
if (-not $remoteUrl) { 
    Write-Host "No git remote 'origin' configured." -ForegroundColor Red
    return 
}

# Parse Owner / Repo from remote URL
$owner = ""
$repo = ""
if ($remoteUrl -match 'github\.com[:/]([^/]+)/([^/\.]+)(?:\.git)?') {
    $owner = $Matches[1]
    $repo = $Matches[2]
}

Write-Host "========================================================" -ForegroundColor Magenta
Write-Host "         OGIOS AUTO BUILD AND SYNC TOOL" -ForegroundColor Magenta
Write-Host "========================================================" -ForegroundColor Magenta
Write-Host "Repository: $owner/$repo" -ForegroundColor Gray
Write-Host "Local Path: $repoRoot" -ForegroundColor Gray

# 3. Add and commit changes
Write-Step "Checking local changes..."
& git add -A

$staged = (& git diff --cached --name-only)
if ($staged) {
    if (-not $CommitMessage) {
        $dateStr = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        $CommitMessage = "Auto build update: $dateStr"
    }
    Write-Step "Committing changes with message: '$CommitMessage'"
    & git commit -m "$CommitMessage"
} else {
    Write-Host "No new file changes to commit." -ForegroundColor Gray
}

# 4. Push to origin main
Write-Step "Pushing to GitHub (main)..."
& git push -u origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAIL] Failed to push to GitHub. If authentication fails, run .\auto_push_runner.ps1" -ForegroundColor Red
    return
}
Write-Success "Push completed successfully!"

$actionsUrl = "https://github.com/$owner/$repo/actions"
$releasesUrl = "https://github.com/$owner/$repo/releases"

Write-Host "`n--------------------------------------------------------" -ForegroundColor DarkGray
Write-Host "GitHub Actions is building your unsigned IPA on macOS!" -ForegroundColor Yellow
Write-Host "Build Status:  $actionsUrl" -ForegroundColor Cyan
Write-Host "Download IPA:  $releasesUrl" -ForegroundColor Cyan
Write-Host "--------------------------------------------------------`n" -ForegroundColor DarkGray

# 5. Optional artifact download via GitHub API
if ($DownloadArtifact -and $Token -and $owner -and $repo) {
    Write-Step "Monitoring GitHub Actions run and downloading IPA..."
    & "$PSScriptRoot\build_windows_dispatch.ps1" -Owner $owner -Repo $repo -Ref "main"
} else {
    $open = Read-Host "Open GitHub Actions in your browser? (Y/N) [Default: Y]"
    if ($open -ne "N" -and $open -ne "n") {
        Start-Process $actionsUrl
    }
}
