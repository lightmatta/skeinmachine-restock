# HouseDye Portal

An independent, self-hostable **PHP / AJAX / HTML** portal for a yarn-dyeing
business: a public CMS front page plus a secure **wholesaler ordering portal**
and an admin back office. No framework and no external services — pure **PHP 8 +
PDO/SQLite** — so it runs on a plain Ubuntu server with Apache or nginx.

> The application lives in [`portal/`](portal/). The web root is
> `portal/public/`. Server-side secrets (`portal/config/config.php`) and the
> database (`portal/data/`) live **outside** the web root.

## Features

- **Overarching superuser** defined in `config/config.php` with a one-way bcrypt
  hash. It is never stored in the database and no other user can view or change
  it through the app. Utility `bin/hash.php` generates the hash.
- **User classes**: superuser · admin · wholesale client · guest. Hardened
  sessions, CSRF protection, bcrypt passwords.
- **Public front page** (CMS): searchable products & bundles (title/description),
  with **no prices or stock** shown publicly, and an "Apply for a wholesale
  account" flow that creates a guest account.
- **Approval workflow**: admins approve guests → wholesale clients, who then see
  pricing, stock, ordering and their dashboard.
- **Wholesale ordering**: catalog, cart, **bundles with minimum quantities**
  (per-account override), checkout, order states **pending → provisioning**
  (locked after provisioning), **per-client data isolation**, printable orders.
- **Chatbox**: polling client↔support chat with a **native rule-based
  auto-responder** (keyword smarts + offline holding message with your phone).
- **Admin back office**: dashboard (pending applications, new orders sorted with
  payment-pending first, chats needing replies, recent activity) and
  spreadsheet-style AJAX grids for **Orders, Users, Products, Bundles** with
  inline edit, sort, column hide/show, search, filter, archive/delete.
- **Analytics** with a **native PHP rule-based NLP engine** (no external LLM):
  ask in plain English (date presets incl. "this financial year", client
  filters) to get tabulated results + inline **SVG charts**.
- **Settings**: branding (logo, coral highlight color), contact/hours, wholesale
  toggle + preface, and an HTML editor for the About/Contact/Products pages.
- **Mobile**: token-authenticated JSON API + installable **PWA** (Android/iPhone)
  with notifications, plus a Capacitor recipe for native App Store/Play Store
  builds. See [`portal/mobile/README.md`](portal/mobile/README.md).
- **Look & feel**: minimal all-white, black outline **inline SVG** icons, coral
  highlight (configurable in Settings).

## Quick start (local)

```bash
cd portal

# 1) Create config + database (+ demo data), then set the superuser password.
php bin/install.php --seed
php bin/hash.php            # paste the printed hash into config/config.php
                           # (superuser.password_hash) and set a real app_secret

# 2) Run it (dev server). Web root is public/.
php -S 127.0.0.1:8080 -t public
# open http://127.0.0.1:8080/
```

### Demo logins (from `--seed`)

| Role | Login | Password |
| --- | --- | --- |
| Superuser | `superadmin` | *(the hash you set in config.php)* |
| Admin | `admin@housedye.example` | `admin12345` |
| Wholesale | `shop@example.com` | `shop12345` |
| Guest (pending) | `pending@example.com` | `guest12345` |

## Deploying on Ubuntu (Apache example)

```bash
sudo apt-get update
sudo apt-get install -y apache2 php php-sqlite3 php-mbstring libapache2-mod-php

# Put the project somewhere like /var/www/housedye and point the vhost at public/
sudo mkdir -p /var/www/housedye && sudo cp -r portal /var/www/housedye/

# Configure + secure
cd /var/www/housedye/portal
sudo -u www-data php bin/install.php --seed
sudo -u www-data php bin/hash.php     # set superuser hash in config/config.php
sudo chown -R www-data:www-data data config
```

Apache virtual host:

```apache
<VirtualHost *:80>
    ServerName portal.example.com
    DocumentRoot /var/www/housedye/portal/public
    <Directory /var/www/housedye/portal/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

nginx + php-fpm: set `root .../portal/public;` and route requests to
`index.php`. Because routing uses `?r=`, no rewrite rules are required.

### Security notes
- Keep `config/` and `data/` **outside** the document root (they already are).
- Serve over **HTTPS** and set `session.cookie_secure => true` in `config.php`.
- Change `app_secret` and the superuser password on install.
- `config/config.php` and `data/*.sqlite` are git-ignored and must never be
  committed or web-served.

## Project layout

```
portal/
├── public/            Web root (index.php front controller, assets, PWA, sw.js)
├── app/               Controllers, Views, Auth, Database, NLP engine, Icons
├── config/            config.sample.php (+ your config.php)  ← superuser + secrets
├── migrations/        schema.sql + demo seed
├── bin/               hash.php (hash generator), install.php (installer)
└── mobile/            PWA notes + Capacitor recipe for native apps
```
