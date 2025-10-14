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