SPARGS FUEL + GAS/LPG INTELLIGENCE - AUTOMATIC WEB BUILD
=========================================================

INSTALL ON THE WEBSITE
----------------------
Upload the complete folder, preserving index.html, assets, data and api.
Open index.html through the website URL. The automatic updater requires PHP hosting; it will not run when index.html is opened directly from a Windows folder.

AUTOMATIC DATA
--------------
- The dashboard calls api/live-data.php on opening, every 30 minutes, and when Refresh now is pressed.
- Independent web sources are fetched concurrently so a slow publisher does not block the whole dashboard.
- Fuel values prioritise the newest dated official CEF anchor. Secondary predictor data is accepted only when it is at least as new as the official packaged anchor.
- USD/ZAR, Brent context, official-page health, Fuel news and Gas/LPG news refresh automatically.
- The LPG 60/40 Saudi propane-butane benchmark, live FX conversion and forecast scenarios are recalculated when a safely parseable monthly CP pair is found.
- If a source is blocked, unavailable or changes format, the last verified value stays active. The updater never invents a replacement.

OPTIONAL HOURLY CPANEL CRON
---------------------------
php /home/USERNAME/public_html/YOUR_FOLDER/api/cron-update.php

SERVER REQUIREMENTS
-------------------
- PHP 8.0 or newer
- PHP cURL and SimpleXML extensions required for full automatic web retrieval
- outbound HTTPS enabled
- write permission for data/live.json and data/live.lock

BRANDING AND PRODUCT COLOURS
----------------------------
The supplied Spargs POS System SVG is used from assets/spargs-superspar-logo.svg.
The site palette comes from the logo: deep navy, electric blue, orange and silver/white.
Operational product colours:
- Petrol: green
- Diesel: yellow
- Illuminating paraffin: light blue
- Gas / LPG: red

STORE OPERATIONS
----------------
The stock screen starts with one editable store named Example. Use + Add Store to add your own stores. Store names and values are saved only in that browser.

WHATSAPP
--------
Leave the number blank to open the WhatsApp contact chooser, or enter a full number with country code for direct sending. The page also provides native Share and Copy summary fallbacks.

FORECAST ARCHITECTURE
---------------------
FUEL ENGINE
- Latest available official CEF accumulated over/(under)-recovery by grade is the primary anchor.
- Petrol: Mediterranean and Singapore finished-product framework.
- Diesel and paraffin: Mediterranean and Arabian Gulf finished-product framework.
- USD/ZAR and landed costs follow the BFP logic.
- Best, central and worst cases reflect remaining review-period volatility.
- Slate Levy and policy components remain separate until officially published.

GAS / LPG ENGINE
- 60% Saudi propane Contract Price + 40% Saudi butane Contract Price.
- Separate USD/ZAR, VLGC freight, insurance, storage, demurrage, cargo dues and financing.
- Separate regulated/local retail components and zone differentials.
- Gas/LPG never uses the petrol/diesel CEF recovery or Slate Levy.

VERIFIED PACKAGED ANCHOR
------------------------
The fallback headline uses the official CEF Daily Basic Fuel Price report through 17 July 2026:
- Petrol 95: indicative decrease R0.90435/l
- Petrol 93: indicative decrease R0.94890/l
- Diesel 500ppm: indicative increase R0.23543/l
- Diesel 50ppm: effectively flat, indicative increase 0.055c/l
- Illuminating paraffin: effectively flat, indicative decrease 0.206c/l

IMPORTANT
---------
Forecasts are indications, not official monthly announcements. The final DMPR/CEF adjustment and gazetted LPG maximum prices always override the model.
