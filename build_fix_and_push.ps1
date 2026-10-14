<#
Automates EOL normalization and pushes to origin.
Run from repository root in PowerShell:
  .\build_fix_and_push.ps1 -RemoteUrl 'https://github.com/OWNER/REPO.git'
It will:
 - set core.autocrlf false
 - create .gitattributes and .gitignore
 - remove cached local caches from index
 - renormalize and commit
 - add remote if missing and push to origin main
#>
param(
    [string] $RemoteUrl,
    [switch] $CreateRepo,
    [switch] $ForceLocal
)

function FailExit($msg){ Write-Host $msg -ForegroundColor Red; exit 1 }

# ensure git available
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    # Try common Git install locations and add to PATH for this session if found
    $possible = @(
        'C:\Program Files\Git\cmd\git.exe',
        'C:\Program Files (x86)\Git\cmd\git.exe'
    )
    $found = $null
    foreach ($p in $possible) {
        if (Test-Path $p) { $found = Split-Path $p -Parent; break }
    }
    if ($found) {
        Write-Host "Found git at $found; adding to PATH for this session..."
        $env:PATH = "$found;$env:PATH"
    } else {
        FailExit "git not found in PATH. Install Git and re-run this script."
    }
}

# Determine repository top-level and decide working directory
Write-Host "Detecting git repository top-level..."
$gitTop = (& git rev-parse --show-toplevel 2>$null).Trim()
$curr = (Get-Location).Path
if ($LASTEXITCODE -ne 0 -or -not $gitTop) {
    if ($CreateRepo) {
        Write-Host "No git repo found; initializing a new repository here..."
        & git init $curr
        if ($LASTEXITCODE -ne 0) { FailExit "Failed to init repository" }
        $workdir = $curr
    } else {
        FailExit "No git repository found in this folder. To initialize a repo here re-run with -CreateRepo, or run this script from the repository root."
    }
} else {
    # git repo exists at $gitTop
    if ($gitTop -ne $curr) {
        if ($ForceLocal -and $CreateRepo) {
            Write-Host "Parent git repo detected at '$gitTop', but -ForceLocal and -CreateRepo specified: initializing a local repo in $curr..."
            & git init $curr
            if ($LASTEXITCODE -ne 0) { FailExit "Failed to init local repository" }
            $workdir = $curr
        } elseif ($ForceLocal -and -not $CreateRepo) {
            # operating in current folder only allowed if .git exists locally
            if (Test-Path (Join-Path $curr '.git')) {
                $workdir = $curr
            } else {
                FailExit "Parent repo exists. To create a local repo here use -CreateRepo -ForceLocal."
            }
        } else {
            FailExit "A git repository exists at '$gitTop' which is a parent of this folder. This script will not operate on parent repositories. Run this script from the repository root, or re-run with -CreateRepo -ForceLocal to create a nested repo here."
        }
    } else {
        $workdir = $curr
    }
}

Write-Host "Working directory for git operations: $workdir"

Write-Host "Setting git core.autocrlf to false..."
git config core.autocrlf false

Write-Host "Creating .gitattributes in $workdir..."
$gitattributes = '* text=auto'
Set-Content -Encoding UTF8 -NoNewline -Path (Join-Path $workdir '.gitattributes') -Value $gitattributes

Write-Host "Creating .gitignore in $workdir..."
$gitignore = @'
.antigravity/
.cache/
.gemini/
.claude/
.cursor/
.copilot/
.vscode/
node_modules/
*.xcarchive
*.ipa
'@
Set-Content -Encoding UTF8 -NoNewline -Path (Join-Path $workdir '.gitignore') -Value $gitignore

Write-Host "Removing common local caches from index (safe, won't delete local files)..."
$paths = @('.antigravity','.cache','.gemini','.claude','.cursor','.copilot')
foreach ($p in $paths) {
    & git -C $workdir rm -r --cached --ignore-unmatch $p 2>$null | Out-Null
}

Write-Host "Re-normalizing and staging files..."
& git -C $workdir add --renormalize .
& git -C $workdir add -A
& git -C $workdir add .gitattributes .gitignore

$staged = (& git -C $workdir diff --cached --name-only)
if (-not $staged) {
    Write-Host "No changes staged. Adding placeholder .gitkeep to allow initial commit..."
    $keep = Join-Path $workdir '.gitkeep'
    if (-not (Test-Path $keep)) { New-Item -Path $keep -ItemType File -Force | Out-Null }
    & git -C $workdir add $keep
    if ($LASTEXITCODE -ne 0) { FailExit "Failed to stage placeholder" }
    & git -C $workdir commit -m "Initial commit (placeholder)"
    if ($LASTEXITCODE -ne 0) { FailExit "Commit failed" }
} else {
    Write-Host "Committing changes..."
    & git -C $workdir commit -m "Normalize EOL, add .gitattributes and ignore local caches"
    if ($LASTEXITCODE -ne 0) { FailExit "Commit failed" }
}

Write-Host "Ensuring branch 'main'..."
& git -C $workdir branch -M main
if ($LASTEXITCODE -ne 0) { FailExit "Failed to set branch to main" }

# Ensure remote origin exists
$remote = git remote get-url origin 2>$null
if (-not $remote) {
    if (-not $RemoteUrl) {
        $RemoteUrl = Read-Host 'Remote "origin" not found. Enter remote URL (https://github.com/OWNER/REPO.git) or press Enter to skip'
    }
    if ($RemoteUrl) {
        Write-Host "Adding remote origin: $RemoteUrl"
        & git -C $workdir remote add origin $RemoteUrl
        if ($LASTEXITCODE -ne 0) { FailExit "Failed to add remote" }
    } else {
        Write-Host "No remote configured. You will need to add a remote and push manually." -ForegroundColor Yellow
        exit 0
    }
}

Write-Host "Pushing to origin main..."
& git -C $workdir push -u origin main
if ($LASTEXITCODE -ne 0) { FailExit "Push failed. Check authentication (personal access token or SSH key)." }

Write-Host "Done." -ForegroundColor Green
