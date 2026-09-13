# GarageOS

A workshop & garage management system: customers, vehicles, job cards, a service catalog, parts inventory, and invoicing. Plain PHP + PostgreSQL, no framework, following the same architectural conventions as its sister project RetailOS (numbered SQL migrations, cookie-session auth, organization/branch RBAC).

Full project rationale, roadmap, and schema reference: [docs/project-documentation.md](docs/project-documentation.md).

## Architecture at a glance

Each customer runs their own independent GarageOS instance, on infrastructure they own — not a shop PC, not a shared multi-tenant server:

```
Customer's own VPS (Ubuntu/Debian)
├── nginx            (HTTPS, reverse proxy to PHP-FPM)
├── PHP-FPM           (runs GarageOS)
├── GarageOS           (this application — public/ is the only web-exposed directory)
└── PostgreSQL         (this customer's own database, never exposed publicly)
```

Every device the customer uses — the shop's Windows PC, a Mac, the owner's phone, a mechanic's tablet — is just a browser pointed at `https://<customer-domain>`. None of them run any part of the application locally, and none of them need to be powered on for the others to work: the VPS is the one thing that has to stay up. There is exactly one database per customer, no synchronization between devices, and no dependency on any one customer's device being online.

Cloudflare (DNS/proxy/HTTPS) is an optional layer a customer can put in front of their own domain if they want it — it is never required, and GarageOS never depends on a Cloudflare Tunnel or any other single vendor.

## Local development

This is for developing GarageOS itself, not how a customer runs it in production — see **Production deployment** below for that.

1. Create the database and user (PostgreSQL):

   ```sql
   CREATE DATABASE garageos;
   CREATE USER garageos_user WITH PASSWORD 'garageos_dev_2026';
   GRANT ALL PRIVILEGES ON DATABASE garageos TO garageos_user;
   ```

   These match `config/database.php`'s built-in defaults, used automatically when no `.env` file is present. To use different credentials instead, copy `.env.example` to `.env` and fill it in — `.env` is never committed.

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

   This built-in PHP server is a development convenience only — never use it in production (see below).

## Production deployment

A production install is one dedicated Ubuntu/Debian VPS per customer, running nginx + PHP-FPM + PostgreSQL — never the PHP development server, never a shop PC.

**Building a release** (done once per version, by whoever maintains GarageOS):

```
./scripts/build-release.sh
```

Produces `dist/GarageOS-<version>.tar.gz` and a matching `.sha256` checksum file — application code only. It refuses to produce a package that contains `.env`, `.git`, or any other local/development artifact, and verifies the result by re-extracting it and confirming every file a real install needs is actually present before calling it done.

**Installing on a customer's VPS**: copy that tarball to a fresh Ubuntu/Debian server, extract it, and run:

```
sudo ./scripts/install-garageos.sh
```

This one script:

- Installs nginx, PHP-FPM, PostgreSQL, and Certbot
- Hardens PHP for production (`display_errors=Off`, `expose_php=Off`, errors go to server logs, never to a visitor)
- Configures the firewall (UFW) to allow only SSH, HTTP, and HTTPS — PostgreSQL is never exposed publicly
- Creates a dedicated, least-privilege PostgreSQL role and database for this customer (never the Postgres superuser)
- Lays out a versioned release directory (`releases/<version>/`, with `current` symlinked to the active one, and `.env` living in `shared/` so it survives every future release) so a future update only ever swaps a symlink
- Runs migrations, then `database/onboard-client.php` to create the organization, branch, admin user, roles, and starter service catalog
- Configures nginx to serve only `public/` — `.env`, `app/`, `database/`, and `scripts/` are never web-reachable
- Requests an HTTPS certificate via Let's Encrypt for the domain you give it
- Verifies the install against `/health.php` before declaring success, and never prints the generated database password to the terminal

Run `sudo ./scripts/install-garageos.sh` again on a different server and you get a second, completely independent installation — its own database, its own credentials, its own domain. Nothing about one customer's install ever talks to another's.

## Customer access through the browser

Once installed, a customer's whole experience is: open a browser, go to `https://<their-domain>`, log in. That's true from a Windows PC, a Mac, an Android phone, or an iPhone — GarageOS is a normal responsive web app, not something installed per device. Nobody needs to install PHP, PostgreSQL, or any GarageOS files on their own computer or phone to use it; the shop's own PC is a client like any other, and if it's switched off, GarageOS keeps running on the VPS exactly as it did before.

## Onboarding a new client

`database/onboard-client.php` creates a fully working workshop inside a customer's own, already-migrated database — organization, branch, admin login, the standard role set (Owner, Manager, Service Advisor, Technician, Accountant, Parts Manager) with permissions matching [docs/project-documentation.md](docs/project-documentation.md) §7, and a starter service catalog. It's run automatically as one step of `scripts/install-garageos.sh`, so onboarding a new client is simply running the installer — no manual database work, no code changes per client.

