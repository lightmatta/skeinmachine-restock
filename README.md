# Skeinmachine Restock

Self-hosted **PHP / AJAX / HTML** portal for tracking supplier stock and
raising restock orders. The application lives in [`portal/`](portal/). The web
root is `portal/public/`.

## What it does

- **Products** — catalog synced from Shopify (API or CSV). Admin columns:
  vendor, **Min** (hover: *Set minimum quantity for restock*), **Goal** (hover:
  *Goal Stock Level*), stock, and active/inactive status. SPT, wholesale, WS,
  colours, variegated and wholesale-status columns are gone. Select multiple
  rows to bulk-set status the same way as before.
- **Vendors** — suppliers you buy from. Vendor ID, name, and one stock URL or
  a list of URLs (scraped one at a time).
- **Vendor products** — scraped supplier catalog, matched to Products by SKU,
  product id, then title. Same spreadsheet grid: sort, keyword filter on names,
  group/filter by vendor, line or bulk active/inactive.
- **Restock orders** — per-vendor breakdown of catalog items at or below Min.
  Top of each vendor: vendor stock &gt; 0 (can order). Bottom: vendor stock is 0
  (cannot fulfil). Goal drives the recommended order quantity.
- **Schedule** — place those restock lines on a timescale (same Gantt controls,
  now vendor-coloured).
- **Settings** — branding, contact, and **Shopify sync** (domain, Client ID,
  secret, collection, periodic stock sync). Wholesale, notifications and
  front-page HTML settings are removed. Analytics is removed.

## Quick start

```bash
cd portal
php bin/install.php --seed
php bin/hash.php            # paste the hash into config/config.php
php -S 127.0.0.1:8080 -t public
```

Demo admin: `admin@housedye.example` / `admin12345`

```bash
php bin/check-restock-vendors.php
```
