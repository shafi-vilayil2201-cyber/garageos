# GarageOS

A workshop & garage management system: customers, vehicles, job cards, a service catalog, parts inventory, and invoicing — built to run locally on the shop's own PC. Plain PHP + PostgreSQL, no framework, following the same architectural conventions as its sister project RetailOS (numbered SQL migrations, cookie-session auth, organization/branch RBAC).

Full project rationale, roadmap, and schema reference: [docs/project-documentation.md](docs/project-documentation.md).

## Setup

1. Create the database and user (PostgreSQL):

   ```sql
   CREATE DATABASE garageos;
   CREATE USER garageos_user WITH PASSWORD 'garageos_dev_2026';
   GRANT ALL PRIVILEGES ON DATABASE garageos TO garageos_user;
   ```

   Update `config/database.php` if you use different credentials.

2. Run migrations:

   ```
   php database/migrate.php
   ```

3. Seed a demo organization, branch, and admin user (interactive — asks for admin email/password):

   ```
   php database/seeders/001_create_demo_data.php
   ```

4. Seed the service catalog and demo workshop data (parts, a walk-in customer, a demo vehicle):

   ```
   php database/seeders/002_seed_service_catalog.php
   php database/seeders/003_seed_demo_workshop_data.php
   ```

5. Serve the app:

   ```
   php -S localhost:8000 -t public
   ```

   Open `http://localhost:8000` and sign in with the admin email/password from step 3.

## Onboarding a new client

One command creates a fully working workshop — organization, branch, admin login, the standard role set (Owner, Manager, Service Advisor, Technician, Accountant, Parts Manager) with permissions matching [docs/project-documentation.md](docs/project-documentation.md) §7, and a starter service catalog:

```
php database/onboard-client.php
```

It asks for the workshop name, a short code, and the admin's details, then prints a summary. This is the whole "close the deal" setup step — no manual database work, no code changes per client. Verified against a second live organization (Highway Motors) with full data isolation from the demo workshop.

## What's working right now

- Login / session auth, dashboard with live stats, today's appointments, and reminders due
- **Dynamic branding** — the sidebar and login page show the client's own organization name (falls back to "GarageOS" only when a database serves more than one org, as in this shared dev setup); "Powered by GarageOS" is the vendor attribution
- **Role-based access control, actually enforced** — the six roles seeded at onboarding (Owner, Manager, Service Advisor, Technician, Accountant, Parts Manager) gate both the nav (hidden if you can't use it) and every page/action server-side (`require_permission()`), not just the UI. Verified live: a Technician account sees only Dashboard/Job Cards/Appointments/Reminders, and hitting `/parts.php` directly returns a 403, not a page.
- **Users page** (`/users.php`) — create staff accounts and assign a role; the front door to RBAC
- **CSRF protection** on every form (verified: a POST without a valid token is rejected with 419)
- **Login rate-limiting** — 5 failed attempts per email locks that email out for 15 minutes, even against the correct password (verified live)
- **HTTPS-aware secure cookies** — the session cookie's `secure` flag follows the actual request scheme instead of being hardcoded off
- **Indexed for scale** — 22 indexes on the foreign keys and status filters every list/dashboard query actually uses
- Customers and vehicles (create + list)
- Job cards: intake (search vehicle by plate or phone), add services and parts, status board, stock deduction on part use
- Invoice generation from a job card, payment recording
- Parts inventory (add part + opening stock, low-stock flag)
- Suppliers, and purchases (multi-line, restocks inventory and logs the movement)
- Reports: 7-day revenue, technician productivity, top parts used, low stock
- Appointments: book by searching a vehicle, convert straight into a job card on arrival
- Reminders: auto-generated from vehicle insurance/PUC expiry dates and from job card delivery (next service due in 90 days) — mark contacted or dismiss; sending SMS/WhatsApp is not wired up yet, this is the in-app due-list only

## What's a placeholder (see the roadmap in the docs)

- Editable settings / branding beyond the name shown today
- An audit log for financial edits and permission changes — deferred to keep this hardening pass shippable; the RBAC/CSRF/rate-limiting work above was prioritized first
- Full pagination on list pages — currently unbounded queries, fine at demo scale, worth adding before a client has years of history
- Actually sending reminder notifications (SMS/WhatsApp/email) — needs a provider decision first
- Cloud sync, mobile/remote access — not yet started; blocked on deciding a cloud host and revisiting the `BIGSERIAL` primary key strategy (see docs §12)

## Conventions to follow when extending this

- Every table is scoped by `organization_id` (and `branch_id` where it's branch-specific) — never trust a client-supplied id without checking it belongs to the logged-in user's organization.
- Migrations are numbered, plain SQL, and never edited after being committed — add a new migration instead.
- Pages live directly under `public/`, one file per screen, handling their own GET (render) and POST (write) — no router, no templating engine. Keep it that way; it's a deliberate choice for developer speed, not an oversight.
- Shared UI (`app/View/sidebar.php`, `app/View/topbar.php`) is the only shared markup — resist pulling more into partials until a third page needs it.
