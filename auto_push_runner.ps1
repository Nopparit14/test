<#
Interactive helper to push this repository to GitHub.
Usage: run from the repository root:
  .\auto_push_runner.ps1
The script will:
 - ensure `git` is available in this session
 - silence PSReadLine history errors for the session
 - ask for the remote URL (or reuse existing)
 - ask for auth method: PAT or SSH
 - perform the push and restore a safe remote URL

Security: do NOT share your PAT. The script only uses the PAT transiently.
#>

function FailExit($msg){ Write-Host $msg -ForegroundColor Red; exit 1 }

# ensure git available
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    $possible = @(
        'C:\Program Files\Git\cmd\git.exe',
        'C:\Program Files (x86)\Git\cmd\git.exe'
    )
    $found = $null
    foreach ($p in $possible) { if (Test-Path $p) { $found = Split-Path $p -Parent; break } }
    if ($found) { $env:PATH = "$found;$env:PATH" } else { FailExit "git not found in PATH. Install Git and re-run." }
}

Set-PSReadLineOption -HistorySaveStyle SaveNothing 2>$null

Write-Host "Repository root: $(Get-Location)"

# get current remote if any
$current = (& git remote get-url origin 2>$null)
if ($current) { Write-Host "Current origin: $current" }

$RemoteUrl = Read-Host "Enter remote URL (leave empty to use current)"
if (-not $RemoteUrl -and $current) { $RemoteUrl = $current }
if (-not $RemoteUrl) { FailExit "No remote URL provided." }

Write-Host "Choose auth method: 1) PAT (HTTPS)  2) SSH"
$choice = Read-Host "Enter 1 or 2"

if ($choice -eq '1') {
    $user = Read-Host 'GitHub username'
    $secure = Read-Host 'Personal Access Token (input hidden)' -AsSecureString
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)

    # build authenticated URL
    if ($RemoteUrl -match '^https://') {
        # strip https:// and any trailing creds
        $u = $RemoteUrl -replace '^https://(?:[^@]+@)?',''
        $authUrl = "https://{0}:{1}@{2}" -f $user, $token, $u
    } else {
        FailExit "For PAT auth provide an https:// URL."
    }

    Write-Host "Setting remote and pushing (using PAT)..."
    git remote remove origin 2>$null
    git remote add origin $authUrl
    git push -u origin main
    $code = $LASTEXITCODE

    # restore safe remote without token
    git remote set-url origin "https://$u"
    if ($code -ne 0) { FailExit "Push failed (exit $code)." }
    Write-Host "Push succeeded." -ForegroundColor Green

    # clear token variable
    Remove-Variable token -ErrorAction SilentlyContinue
} elseif ($choice -eq '2') {
    # ensure SSH remote format
    if ($RemoteUrl -match '^git@') {
        $sshUrl = $RemoteUrl
    } elseif ($RemoteUrl -match '^https://github.com/(.+)/(.+)(?:\.git)?$') {
        $owner = $Matches[1]; $repo = $Matches[2]
        if ($repo -notmatch '\.git$') { $repo = "$repo.git" }
        $sshUrl = "git@github.com:$owner/$repo"
    } else {
        FailExit "Could not convert provided URL to SSH form. Provide ssh URL or https github.com/OWNER/REPO.git"
    }

    # check for SSH key
    $hasKey = Test-Path (Join-Path $env:USERPROFILE '.ssh\id_ed25519.pub') -or Test-Path (Join-Path $env:USERPROFILE '.ssh\id_rsa.pub')
    if (-not $hasKey) {
        Write-Host "No SSH public key found. Generating one now (no passphrase)."
        ssh-keygen -t ed25519 -f (Join-Path $env:USERPROFILE '.ssh\id_ed25519') -N "" | Out-Null
        Write-Host "Public key created. Add the contents of:`n" + (Join-Path $env:USERPROFILE '.ssh\id_ed25519.pub') + "`n to GitHub → Settings → SSH and GPG keys, then re-run this script."
        exit 0
    }

    Write-Host "Using SSH remote: $sshUrl"
    git remote remove origin 2>$null
    git remote add origin $sshUrl
    git push -u origin main
    if ($LASTEXITCODE -ne 0) { FailExit "Push failed. Ensure your SSH key is added to GitHub." }
    Write-Host "Push succeeded via SSH." -ForegroundColor Green
} else {
    FailExit "Invalid choice." 
}
