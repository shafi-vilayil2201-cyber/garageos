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
- Job cards: intake (search vehicle by plate or phone, or add a brand-new customer + vehicle inline in the same form — no detour to another screen), add services and parts, status board, stock deduction on part use
- **"+ New Job Card" / "+ New Appointment" open as a quick-create modal** from the Dashboard, Job Cards, and Appointments pages — no navigating to an empty page first. Both the search box and "Add new customer & vehicle" are visible from the first render, not one hidden behind a failed search of the other. The dedicated `/job-card-new.php` and `/appointment-new.php` pages still exist standalone (direct links, no-JS fallback) and share the exact same form markup and backend, via `vehicle_intake_form()` in `app/View/VehicleIntake.php` and `initVehicleIntake(prefix)` in `public/js/vehicle-intake.js`.
- Invoice generation from a job card, payment recording
- Parts inventory (add part + opening stock, low-stock flag)
- Suppliers, and purchases (multi-line, restocks inventory and logs the movement)
- Reports: 7-day revenue, technician productivity, top parts used, low stock
- Appointments: book by searching a vehicle (or, same as job card intake, add a new customer + vehicle inline if they're not in the system yet), convert straight into a job card on arrival. A repeat phone number reuses the existing customer instead of creating a duplicate — verified against a real second-vehicle booking.
- Reminders: auto-generated from vehicle insurance/PUC expiry dates and from job card delivery (next service due in 90 days) — mark contacted or dismiss; sending SMS/WhatsApp is not wired up yet, this is the in-app due-list only

## Design system

A small, deliberate set of conventions in `public/css/app.css` and `app/View/Icons.php` — apply these to any new screen rather than inventing new patterns:

- **Icons are real and contextual**, not decorative unicode glyphs — `icon('name', size)` from `app/View/Icons.php` returns inline SVG (feather-style, `currentColor` stroke) themed for the sidebar/badges/buttons automatically. Every nav item, card header, empty state, and button that benefits from one uses the icon that actually matches its meaning (a car for vehicles, a bell for reminders, a wallet for money, a truck for purchases).
- **Buttons have three distinct roles, not one style with color variants**: `.button` (primary action), `.button.secondary` (neutral/view), `.button.danger` (destructive — Cancel, Dismiss). Never give a destructive action the same visual weight as a neutral one. `.button.sm` is the compact variant for inline table-row actions.
- **A visible action is always a usable action** — buttons and links are gated by `user_can($user, 'permission.code')` in the markup, matching the `require_permission()` check the same page enforces server-side. A Technician should never see "New Job Card" only to hit a 403; the button simply isn't there. Empty sidebar sections (an "Operations" heading with nothing under it) are suppressed the same way.
- **Layout utilities over inline styles**: `.stack` / `.stack-sm` (vertical flex + gap), `.actions` / `.row-actions` (horizontal button groups), `.card-header-title` + `.icon-badge` (icon next to a card title), `.selected-summary` (the "you picked this vehicle/part" confirmation block), `.link-action` (a small icon+text link). Reach for these before writing a new `style="display:flex..."` attribute.
- **Numbers that should line up get the `.num` class** (`font-variant-numeric: tabular-nums`) — every price, quantity, and count column.
- **Keyboard focus is always visible** (`:focus-visible` outline, defined once, globally) — never remove it without replacing it.
- **Quick-create modals over blank pages**: `.modal-backdrop` / `.modal` (see `public/js/modal.js` — `openModal(id)` / `closeModal(id)`, Escape and backdrop-click both close). The form inside still POSTs normally to a real page (no AJAX, no SPA) — the modal is presentation only, so the backend stays the same simple PHP pattern. When a form can appear more than once on a page (e.g. a job-card modal and an appointment modal both on the Dashboard), every element id must be namespaced by a `$prefix` — see `vehicle_intake_form()` for the pattern. Every list page (Customers, Vehicles, Parts, Suppliers, Users, Job Cards, Appointments) follows this same shape: full-width list, a single "+ Add X" button in the page header, the create form lives in a modal, not a permanent side panel. If a submission fails validation, render the modal with `class="modal-backdrop open"` (`$error ? ' open' : ''`) so the error isn't silently hidden behind a closed dialog — verified live (a name-only submission re-opens the Add Customer modal with "Name and phone are required." shown, not a fresh empty form).

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
