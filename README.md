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

## What's working right now

- Login / session auth, dashboard with live stats
- Customers and vehicles (create + list)
- Job cards: intake (search vehicle by plate or phone), add services and parts, status board, stock deduction on part use
- Invoice generation from a job card, payment recording
- Parts inventory (add part + opening stock, low-stock flag)
- Suppliers (basic list + create)

## What's a placeholder (Phase 2, see the roadmap in the docs)

- Purchases (schema exists, no UI yet — restock via the Parts page for now)
- Reports
- Editable settings / branding
- Appointments, reminders, cloud sync — not yet started

## Conventions to follow when extending this

- Every table is scoped by `organization_id` (and `branch_id` where it's branch-specific) — never trust a client-supplied id without checking it belongs to the logged-in user's organization.
- Migrations are numbered, plain SQL, and never edited after being committed — add a new migration instead.
- Pages live directly under `public/`, one file per screen, handling their own GET (render) and POST (write) — no router, no templating engine. Keep it that way; it's a deliberate choice for developer speed, not an oversight.
- Shared UI (`app/View/sidebar.php`, `app/View/topbar.php`) is the only shared markup — resist pulling more into partials until a third page needs it.
