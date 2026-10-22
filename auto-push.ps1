
$ProjectPath = "C:\xampp\htdocs\WEBPAGE\eduMate"
$QuietSeconds = 60

Set-Location $ProjectPath

if (-not (Test-Path ".git")) {
    Write-Error "Git repository not found."
    exit 1
}

$branch = git branch --show-current
$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = $ProjectPath
$watcher.IncludeSubdirectories = $true
$watcher.NotifyFilter = [System.IO.NotifyFilters]'FileName, DirectoryName, LastWrite, Size'

$lastChange = Get-Date
$watcher.EnableRaisingEvents = $true

Write-Host "Watching $ProjectPath for changes..."
Write-Host "Automatic commit and push after $QuietSeconds seconds of inactivity."
Write-Host "Press Ctrl+C to stop."

try {
    while ($true) {
        Start-Sleep -Seconds 2

        # Detect changes in Git's working tree.
        $status = git status --porcelain --untracked-files=all
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "Could not check Git status."
            continue
        }

        if ($status) {
            $lastChange = Get-Date
            while (((Get-Date) - $lastChange).TotalSeconds -lt $QuietSeconds) {
                Start-Sleep -Seconds 2
                $newStatus = git status --porcelain --untracked-files=all
                if ($newStatus -ne $status) {
                    $status = $newStatus
                    $lastChange = Get-Date
                }
            }

            git add -A
            if ($LASTEXITCODE -ne 0) {
                Write-Warning "Staging failed."
                continue
            }

            git diff --cached --quiet
            if ($LASTEXITCODE -eq 0) {
                continue
            }

            git commit -m "Auto-sync: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
            if ($LASTEXITCODE -ne 0) {
                Write-Warning "Commit failed."
                continue
            }

            git push origin $branch
            if ($LASTEXITCODE -ne 0) {
                Write-Warning "Push failed. Check authentication or remote changes."
            } else {
                Write-Host "Successfully pushed to GitHub." -ForegroundColor Green
            }
        }
    }
}
finally {
    $watcher.Dispose()
}
