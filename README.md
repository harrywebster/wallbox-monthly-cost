# Wallbox (EV Charger) cost report

Report EV charging cost for a given user and charger via email and/or Google Sheets.
Supports both daily (yesterday's data) and monthly (month-to-date) reporting modes.

## Install

Tested on Ubuntu 24.04 and PHP 8.3.

```bash
apt-get update
apt-get install -y php-yaml composer
composer update
```

## Configuration

1. Copy `config.yaml.template` to `config.yaml` and fill in your credentials:
   - Wallbox API credentials
   - Email server settings (SMTP)
   - Google OAuth credentials (if using Google Sheets)
   - ExchangeRate API key

2. For Google Sheets integration:
   - Create OAuth 2.0 credentials in Google Cloud Console
   - Download the credentials JSON file as `google_client_secrets.json`
   - On first run with `-s`, you'll be prompted to authorize access

## Usage

`php ev-charge.php [-u] [-e] [-s] [-d] [-q] [-h]`

### Flags

 - `-u` Download latest Wallbox sessions and exchange rate JSON
 - `-e` Email a copy of the report
 - `-s` Append data to Google Sheet
 - `-d` Daily mode (yesterday's data, default is month-to-date)
 - `-q` Quiet mode (no output except errors, useful for cron)
 - `-h` Show this help

### Examples

 - `php ev-charge.php -u` - Refresh JSON only
 - `php ev-charge.php -e` - Read existing JSON, print + email report (month-to-date)
 - `php ev-charge.php -u -e` - Refresh JSON then print + email (month-to-date)
 - `php ev-charge.php -u -s` - Refresh JSON and append monthly data to Google Sheet
 - `php ev-charge.php -u -d -s` - Refresh JSON and append yesterday's data to Google Sheet
 - `php ev-charge.php -u -d -s -q` - Daily cron job (quiet mode)

### Cron Examples

For daily tracking (runs at 2am, logs yesterday's data):
```cron
0 2 * * * /usr/bin/php /path/to/ev-charge.php -u -d -s -q
```

For monthly summary (runs on 1st of each month):
```cron
0 3 1 * * /usr/bin/php /path/to/ev-charge.php -u -e -s -q
```

## Google Sheets Format

The script appends data to your configured Google Sheet with the following columns:
- **Column A**: Date (YYYY-MM-DD)
- **Column B**: Total kWh
- **Column C**: Total cost (GBP)
- **Column D**: Fetch type (`daily` or `monthly`)

## Example output

```
On 2025-10-12 for 11 hours 58 minutes (718 minutes)
Energy 13.581 kWh at cost 0.83 GBP (0.95 EUR)
Energy cost was 0.061 GBP per kWh

On 2025-10-11 for 11 hours 36 minutes (696 minutes)
Energy 13.784 kWh at cost 0.84 GBP (0.97 EUR)
Energy cost was 0.061 GBP per kWh

Total cost is 7.34 GBP from 77.803 kWh (est cost 5.84 GBP if only using overnight power)
```
