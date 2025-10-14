# Wallbox (EV Charger) cost report

Report via email last months EV charging cost for a given user and charger.

## Install

Tested on Ubuntu 24.04 and PHP 8.3.

```bash
apt-get install -y php-yaml php-composer
composer update
```

## Usage

`php ev-charge.php [-u] [-e] [-h]`

Flags:
 - `-u` Download latest Wallbox sessions and exchange rate JSON
 - `-e` Email a copy of the report
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

On 2025-10-08 for 10 hours 44 minutes (644 minutes)
Energy 10.986 kWh at cost 0.67 GBP (0.77 EUR)
Energy cost was 0.061 GBP per kWh

On 2025-10-04 for 04 hours 40 minutes (280 minutes)
Energy 14.138 kWh at cost 3.33 GBP (3.82 EUR)
Energy cost was 0.235 GBP per kWh

On 2025-10-04 for 08 hours 45 minutes (525 minutes)
Energy 15.430 kWh at cost 0.94 GBP (1.08 EUR)
Energy cost was 0.061 GBP per kWh

On 2025-10-01 for 14 hours 19 minutes (859 minutes)
Energy 9.884 kWh at cost 0.73 GBP (0.84 EUR)
Energy cost was 0.074 GBP per kWh

Total cost is 7.34 GBP from 77.803 kWh (est cost 5.84 GBP if only using overnight power)
```