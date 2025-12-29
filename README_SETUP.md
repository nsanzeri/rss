# Ready Set Shows — Ops (v1) (PHP + MySQL)

## 1) Create DB
In phpMyAdmin or MySQL CLI:

- Create database: `readysetshows_ops`
- Run: `sql/001_schema.sql`

## 2) Configure
Edit `config.php` OR set env vars:

- RSS_DB_HOST
- RSS_DB_NAME
- RSS_DB_USER
- RSS_DB_PASS

Optional Google Sign‑In:
- RSS_GOOGLE_CLIENT_ID
- RSS_GOOGLE_CLIENT_SECRET
(or insert into `app_secrets`)

## 3) Local URL
If you place this folder under XAMPP `htdocs` as `readysetshows_ops_v1`,
you can browse:

- http://localhost/readysetshows_ops_v1/public/

## 4) First run
- Create account at /public/register.php
- Add an ICS calendar in /public/manage_calendars.php
- Click Import
- Use /public/check_availability.php to generate available dates
- Copy / export / share via /public/public_availability.php
