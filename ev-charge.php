#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

//
// wallbox.php
// Flags:
//   -u  Update JSON files (Wallbox sessions + exchange rate)
//   -e  Email the report
//   -s  Append data to Google Sheet
//   -d  Daily mode (yesterday's data, default is month-to-date)
//   -q  Quiet mode (no output except errors)
//   -h  Show help
//

// Global flags
$GLOBALS['quiet'] = false;
$GLOBALS['daily'] = false;

// ---------- CLI entry ----------
(function (): void {
    $opts = getopt('uehsdq');

    if (isset($opts['h'])) {
        printUsage();
        exit(0);
    }

    // Set quiet mode
    if (isset($opts['q'])) {
        $GLOBALS['quiet'] = true;
    }

    // Set daily mode
    if (isset($opts['d'])) {
        $GLOBALS['daily'] = true;
    }

    $conf = parseConfig(__DIR__ . '/config.yaml');

    // Always compute & print the report. Optionally update and/or email.
    if (isset($opts['u'])) {
        updateData($conf);
    }

    $fetchType = $GLOBALS['daily'] ? 'daily' : 'monthly';
    [$summaryText, $perLineText, $kwhTotal, $gbpTotal] = buildReport($conf, $fetchType);
    if (!$GLOBALS['quiet']) {
        echo $perLineText;
        echo $summaryText;
    }

    if (isset($opts['e'])) {
        sendReportEmail($conf, $summaryText, $perLineText, $kwhTotal, $gbpTotal);
        if (!$GLOBALS['quiet']) {
            echo "Email sent successfully.\n";
        }
    }

    if (isset($opts['s'])) {
        appendToGoogleSheet($conf, $kwhTotal, $gbpTotal, $fetchType);
        if (!$GLOBALS['quiet']) {
            echo "Data appended to Google Sheet successfully.\n";
        }
    }
})();

// ---------- Config ----------

/**
 * Load YAML config and return as stdClass (recursively).
 */