It's safe to run directly too (`php database/onboard-client.php`) but only against a fresh database — it checks for an existing organization first and refuses to run twice against the same database, since each customer's database is meant to hold exactly one organization, never several.

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
- **The job card detail page follows the same modal convention as every list page.** "+ Add Service" and "+ Add Part" are prominent buttons in their card headers, not a form permanently wedged under the table — clicking either opens a modal (the part modal keeps the exact same search-then-select flow, unchanged). A failed submission (an unselected service, insufficient part stock) reopens the correct modal showing the error, never a silent no-op or the wrong dialog. "Generate invoice" is disabled with a tooltip until at least one line exists. A horizontal stage tracker (Received → In Progress → Quality Check → Ready → Delivered) sits above the status dropdown, coloured with the same stage colours as the kanban board — On Hold renders the In Progress segment in red, Cancelled skips the tracker entirely. The add buttons, status card, and invoice button are all gated by `job_cards.manage`/`invoices.manage`, so a Technician sees a read-only page instead of controls that would 403 on submit.
- **The status board is drag-and-drop, and forward-only everywhere, not just in the UI.** Drag a card from Received into any later column (including skipping straight to Delivered) and it updates instantly via `/api/job-cards/update-status.php`; drag it backward and the target column visibly disables itself the moment you pick the card up, and even a direct API call is rejected with a 422 — the rule (`job_card_can_transition()` in `app/Domain/JobCardStatus.php`) is enforced once and shared by the kanban board *and* the status dropdown on the job card page (which only ever lists the current status plus what it can legally become next). Delivered and Cancelled are terminal: a delivered card isn't even draggable. Every stage has its own colour carried on both the column edge and the card itself — an On Hold job stays visually inside the In Progress column but is tinted and badged distinctly so a paused job never reads as active work. Verified live: a card dragged from Received straight to Ready (skipping two stages), the reverse drag rejected by both the client guard and the server, and a Technician account's direct API call rejected with 403 before the transition rule is even checked.
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
- **Paginated lists, 20 rows per page**: `paginate_page()` / `paginate_offset()` / `render_pagination()` in `app/View/Pagination.php` — each list page runs its own `COUNT(*)` matching its `WHERE` clause, hands the total to `paginate_page()` to get a clamped `?page=N` (a page past the last one clamps to the last page instead of showing an empty table with live-looking Prev/Next), then binds `LIMIT`/`OFFSET` on the real query. `render_pagination()` renders the Prev/Next + "Page X of Y" control and returns an empty string when everything fits on one page, so small lists show no pagination bar at all. Applied to Customers, Vehicles, Parts, Suppliers, Purchases, Appointments, and Users — verified live against 27 seeded customers (correct 20/7 split across two pages, `?page=999` and `?page=0` both clamp correctly) and against every other list page with real data below the threshold (no pagination bar, no errors). The Job Cards board stays unpaginated — it's a kanban, not a flat list, and paginating five independently-scrolling columns needs a different UX than Prev/Next.

## What's a placeholder (see the roadmap in the docs)

- Editable settings / branding beyond the name shown today
- An audit log for financial edits and permission changes — deferred to keep this hardening pass shippable; the RBAC/CSRF/rate-limiting work above was prioritized first
- Actually sending reminder notifications (SMS/WhatsApp/email) — needs a provider decision first
- An in-app update checker/updater (check for a new release, back up, migrate, roll back on failure) — the release/install tooling (`scripts/build-release.sh`, `scripts/install-garageos.sh`) exists; the update flow on top of it doesn't yet
- Automated backups and a tested restore path on the production VPS — not yet built
- A Progressive Web App / "Add to Home Screen" experience, and any offline capability — intentionally deferred; the current architecture is online-first by design, not a step toward local/cloud database sync

## Conventions to follow when extending this

- Every table is scoped by `organization_id` (and `branch_id` where it's branch-specific) — never trust a client-supplied id without checking it belongs to the logged-in user's organization.
- Migrations are numbered, plain SQL, and never edited after being committed — add a new migration instead.
- Pages live directly under `public/`, one file per screen, handling their own GET (render) and POST (write) — no router, no templating engine. Keep it that way; it's a deliberate choice for developer speed, not an oversight.
- Shared UI (`app/View/sidebar.php`, `app/View/topbar.php`) is the only shared markup — resist pulling more into partials until a third page needs it.
- Configuration comes from environment variables (`app/Support/Env.php`'s `env()`), never hard-coded — `config/database.php` is the pattern to follow for any future config value. `.env` never gets committed; `.env.example` is the safe, always-up-to-date template.
- `app/Support/Version.php`'s `GARAGEOS_VERSION` is the one place the installed version lives — never inferred from git, since a deployed release has no `.git` directory.
