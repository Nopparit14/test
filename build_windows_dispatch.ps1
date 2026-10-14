<#
Usage example (run in PowerShell):

$env:GITHUB_TOKEN = '<your_personal_access_token_with_workflows>'
.\build_windows_dispatch.ps1 -Owner youruser -Repo yourrepo -Ref main -BundleId com.example.app -ProjectName OGIOS -Scheme OGIOS

Requirements:
- A GitHub personal access token in env var GITHUB_TOKEN with "repo" and "workflow" scopes.
- The repository already contains the workflow file .github/workflows/objective-c-xcode.yml (this repo does).
#>

param(
    [Parameter(Mandatory=$true)][string]$Owner,
    [Parameter(Mandatory=$true)][string]$Repo,
    [string]$Ref = 'main',
    [string]$BundleId = 'com.apple.mobile.MobileHouseArrest',
    [string]$ProjectName = 'OGIOS',
    [string]$Scheme = 'OGIOS',
    [int]$PollIntervalSeconds = 10,
    [int]$TimeoutMinutes = 30,
    [string]$WorkflowFile = 'objective-c-xcode.yml'
)

if (-not $env:GITHUB_TOKEN) {
    Write-Error "Set environment variable GITHUB_TOKEN with a PAT that has workflow permissions."
    exit 1
}

$base = "https://api.github.com/repos/$Owner/$Repo"
$headers = @{ Authorization = "Bearer $env:GITHUB_TOKEN"; Accept = 'application/vnd.github.v3+json' }

# Trigger workflow_dispatch
$body = @{ ref = $Ref; inputs = @{ bundle_id = $BundleId; project_name = $ProjectName; scheme = $Scheme } } | ConvertTo-Json -Depth 5
Write-Host "Dispatching workflow $WorkflowFile on ref $Ref..."
$dispatchUrl = "$base/actions/workflows/$WorkflowFile/dispatches"
[void](Invoke-RestMethod -Method Post -Uri $dispatchUrl -Headers $headers -Body $body -ContentType 'application/json')

# Poll for the newest run of that workflow
$start = Get-Date
$timeout = [TimeSpan]::FromMinutes($TimeoutMinutes)
while ($true) {
    Start-Sleep -Seconds $PollIntervalSeconds
    $runsUrl = "$base/actions/workflows/$WorkflowFile/runs?per_page=5"
    $runs = Invoke-RestMethod -Method Get -Uri $runsUrl -Headers $headers
    if ($runs.total_count -gt 0) {
        $run = $runs.workflow_runs | Where-Object { $_.event -eq 'workflow_dispatch' } | Select-Object -First 1
        if ($null -ne $run) {
            $runId = $run.id
            $status = $run.status
            $conclusion = $run.conclusion
            Write-Host "Run ID: $runId  Status: $status  Conclusion: $conclusion"
            if ($status -eq 'completed') { break }
        } else {
            Write-Host "No recent workflow_dispatch run found yet..."
        }
    }
    if ((Get-Date) - $start -gt $timeout) {
        Write-Error "Timed out waiting for workflow run to start/complete."
        exit 1
    }
}

# Check final conclusion
if ($conclusion -ne 'success') {
    Write-Error "Workflow completed with conclusion: $conclusion. Check Actions for logs."
    exit 1
}

# Download artifacts
$artifactsUrl = "$base/actions/runs/$runId/artifacts"
$artifacts = Invoke-RestMethod -Method Get -Uri $artifactsUrl -Headers $headers
if ($artifacts.total_count -eq 0) {
    Write-Error "No artifacts found in the run."
    exit 1
}

foreach ($a in $artifacts.artifacts) {
    $name = $a.name
    $downloadUrl = $a.archive_download_url
    $out = Join-Path -Path (Get-Location) -ChildPath ("$name.zip")
    Write-Host "Downloading artifact $name -> $out"
    Invoke-WebRequest -Uri $downloadUrl -Headers $headers -OutFile $out -UseBasicParsing
}

Write-Host "Artifacts downloaded. Look for files like '$ProjectName-unsigned-ipa.zip' and build log zip."