function parseConfig(string $path): stdClass
{
    if (!file_exists($path)) {
        fwrite(STDERR, "Missing config.yaml at {$path}\n");
        exit(2);
    }
    /** @var array<string,mixed> $arr */
    $arr = yaml_parse_file($path);
    if (!is_array($arr)) {
        fwrite(STDERR, "config.yaml did not parse to an array.\n");
        exit(2);
    }
    return json_decode(json_encode($arr, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
}

/**
 * Print CLI usage.
 */
function printUsage(): void
{
    $self = basename(__FILE__);
    echo <<<TXT
Usage:
  php {$self} [-u] [-e] [-s] [-d] [-q] [-h]

Flags:
  -u   Download latest Wallbox sessions and exchange rate JSON
  -e   Email a copy of the report
  -s   Append data to Google Sheet
  -d   Daily mode (yesterday's data, default is month-to-date)
  -q   Quiet mode (no output except errors, useful for cron)
  -h   Show this help

Examples:
  php {$self} -u         # refresh JSON only
  php {$self} -e         # read existing JSON, print + email report (month-to-date)
  php {$self} -u -e      # refresh JSON then print + email (month-to-date)
  php {$self} -u -s      # refresh JSON then append to Google Sheet (monthly)
  php {$self} -u -d -s   # refresh JSON and append yesterday's data to Google Sheet
  php {$self} -u -e -s   # refresh JSON, email report, and append to Google Sheet
  php {$self} -u -d -s -q # refresh JSON, append yesterday's data (quiet for daily cron)

TXT;
}

// ---------- Data update (Wallbox + exchange rate) ----------

/**
 * Update local JSON files from Wallbox and ExchangeRate host.
 */
function updateData(stdClass $conf): void
{
    if (!$GLOBALS['quiet']) {
        echo "Updating data...\n";
    }

    $http = new Client([
        'base_uri' => 'https://api.wall-box.com/',
        'timeout'  => 15,
        'headers'  => [
            'Accept'       => 'application/json, text/plain, */*',
            'Content-Type' => 'application/json;charset=utf-8',
        ],
    ]);

    $email     = (string) $conf->wallbox->username;
    $password  = (string) $conf->wallbox->password;
    $chargerId = (int) $conf->wallbox->charger_id;

    try {
        $jwt     = getJwt($http, $email, $password);
        $session = getChargeSessions($http, $jwt, $chargerId);
        $session['last_update'] = time();

        file_put_contents(
            __DIR__ . '/session.json',
            json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        if (!$GLOBALS['quiet']) {
            echo "  • Sessions: session.json [ok]\n";
        }
    } catch (RequestException $e) {
        $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
        fwrite(STDERR, "HTTP error (Wallbox): {$body}\n");
        exit(1);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error (Wallbox): {$e->getMessage()}\n");
        exit(1);
    }

    try {
        $fx = fetchExchangeRate((string) $conf->exchangerate_host->api_key, 'EUR', 'GBP', 1);
        file_put_contents(
            __DIR__ . '/exchange.json',
            json_encode($fx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        if (!$GLOBALS['quiet']) {
            echo "  • Exchange rate: exchange.json [ok]\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Error (FX): {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * Get Wallbox JWT using Basic auth.
 */
function getJwt(Client $http, string $email, string $password): string
{
    $basic = base64_encode($email . ':' . $password);
    $resp  = $http->post('auth/token/user', [
        'headers' => ['Authorization' => "Basic {$basic}"],
    ]);

    /** @var array<string,mixed> $data */
    $data = json_decode((string) $resp->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $jwt  = $data['jwt'] ?? $data['token'] ?? null;

    if (!is_string($jwt) || $jwt === '') {
        throw new RuntimeException('No JWT in response');
    }
    return $jwt;
}

/**
 * Fetch charge sessions (stats).
 * Note: endpoint currently doesn't require chargerId param in URL; adjust if needed.
 * Returns decoded array for persistence.
 *
 * @return array<string,mixed>
 */
function getChargeSessions(Client $http, string $jwt, int $chargerId): array
{
    $resp = $http->get('v4/sessions/stats', [
        'headers' => ['Authorization' => "Bearer {$jwt}"],
    ]);
    /** @var array<string,mixed> */
    return json_decode((string) $resp->getBody(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fetch exchange rate via exchangerate.host convert endpoint.
 *
 * @return array<string,mixed>
 */
function fetchExchangeRate(string $apiKey, string $from, string $to, int|float $amount): array
{
    $qs = http_build_query([
        'from'       => $from,
        'to'         => $to,
        'amount'     => $amount,
        'access_key' => $apiKey,
    ], '', '&', PHP_QUERY_RFC3986);

    $url      = "https://api.exchangerate.host/convert?{$qs}";
    $response = @file_get_contents($url);
    if ($response === false) {
        throw new RuntimeException('Failed to download exchange rate JSON');
    }

    /** @var array<string,mixed> */
    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

// ---------- Reporting ----------

/**
 * Build a per-session report + totals.
 *
 * @param string $fetchType 'daily' for yesterday's data, 'monthly' for month-to-date
 * @return array{0:string,1:string,2:float,3:float} [summaryText, perLineText, totalKwh, totalGbp]
 */
function buildReport(stdClass $conf, string $fetchType = 'monthly'): array
{
    $sessionPath  = __DIR__ . '/session.json';
    $exchangePath = __DIR__ . '/exchange.json';

    if (!file_exists($sessionPath)) {
        fwrite(STDERR, "Missing session.json. Run with -u first.\n");
        exit(3);
    }
    if (!file_exists($exchangePath)) {
        fwrite(STDERR, "Missing exchange.json. Run with -u first.\n");
        exit(3);
    }

    $sessionJson  = json_decode((string) file_get_contents($sessionPath), false, 512, JSON_THROW_ON_ERROR);
    $exchangeJson = json_decode((string) file_get_contents($exchangePath), false, 512, JSON_THROW_ON_ERROR);

    /** @var array<int,object> $sessions */
    $sessions = is_array($sessionJson->data ?? null) ? $sessionJson->data : [];
    $fxRate   = (float) ($exchangeJson->result ?? 0.0);

    $tz           = new DateTimeZone((string) $conf->timezone);
    $targetEmail  = (string) $conf->wallbox->charger_user;
    $now          = new DateTimeImmutable('now', $tz);

    // Determine date range based on fetch type
    if ($fetchType === 'daily') {
        // Yesterday's data
        $targetDate = $now->modify('-1 day')->format('Y-m-d');
    } else {
        // Month-to-date
        $targetMonth = $now->format('Y-m');
    }

    $totalCostGbp = 0.0;
    $totalKwh     = 0.0;

    $lines = [];

    foreach ($sessions as $session) {
        $stats = $session->attributes ?? null;
        if (!is_object($stats)) {
            continue;
        }

        $energyKwh = (float) ($stats->energy ?? 0.0);
        if ($energyKwh <= 0.0) {
            continue;
        }

        $userEmail = (string) ($stats->user_email ?? '');
        if (strcasecmp($userEmail, $targetEmail) !== 0) {
            continue;
        }

        $startTs = (int) ($stats->start ?? 0);
        $endTs   = (int) ($stats->end ?? 0);
        if ($endTs <= 0 || $startTs <= 0) {
            continue;
        }

        $endLocal   = (new DateTimeImmutable('@' . $endTs))->setTimezone($tz);
        $endDayStr  = $endLocal->format('Y-m-d');
        $endMonth   = $endLocal->format('Y-m');

        // Filter by date range based on fetch type
        if ($fetchType === 'daily') {
            // Only include yesterday's sessions
            if ($endDayStr !== $targetDate) {
                continue;
            }
        } else {
            // Only include this month
            if ($endMonth !== $targetMonth) {
                continue;
            }
        }

        $activeMinutes = max(0, (int) round(($endTs - $startTs) / 60));
        $costEur       = (float) ($stats->cost ?? 0.0);
        $costGbp       = $costEur * $fxRate;

        $totalKwh     += $energyKwh;
        $totalCostGbp += $costGbp;

        $lines[] = sprintf(
            "On %s for %s (%d minutes)\nEnergy %.3f kWh at cost %.2f GBP (%.2f EUR)\nEnergy cost was %.3f GBP per kWh\n\n",
            $endDayStr,
            minutesToHoursMinutes($activeMinutes),
            $activeMinutes,
            $energyKwh,
            $costGbp,
            $costEur,
            $energyKwh > 0 ? ($costGbp / $energyKwh) : 0.0
        );
    }

    $overnightEstimate = $totalKwh * 0.075; // configurable if you want
    $summary = sprintf(
        "Total cost is %.2f GBP from %.3f kWh (est cost %.2f GBP if only using overnight power)\n\n",
        $totalCostGbp,
        $totalKwh,
        $overnightEstimate
    );

    return [$summary, implode('', $lines), $totalKwh, $totalCostGbp];
}

/**
 * Format minutes as HH hours MM minutes.
 */
function minutesToHoursMinutes(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $mins  = $minutes % 60;
    return sprintf('%02d hours %02d minutes', $hours, $mins);
}

// ---------- Email ----------

/**
 * Send report email using PHPMailer SMTP settings from config.
 */
function sendReportEmail(stdClass $conf, string $summaryText, string $perLineText, float $kwhTotal, float $gbpTotal): void
{
    $mailer = new PHPMailer(true);

    $smtpHost = (string) $conf->email_server->smtp_host;
    $smtpUser = (string) $conf->email_server->smtp_username;
    $smtpPass = (string) $conf->email_server->smtp_password;
    $smtpPort = (int) $conf->email_server->smtp_port;

    $fromAddr = (string) $conf->email_server->from_address;
    $fromName = (string) $conf->email_server->from_name;
    $to       = (string) $conf->email_report_to;

    // Simple HTML body with a monospace block for the per-line section
    $htmlBody = <<<HTML
<p><strong>EV charge report</strong></p>
<p><strong>Totals:</strong><br>
Total energy: <strong>{$kwhTotal}</strong> kWh<br>
Total cost: <strong>{$gbpTotal}</strong> GBP
</p>
<pre style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:14px; line-height:1.4; white-space:pre-wrap; background:#f6f8fa; padding:12px; border-radius:8px; border:1px solid #eaecef;">
{$perLineText}{$summaryText}
</pre>
HTML;

    try {
        $mailer->isSMTP();
        $mailer->Host       = $smtpHost;
        $mailer->SMTPAuth   = true;
        $mailer->Username   = $smtpUser;
        $mailer->Password   = $smtpPass;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mailer->Port       = $smtpPort;

        $mailer->setFrom($fromAddr, $fromName);
        $mailer->addAddress($to);

        $mailer->isHTML(true);
        $mailer->Subject = 'EV charge report';
        $mailer->Body    = $htmlBody;
        $mailer->AltBody = strip_tags($perLineText . "\n" . $summaryText);

        $mailer->send();
    } catch (MailerException $e) {
        fwrite(STDERR, "Email failed: {$mailer->ErrorInfo}\n");
        exit(4);
    }
}

// ---------- Google Sheets ----------

/**
 * Append cost and kWh data to Google Sheet using OAuth.
 *
 * @param string $fetchType 'daily' or 'monthly'
 */
function appendToGoogleSheet(stdClass $conf, float $kwhTotal, float $gbpTotal, string $fetchType = 'monthly'): void
{
    $credentialsFile = (string) $conf->google->credentials_file;
    $spreadsheetId   = (string) $conf->google->spreadsheet_id;
    $sheetName       = (string) $conf->google->sheet_name;

    $credentialsPath = __DIR__ . '/' . $credentialsFile;
    if (!file_exists($credentialsPath)) {
        fwrite(STDERR, "Missing credentials file: {$credentialsPath}\n");
        exit(5);
    }

    try {
        $client = new Google_Client();
        $client->setApplicationName('EV Charge Report');
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $client->setAuthConfig($credentialsPath);
        $client->setRedirectUri('http://localhost');
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $tokenPath = __DIR__ . '/google_token.json';

        // Load existing token or request new one
        if (file_exists($tokenPath)) {
            $accessToken = json_decode((string) file_get_contents($tokenPath), true, flags: JSON_THROW_ON_ERROR);
            $client->setAccessToken($accessToken);
        }

        // Refresh token if expired
        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                // Request new authorization
                $authUrl = $client->createAuthUrl();
                echo "Open this URL in your browser and authorize:\n{$authUrl}\n\n";
                echo "After authorizing, you'll be redirected to localhost (which will fail).\n";
                echo "Copy the FULL URL from your browser's address bar and paste it here.\n";
                echo "Paste the redirect URL: ";
                $redirectUrl = trim((string) fgets(STDIN));

                // Parse the authorization code from the URL
                $urlParts = parse_url($redirectUrl);
                parse_str($urlParts['query'] ?? '', $queryParams);
                $authCode = $queryParams['code'] ?? '';

                if (empty($authCode)) {
                    throw new RuntimeException('No authorization code found in URL');
                }

                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

                if (isset($accessToken['error'])) {
                    throw new RuntimeException('Error fetching access token: ' . ($accessToken['error_description'] ?? $accessToken['error']));
                }
            }

            // Save the token
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken(), JSON_THROW_ON_ERROR));
        }

        $service = new Google_Service_Sheets($client);

        // Get current date
        $tz = new DateTimeZone((string) $conf->timezone);
        $currentDate = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

        // Find the last row with data
        $readRange = "{$sheetName}!A:D";
        $response = $service->spreadsheets_values->get($spreadsheetId, $readRange);
        $existingValues = $response->getValues();
        $lastRow = $existingValues ? count($existingValues) : 0;
        $nextRow = $lastRow + 1;

        // Prepare row data: [date, kWh, cost in GBP, fetch type]
        $values = [
            [$currentDate, $kwhTotal, $gbpTotal, $fetchType]
        ];

        $body = new Google_Service_Sheets_ValueRange([
            'values' => $values
        ]);

        $params = [
            'valueInputOption' => 'USER_ENTERED'
        ];

        // Write to the specific next row
        $writeRange = "{$sheetName}!A{$nextRow}:D{$nextRow}";
        $service->spreadsheets_values->update($spreadsheetId, $writeRange, $body, $params);

    } catch (Throwable $e) {
        fwrite(STDERR, "Google Sheets error: {$e->getMessage()}\n");
        exit(5);
    }
}