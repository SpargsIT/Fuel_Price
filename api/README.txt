SPARGS AUTOMATIC DATA UPDATER
=============================

The dashboard calls api/live-data.php when the page opens, every 30 minutes and when Refresh now is pressed.

AUTOMATIC SOURCES
-----------------
- Newest verified official CEF daily anchor packaged with the site
- CEF-linked predictor pages by fuel grade, protected by a source-date freshness guard
- USD/ZAR from two public no-key exchange-rate services
- Brent context from a public market endpoint
- DMPR monthly publication page and CEF daily archive health
- Live Fuel and Gas/LPG Google News RSS feeds
- Monthly Saudi propane/butane CP discovery with safe parsing
- LPG 60/40 benchmark, FX conversion and scenarios recalculated automatically

PERFORMANCE AND SAFETY
----------------------
Independent web sources are fetched concurrently with a 10-second timeout. Results are cached for 30 minutes. If a source fails or changes format, its verified fallback is retained and the health badge reports the failure. No missing value is replaced with a guess.

OPTIONAL CPANEL CRON
--------------------
Run hourly:
php /home/USERNAME/public_html/YOUR_FOLDER/api/cron-update.php

PHP REQUIREMENTS
----------------
- PHP 8.0+
- PHP cURL extension required for full automatic web retrieval
- SimpleXML extension recommended
- outbound HTTPS access
- write permission for data/live.json and data/live.lock
