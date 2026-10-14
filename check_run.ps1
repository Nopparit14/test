$headers = @{ 'User-Agent' = 'Mozilla/5.0' }
$runs = (Invoke-RestMethod -Uri 'https://api.github.com/repos/Nopparit14/test/actions/runs' -Headers $headers).workflow_runs
$run4 = $runs | Where-Object { $_.run_number -eq 4 }
$artifacts = (Invoke-RestMethod -Uri "https://api.github.com/repos/Nopparit14/test/actions/runs/$($run4.id)/artifacts" -Headers $headers).artifacts
$artifacts | Select-Object name, size_in_bytes, archive_download_url
