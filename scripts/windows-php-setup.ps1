<#
.SYNOPSIS
    Makes a Windows PHP install able to reach the providers this app calls.

.DESCRIPTION
    Two things stop a fresh Windows setup from working, and both report
    themselves as something they are not:

    1. XAMPP 8.2 answers `php` on the PATH, and Laravel 13 requires 8.3+. The
       failure happens in vendor/composer/platform_check.php before the
       framework boots, so it looks like a dependency problem.

    2. PHP for Windows does not read the Windows certificate store, and the
       standalone builds ship no root certificates. Every HTTPS call then dies
       with "cURL error 60: unable to get local issuer certificate", which
       reads like a provider outage or a firewall.

    This finds a PHP 8.3+ interpreter, points it at a CA bundle, and proves the
    result by opening a real TLS connection to fal. It spends nothing: the
    check is an unauthenticated HEAD request, and this script never calls a
    generation endpoint.

    Safe to re-run. It will not duplicate ini settings it has already written.

.PARAMETER DryRun
    Report what would change and write nothing.

.EXAMPLE
    .\scripts\windows-php-setup.ps1

.EXAMPLE
    .\scripts\windows-php-setup.ps1 -DryRun
#>

[CmdletBinding()]
param(
    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'

function Write-Step { param([string] $Text) Write-Host "`n==> $Text" -ForegroundColor Cyan }
function Write-Ok   { param([string] $Text) Write-Host "    OK    $Text" -ForegroundColor Green }
function Write-Warn { param([string] $Text) Write-Host "    WARN  $Text" -ForegroundColor Yellow }
function Write-Bad  { param([string] $Text) Write-Host "    FAIL  $Text" -ForegroundColor Red }

# ---------------------------------------------------------------------------
# 1. Find an interpreter this app can actually run on.
# ---------------------------------------------------------------------------
# Deliberately does not trust `php` on the PATH. On the machine this was
# written for, PATH resolves to XAMPP's 8.2 and the override had been lost
# four times across separate terminals. An absolute path cannot be lost.

Write-Step 'Locating a PHP 8.3+ interpreter'

$candidates = @()

$candidates += Get-ChildItem -Path "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8*\php.exe" -ErrorAction SilentlyContinue
$candidates += Get-ChildItem -Path "$env:ProgramFiles\PHP\*\php.exe" -ErrorAction SilentlyContinue

$onPath = Get-Command php -ErrorAction SilentlyContinue
if ($onPath) { $candidates += Get-Item $onPath.Source }

$php = $null

foreach ($candidate in $candidates) {
    # Ask the binary rather than parsing the folder name: a WinGet package
    # directory is not a version number, and php.net zips are unpacked anywhere.
    $version = & $candidate.FullName -r 'echo PHP_VERSION;' 2>$null

    if ($LASTEXITCODE -ne 0 -or -not $version) { continue }

    if ([version]($version -replace '-.*$', '') -ge [version]'8.3.0') {
        $php = $candidate.FullName
        Write-Ok "$php is PHP $version"
        break
    }

    Write-Warn "$($candidate.FullName) is PHP $version - too old, skipping"
}

if (-not $php) {
    Write-Bad 'No PHP 8.3 or newer found.'
    Write-Host '      Install one with:  winget install --id PHP.PHP.8.4 -e'
    Write-Host '      Laravel 13 dropped 8.2, so XAMPP 8.2 cannot run this app at all.'
    exit 1
}

# ---------------------------------------------------------------------------
# 2. Find the php.ini that interpreter actually loads.
# ---------------------------------------------------------------------------
# Not the one next to php.exe, and not php.ini-development: PHP reports the
# file it loaded, and writing to any other one changes nothing while looking
# like it worked.

Write-Step 'Locating the loaded php.ini'

$ini = & $php -r 'echo php_ini_loaded_file() ?: "";'

if (-not $ini) {
    # A WinGet install ships no php.ini at all, so there is nothing to append
    # to. The default location is beside the binary.
    $ini = Join-Path (Split-Path $php -Parent) 'php.ini'
    Write-Warn "No php.ini is loaded. Will create $ini"

    if (-not $DryRun) {
        New-Item -ItemType File -Path $ini -Force | Out-Null
    }
} else {
    Write-Ok $ini
}

# ---------------------------------------------------------------------------
# 3. Find a CA bundle, or fetch one.
# ---------------------------------------------------------------------------
# Prefers a bundle already on the machine over a download: XAMPP ships one,
# and a file that is already there cannot fail to arrive.

Write-Step 'Locating a CA certificate bundle'

$bundle = $null

# Where this script keeps a bundle it downloaded. Under the user profile, so no
# elevation is needed; falls back to the temp directory if LOCALAPPDATA is not
# set, rather than assuming an environment variable is always there - that
# assumption is what made the PATH override fail four times.
$bundleHome = if ($env:LOCALAPPDATA) {
    Join-Path $env:LOCALAPPDATA 'php-cacert'
} else {
    Join-Path ([System.IO.Path]::GetTempPath()) 'php-cacert'
}

$known = @(
    'C:\xampp1\apache\bin\curl-ca-bundle.crt',
    'C:\xampp\apache\bin\curl-ca-bundle.crt',
    'C:\xampp1\php\extras\ssl\cacert.pem',
    'C:\xampp\php\extras\ssl\cacert.pem',
    (Join-Path (Split-Path $php -Parent) 'cacert.pem'),
    (Join-Path $bundleHome 'cacert.pem')
)

foreach ($path in $known) {
    if ($path -and (Test-Path $path)) {
        $bundle = $path
        Write-Ok "Found $bundle"
        break
    }
}

if (-not $bundle) {
    # Downloads into the user profile rather than next to php.exe. A php.exe in
    # Program Files is not writable without elevation, and a script that
    # demands Administrator to fetch a public file is a script people skip.
    $bundleDir = $bundleHome
    $bundle = Join-Path $bundleDir 'cacert.pem'

    Write-Warn "No bundle on disk. Downloading the Mozilla set to $bundle"

    if (-not $DryRun) {
        try {
            New-Item -ItemType Directory -Path $bundleDir -Force | Out-Null

            # curl.se publishes the Mozilla root set; this is the source curl's
            # own documentation points at.
            Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile $bundle -UseBasicParsing
        } catch {
            Write-Bad "Could not download the bundle: $($_.Exception.Message)"
            Write-Host ''
            Write-Host '      Fetch it by hand instead:' -ForegroundColor Yellow
            Write-Host '        1. Open https://curl.se/docs/caextract.html in a browser' -ForegroundColor Yellow
            Write-Host '        2. Save cacert.pem to' $bundle -ForegroundColor Yellow
            Write-Host '        3. Re-run this script' -ForegroundColor Yellow
            Write-Host ''
            Write-Host '      Or copy the one XAMPP ships, if you have it:' -ForegroundColor Yellow
            Write-Host '        C:\xampp\apache\bin\curl-ca-bundle.crt' -ForegroundColor Yellow
            exit 1
        }
    }
}

# A truncated or HTML-error-page download is worse than none: php.ini would
# name a file that exists, so the setting looks right while every call still
# fails with the same cURL error 60. The real bundle is a few hundred KB of
# PEM blocks.
if (-not $DryRun) {
    $bundleFile = Get-Item $bundle

    if ($bundleFile.Length -lt 50KB) {
        Write-Bad "$bundle is only $($bundleFile.Length) bytes - that is not a CA bundle."
        Write-Host '      Delete it and re-run, or fetch it by hand from https://curl.se/docs/caextract.html' -ForegroundColor Yellow
        exit 1
    }

    if (-not (Select-String -Path $bundle -Pattern 'BEGIN CERTIFICATE' -Quiet)) {
        Write-Bad "$bundle contains no certificates. It is probably an error page."
        exit 1
    }

    Write-Ok "$bundle looks like a real bundle ($([math]::Round($bundleFile.Length / 1KB)) KB)"
}

# ---------------------------------------------------------------------------
# 4. Write the settings, once.
# ---------------------------------------------------------------------------
# curl.cainfo covers Guzzle and therefore Laravel's HTTP client, which is what
# every provider adapter goes through. openssl.cafile covers PHP's own stream
# wrappers - a separate setting that bites later, on file_get_contents or SMTP.

Write-Step 'Configuring the certificate paths'

$existing = if (Test-Path $ini) { Get-Content $ini -Raw } else { '' }
$toAppend = @()

foreach ($setting in 'curl.cainfo', 'openssl.cafile') {
    # Matches an active setting only. A commented-out line is not a setting,
    # and treating it as one would leave the install broken while reporting
    # that it was already configured.
    if ($existing -match "(?m)^\s*$([regex]::Escape($setting))\s*=") {
        Write-Ok "$setting is already set - leaving it alone"
    } else {
        $toAppend += "$setting = `"$bundle`""
    }
}

if ($toAppend.Count -eq 0) {
    Write-Ok 'Nothing to change'
} elseif ($DryRun) {
    Write-Warn "Would append to $ini :"
    $toAppend | ForEach-Object { Write-Host "        $_" }
} else {
    try {
        Add-Content -Path $ini -Value ("`n; Added by scripts\windows-php-setup.ps1 - PHP on Windows ships no root certificates.`n" + ($toAppend -join "`n"))
    } catch {
        # A php.ini under Program Files needs an elevated shell. Naming that is
        # the difference between a one-line fix and an afternoon.
        Write-Bad "Could not write to $ini : $($_.Exception.Message)"
        Write-Host ''
        Write-Host '      That file needs Administrator. Either re-run this script from an' -ForegroundColor Yellow
        Write-Host '      elevated PowerShell, or open it in an editor as Administrator and add:' -ForegroundColor Yellow
        Write-Host ''
        $toAppend | ForEach-Object { Write-Host "        $_" -ForegroundColor Yellow }
        exit 1
    }

    $toAppend | ForEach-Object { Write-Ok "Appended $_" }
}

if ($DryRun) {
    Write-Step 'Dry run - nothing was changed and nothing was called'
    exit 0
}

# ---------------------------------------------------------------------------
# 5. Confirm PHP read them.
# ---------------------------------------------------------------------------

Write-Step 'Verifying PHP picked the settings up'

$readBack = & $php -r 'echo ini_get("curl.cainfo") . "|" . ini_get("openssl.cafile");'
$curlIni, $opensslIni = $readBack -split '\|'

if (-not $curlIni) {
    Write-Bad "curl.cainfo is still empty. PHP is not loading $ini"
    Write-Host '      Check the "Loaded Configuration File" line in:  php --ini'
    exit 1
}

Write-Ok "curl.cainfo    = $curlIni"
Write-Ok "openssl.cafile = $opensslIni"

# ---------------------------------------------------------------------------
# 6. Prove it against the real host, for free.
# ---------------------------------------------------------------------------
# An unauthenticated HEAD to the queue host. It will be refused, and that is
# the point: any HTTP status means the TLS handshake and certificate chain
# verified, which is the only thing being tested. No generation endpoint is
# called and nothing is billed.

Write-Step 'Opening a real TLS connection to fal (no cost)'

$probe = Join-Path ([System.IO.Path]::GetTempPath()) 'fal_tls_probe.php'

@'
<?php
$ch = curl_init("https://queue.fal.run/");
curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
curl_exec($ch);

$errno = curl_errno($ch);

if ($errno === 0) {
    echo "OK|" . curl_getinfo($ch, CURLINFO_HTTP_CODE);
    exit;
}

// Separate "the certificates are wrong" from "the network would not carry it".
// Reporting a blocked proxy as a trust failure sends people to edit php.ini
// again, which is exactly the misdiagnosis this script exists to prevent.
//   60 CURLE_PEER_FAILED_VERIFICATION   77 CURLE_SSL_CACERT_BADFILE
//   58 CURLE_SSL_CERTPROBLEM            83 CURLE_SSL_ISSUER_ERROR
$certErrors = [58, 60, 77, 83];

echo (in_array($errno, $certErrors, true) ? "CERT|" : "NETWORK|")
    . $errno . " " . curl_error($ch);
'@ | Set-Content -Path $probe -Encoding UTF8

$result = & $php $probe
Remove-Item $probe -Force -ErrorAction SilentlyContinue

$outcome, $detail = $result -split '\|', 2

switch ($outcome) {
    'OK' {
        Write-Ok "TLS verified. queue.fal.run answered HTTP $detail"
        Write-Host ''
        Write-Host 'A 401 or 404 here is expected and correct - the request carried no key.' -ForegroundColor DarkGray
        Write-Host 'What matters is that the certificate chain verified.' -ForegroundColor DarkGray
    }

    'CERT' {
        Write-Bad "The certificate chain still does not verify: $detail"
        Write-Host ''
        Write-Host '      The bundle is named in php.ini but PHP cannot use it. Check that' -ForegroundColor Yellow
        Write-Host "      $bundle is readable, and that php --ini reports $ini" -ForegroundColor Yellow
        Write-Host ''
        Write-Host '      Do NOT work around this by disabling certificate verification.' -ForegroundColor Yellow
        Write-Host '      That would send your API key over a connection nobody authenticated,' -ForegroundColor Yellow
        Write-Host '      which is the exact attack verification exists to stop.' -ForegroundColor Yellow
        exit 1
    }

    default {
        # Resolution, connection, proxy and timeout failures. The certificate
        # configuration above is in place; this machine simply could not carry
        # the request far enough to prove it.
        Write-Warn "Certificates are configured, but the connection did not complete: $detail"
        Write-Host ''
        Write-Host '      This is a network problem, not a certificate problem - a proxy, a' -ForegroundColor Yellow
        Write-Host '      firewall, or no route to fal.ai. The php.ini change above is still' -ForegroundColor Yellow
        Write-Host '      correct and still needed; it just could not be proven from here.' -ForegroundColor Yellow
        Write-Host ''
        Write-Host '      Re-run this script once the host is reachable.' -ForegroundColor Yellow
        exit 2
    }
}

# ---------------------------------------------------------------------------
# 7. Hand over.
# ---------------------------------------------------------------------------

Write-Step 'Ready'
Write-Host ''
Write-Host '  Use this interpreter for every command in this project:' -ForegroundColor White
Write-Host ''
Write-Host "      `$php = `"$php`"" -ForegroundColor White
Write-Host '      & $php artisan test' -ForegroundColor White
Write-Host ''
Write-Host '  To avoid re-deriving it in each new terminal, move the PHP 8.3+ folder' -ForegroundColor DarkGray
Write-Host '  above C:\xampp*\php in your user PATH. Apache is unaffected: it loads' -ForegroundColor DarkGray
Write-Host '  its own bundled PHP from httpd-xampp.conf and never consults PATH.' -ForegroundColor DarkGray
Write-Host ''
