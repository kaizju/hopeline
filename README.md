# HopeLine

HopeLine is an emergency incident reporting and response coordination system. Managers can log incidents, assign response vehicles, and monitor active work on a live map. Responders can view assignments, share their location, report delays, and submit on-site updates. Administrators manage users, teams, response units, settings, reports, and audit records.

## Main capabilities

- Role-based areas for administrators, managers, and responders
- Incident intake for phone and walk-in reports, including map location and severity
- Dispatch of one or more response units and tracking of dispatch status
- Live map views for incidents and GPS-enabled response units
- Responder assignment details, delay reporting, site photos, and closeout information
- Incident history, analytics, exports, alerts, and activity/security logs
- Login throttling, verified-account checks, and session activity tracking

## Requirements

- PHP with PDO MySQL enabled (the project is set up for XAMPP)
- MariaDB 10.4+ or MySQL 8+
- A web server such as Apache, or PHP's built-in development server
- A modern browser; location reporting requires the browser's geolocation permission

The repository also includes an Electron launcher, but its PHP and document-root paths are currently hard-coded for a particular XAMPP layout. Use the web setup below unless those paths have been adapted for your machine.

## Setup with XAMPP

1. Place this repository in `C:\xampp\htdocs\hopeline` (or another directory served by Apache).
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Import the schema and demo data into MySQL. For example, from a terminal with the MySQL client on `PATH`:

   ```sh
   mysql -u root < db/schema.sql
   ```

   The schema creates and selects the `hopeline` database. It contains demo accounts listed below.
4. Review `config/config.php`. Its defaults use database `hopeline`, user `root`, and an empty password. Update the credentials for your local database. If the site is served at a different URL, update `BASE_URL` there as well.
5. Open `http://localhost/hopeline/` in your browser. The current `BASE_URL` setting is `https://localhost/hopeline`; use the matching HTTP or HTTPS URL for your Apache configuration.

For an existing database, do not re-import the full schema. Apply the relevant SQL file from `db/migrations/` instead.

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Administrator | `admin@hopeline.local` | `Admin@123` |
| Manager | `manager@hopeline.local` | `Manager@123` |
| Responder | `user@hopeline.local` | `Responder@123` |
| Responder | `user2@hopeline.local` | `Responder@123` |

These accounts are for local demonstration only. Change their passwords and configure secure database credentials before deploying the system for real use. Do not commit secrets or production data.

## Project layout

```text
app/                 Role-specific admin, manager, and responder pages
api/                 JSON endpoints for location and dispatch updates
assets/              Styles, scripts, layouts, and bundled map libraries
config/              Database connection and shared application helpers
db/schema.sql        Database schema and demo data
db/migrations/       Incremental database changes
includes/            Shared PHP components, including activity logging
index.php            Login page and role-based entry point
```

## Notes

- Leaflet is bundled locally. Map tiles and geocoding use external OpenStreetMap services, so those functions need network access and must follow the providers' usage policies.
- Browser geolocation generally requires HTTPS or a trusted localhost origin, plus user permission.
- `npm start` launches the optional Electron shell. The PHP executable and document root are hard-coded in `main.js` and may need adjustment. `npm test` is only a placeholder and does not run an application test suite.
- The SQL schema documents the expected MySQL/MariaDB versions and warns against re-importing into a database that already contains data.
