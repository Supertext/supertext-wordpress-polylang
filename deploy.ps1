#Requires -Version 5.1
<#
.SYNOPSIS
    Commits to GitHub, then deploys the Supertext plugin to the demo server via SFTP.
.DESCRIPTION
    1. Stages all changes, commits with an auto-generated message, and pushes to origin/main.
    2. Syncs the plugin directory to the remote WordPress plugins folder via WinSCP SFTP,
       excluding .git, deploy.ps1, and README.md.
    Requires WinSCP to be installed (https://winscp.net).
.PARAMETER CommitMessage
    Optional commit message. Auto-generated from changed files if omitted.
#>
param(
    [string]$CommitMessage = ''
)

function Get-AutoCommitMessage {
    $lines = git status --short 2>$null | Where-Object { $_ -match '\S' }
    if (-not $lines) { return $null }

    # Map file path fragments to readable labels (first match wins, order matters)
    $labelMap = [ordered]@{
        'admin/class-supertext-bulk-actions' = 'bulk actions'
        'admin/class-supertext-admin'        = 'admin settings'
        'admin/partials'                     = 'settings page'
        'admin/js'                           = 'admin JS'
        'admin/css'                          = 'admin CSS'
        'includes/class-supertext-deepl'     = 'DeepL proxy'
        'includes/class-supertext-api'       = 'API client'
        'includes/class-supertext-activator' = 'activator'
        'includes/class-supertext'           = 'plugin core'
        'supertext.php'                      = 'main plugin file'
        'uninstall.php'                      = 'uninstall handler'
        'languages/'                         = 'translations'
        'deploy'                             = 'deploy config'
    }

    $labels   = [System.Collections.Generic.List[string]]::new()
    $hasAdd   = $false
    $hasMod   = $false
    $hasDel   = $false

    foreach ($line in $lines) {
        $code = $line.Substring(0, 2).Trim()
        $file = $line.Substring(3).Trim()

        if ($code -match '[A?]') { $hasAdd = $true }
        if ($code -match 'M')    { $hasMod = $true }
        if ($code -match 'D')    { $hasDel = $true }

        foreach ($entry in $labelMap.GetEnumerator()) {
            if ($file -like "*$($entry.Key)*" -and $labels -notcontains $entry.Value) {
                $labels.Add($entry.Value)
                break
            }
        }
    }

    # Fallback: use raw filenames if nothing matched
    if ($labels.Count -eq 0) {
        $lines | ForEach-Object { $_.Substring(3).Trim() } |
            Select-Object -First 3 |
            ForEach-Object { $labels.Add($_) }
    }

    $verb = if     ($hasDel -and -not $hasAdd -and -not $hasMod) { 'Remove' }
            elseif ($hasAdd -and -not $hasMod -and -not $hasDel) { 'Add' }
            else                                                  { 'Update' }

    return "$verb $($labels -join ', ')"
}

$ErrorActionPreference = 'Stop'

# --- Config -------------------------------------------------------------------
$LocalDir = $PSScriptRoot
# ------------------------------------------------------------------------------

# Load server credentials from local file (gitignored)
$CredFile = Join-Path $LocalDir 'deploy.local.ps1'
if (-not (Test-Path $CredFile)) {
    Write-Error "deploy.local.ps1 not found. Copy deploy.local.ps1.example, fill in your credentials, and re-run."
    exit 1
}
. $CredFile

foreach ($var in @('Server', 'Username', 'Password', 'RemotePluginDir')) {
    if (-not (Get-Variable -Name $var -ValueOnly -ErrorAction SilentlyContinue)) {
        Write-Error "deploy.local.ps1 is missing `$$var. See deploy.local.ps1.example."
        exit 1
    }
}

Set-Location $LocalDir

# ── Step 1: Git commit & push ─────────────────────────────────────────────────
Write-Host "Checking git status..." -ForegroundColor Cyan

$gitStatus = git status --porcelain 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "git status failed. Is this a git repository?"
    exit 1
}

if ($gitStatus) {
    if (-not $CommitMessage) {
        $CommitMessage = Get-AutoCommitMessage
        if (-not $CommitMessage) {
            $CommitMessage = "Update plugin ($(Get-Date -Format 'yyyy-MM-dd HH:mm'))"
        }
    }

    Write-Host "Staging all changes..." -ForegroundColor Cyan
    git add --all
    if ($LASTEXITCODE -ne 0) { Write-Error "git add failed."; exit 1 }

    Write-Host "Committing: $CommitMessage" -ForegroundColor Cyan
    git commit -m $CommitMessage
    if ($LASTEXITCODE -ne 0) { Write-Error "git commit failed."; exit 1 }
} else {
    Write-Host "Nothing to commit — working tree clean." -ForegroundColor DarkGray
}

Write-Host "Pushing to origin/main..." -ForegroundColor Cyan
git push origin main
if ($LASTEXITCODE -ne 0) { Write-Error "git push failed."; exit 1 }
Write-Host "GitHub up to date." -ForegroundColor Green

# ── Step 2: Locate WinSCP ─────────────────────────────────────────────────────
$WinScpCandidates = @(
    'winscp.com',
    'C:\Program Files (x86)\WinSCP\WinSCP.com',
    'C:\Program Files\WinSCP\WinSCP.com'
)
$WinScp = $null
foreach ($candidate in $WinScpCandidates) {
    if (Get-Command $candidate -ErrorAction SilentlyContinue) {
        $WinScp = $candidate; break
    }
    if (Test-Path $candidate) {
        $WinScp = $candidate; break
    }
}

if (-not $WinScp) {
    Write-Error "WinSCP not found. Install it from https://winscp.net/eng/download.php then re-run."
    exit 1
}

Write-Host "Using WinSCP: $WinScp" -ForegroundColor Cyan

# ── Step 3: SFTP sync ─────────────────────────────────────────────────────────
$LocalDirUnix = $LocalDir.Replace('\', '/')
$ScriptContent = @"
option confirm off
open sftp://${Username}:${Password}@${Server}/ -hostkey=*
synchronize remote -delete -criteria=either -filemask="|.git\;.gitignore;deploy.ps1;deploy.local.ps1;deploy.local.ps1.example;deploy.log;supertext-debug.log;README.md" "$LocalDirUnix" "$RemotePluginDir"
exit
"@

$TempScript = [System.IO.Path]::GetTempFileName() + '.winscp'
Set-Content -Path $TempScript -Value $ScriptContent -Encoding UTF8

try {
    Write-Host "Deploying to $Server$RemotePluginDir ..." -ForegroundColor Cyan
    & $WinScp /script="$TempScript" /log="$LocalDir\deploy.log"

    if ($LASTEXITCODE -ne 0) {
        Write-Error "WinSCP exited with code $LASTEXITCODE — check deploy.log for details."
        exit $LASTEXITCODE
    }

    Write-Host "Deploy complete." -ForegroundColor Green
}
finally {
    Remove-Item -Path $TempScript -Force -ErrorAction SilentlyContinue
}
