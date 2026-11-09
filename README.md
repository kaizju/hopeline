# HopeLine

HopeLine is an emergency incident reporting and response coordination system. Managers can log incidents, assign response vehicles, and monitor active work on a live map. Responders can view assignments, share their location, report delays, and submit on-site updates. Administrators manage users, teams, response units, settings, reports, and audit records.

## Features

### Incident intake and dispatch

- Record phone and walk-in emergency reports with caller details, incident type, severity, notes, and a map location.
- Assign response units and crews to incidents, and track each dispatch through assigned, en route, on-site, returning, and completion states.
- Track unit availability and show active incidents and responding units on map views.
- Calculate and display estimated travel times; dispatch records can include site photos, GPS coordinates, and patient/outcome details.

### Field response

- Give responders a dashboard, current assignment view, and response history.
- Collect responder GPS updates, including accuracy, heading, speed, and last update time, when the user grants location access.
- Let responders report and clear delays with a reason and notes; managers can review active delay alerts and their effect on response times.
- Save on-site updates and closeout details, including photos and location information.

### Management and administration

- Provide separate dashboards and pages for administrators, managers, and responders.
- Manage user accounts, teams, response units, and system settings.
- Review incident history, active incidents, alerts, and delay reports.
- View analytics and export case or analytics data as CSV.
- Keep activity, sign-in session, and security logs for review.

### Account and access controls

- Check verified account status at login and route users to the area for their role.
- Apply login attempt throttling and regenerate the PHP session ID after successful authentication.
- Track session activity and sign-in events.

## Technologies

| Area | Technology |
| --- | --- |
| Server-side application | PHP |
| Database access | PDO with the MySQL driver |
| Database | MariaDB 10.4+ or MySQL 8+, using InnoDB and `utf8mb4` |
| Browser interface | HTML, CSS, and JavaScript |
| Maps | Leaflet 1.9.4 and Leaflet Routing Machine (bundled in `assets/vendor/`) |
| Map data and geocoding | OpenStreetMap tiles/attribution and Nominatim reverse geocoding |
| Route and travel-time services | OSRM routing/table service endpoints |
| Optional desktop shell | Electron 23.1.3 with Node.js/npm; PHP still runs as a separate local server process |

The web application does not require npm for its normal PHP/Apache setup. `package.json` is used for the optional Electron shell and its JavaScript dependency. The project uses browser geolocation for field location reporting. External map, geocoding, and routing services need network access and have their own usage policies and availability limits.

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
4. Review the database settings in `config/config.php`. Local XAMPP defaults are database `hopeline`, user `root`, and an empty password. Override these with environment variables or the ignored `config/secrets.php` file if your local database differs. Set the base URL to match how Apache serves the project.
5. Open `http://localhost/hopeline/` in your browser. The current `BASE_URL` setting is `https://localhost/hopeline`; use the matching HTTP or HTTPS URL for your Apache configuration.

For an existing database, do not re-import the full schema. Apply the relevant SQL file from `db/migrations/` instead.

For deployments, supply `HOPELINE_DB_HOST`, `HOPELINE_DB_NAME`, `HOPELINE_DB_USER`, `HOPELINE_DB_PASSWORD`, and `HOPELINE_BASE_URL` as environment variables, or set the matching `$host`, `$dbname`, `$username`, `$password`, and `$baseUrl` variables in the ignored `config/secrets.php` file. Environment variables take precedence. Keep production secrets out of version control and serve the application over HTTPS.

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Administrator | `admin@hopeline.local` | `Admin@123` |
| Manager | `manager@hopeline.local` | `Manager@123` |
| Responder | `user@hopeline.local` | `Responder@123` |
| Responder | `user2@hopeline.local` | `Responder@123` |

These accounts are for local demonstration only. Change their passwords and configure secure database credentials before deploying the system for real use. Do not commit secrets or production data.

Passwords for newly created accounts must contain at least 12 characters and are stored using PHP's `password_hash()` API. Existing demo account passwords are pre-hashed in the SQL seed data; replace them before deployment.

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
