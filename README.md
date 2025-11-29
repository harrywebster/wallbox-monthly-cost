# Wallbox (EV Charger) cost report

Report via email last months EV charging cost for a given user and charger.

## Install

Tested on Ubuntu 24.04 and PHP 8.3.

```bash
apt-get update
apt-get install -y php-yaml composer
composer update
```

## Usage

`php ev-charge.php [-u] [-e] [-s] [-q] [-h]`

Flags:
 - `-u` Download latest Wallbox sessions and exchange rate JSON
 - `-e` Email a copy of the report
 - `-s` Update Google Sheets with data
 - `-q` Quiet, dont print anything to STDOUT
 - `-h` Show this help

Examples:
 - `php ev-charge.php -u` refresh JSON only
 - `php ev-charge.php -e` read existing JSON, print + email report
 - `php ev-charge.php -u -e` refresh JSON then print + email

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
