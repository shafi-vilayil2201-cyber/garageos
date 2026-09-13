# GarageOS — Project Documentation

*A workshop & garage management system built on RetailOS's architectural conventions: each customer runs their own independent instance on their own cloud server, accessible from any browser on any device, and every client gets a landing page that looks like nobody else's.*

> **Deployment model:** GarageOS is **not** installed on a shop's own PC and does not synchronize a local database to the cloud. Each customer provisions their own VPS (Linux + nginx + PHP-FPM + PostgreSQL) via `scripts/install-garageos.sh`, and every device — the shop's PC, a phone, a tablet — is simply a browser pointed at that customer's own HTTPS domain. There is one authoritative database per customer, no local/cloud synchronization, and no shop-PC-as-server. See [README.md](../README.md) for the current setup instructions, and §9/§14 below for the full architecture.

`v0.1 — draft` · `2026-09-10` · `Built on the RetailOS core` · Author: shafivilayil2201@gmail.com

---

## 1. Vision & positioning

Platforms like `autorox.ai` prove garages will pay for software — but they ship enterprise-grade complexity (multi-module configuration, long onboarding, dedicated training) at a small workshop that just wants to open a job card, order a part, and text the customer when the car's ready. GarageOS competes on a different axis: **time-to-value**, not feature count.

- **Fast to learn** — a service advisor should be creating job cards within 10 minutes of first login, no manual required.
- **Fast to deploy** — because it reuses RetailOS's proven core (auth, roles, org/branch model), a new client is mostly configuration, not code.
- **Reliable by construction** — a dedicated VPS per customer means one workshop's traffic, uptime, and data are never affected by another's; the browser-based client works identically from a shop PC, a phone, or a tablet, with nothing to install on any of them.
- **Looks bespoke, runs standard** — every client gets a custom-designed landing page (§10), but the software behind the login screen is identical from client to client.

> **Why this matters commercially:** The sales pitch and the engineering plan are the same document. "Custom landing page + proven core" is simultaneously the thing that closes the deal (it doesn't feel generic) and the thing that keeps margins healthy (the core never changes per client).

---

## 2. Design principles

1. **Reuse over rebuild** — auth, sessions, RBAC, and the organizations/branches model are ported from RetailOS, not rewritten.
2. **Customer-owned, cloud-hosted** — each customer's own VPS and PostgreSQL database is the single, authoritative source of truth for their data. No device is a local server, and no synchronization happens between devices or between a customer's infrastructure and anyone else's — every device is simply a browser client talking to that customer's own server.
3. **Config over code** — branding, service catalog, tax rules, and working hours are rows in a database, never a per-client code branch.
4. **One screen, one job** — every role gets a short, task-shaped screen list. No nested settings mazes, no Autorox-style module sprawl.

---

## 3. Domain glossary

| Term | Meaning |
|---|---|
| **Organization** | The workshop business — a single garage or a small chain. |
| **Branch** | One physical service center belonging to an organization. |
| **Customer** | A vehicle owner. |
| **Vehicle** | A customer's car/bike — make, model, year, plate, VIN. |
| **Job Card** | The central document: one service visit, from intake to delivery. |
| **Service** | A catalog item of billable labor (e.g. "Oil change") with a standard price and duration. |
| **Part** | An inventory item consumed on a job card. |
| **Technician** | Staff member who performs the work on a job card. |
| **Estimate** | A quote sent to the customer before work is approved. |
| **Invoice** | The final bill generated when a job card is closed. |
| **Payment** | Money received against an invoice. |
| **Appointment** | A booked future visit, walk-in or from the landing page. |
| **Reminder** | An automated nudge — service due, insurance/PUC expiry, follow-up. |
| **Inspection** | A checklist + photos capturing vehicle condition at intake and handover. |

---

## 4. Core business flows

**Service flow — the job card lifecycle**

```
Customer + Vehicle → Job Card (received)
→ Inspection (checklist + photos)
→ Estimate → Customer approval
→ Work in progress (technician assigned)
→ Parts consumed → Stock Movement (−)
→ Quality check → Invoice → Payment
→ Delivered / Closed
```

**Reminder flow**

```
Job Card closed → next-service date computed
→ Reminder scheduled → SMS/WhatsApp sent
→ (optional) Appointment booked
```

**Purchase flow — reused from RetailOS unchanged**

```
Supplier → Purchase → Purchase Items
→ Stock Movement (+) → Payment
```

---

## 5. Reused vs. new

**Reused as-is**
- organizations, branches
- users, roles, permissions, role_permissions, user_roles
- sessions (cookie-token auth)
- suppliers, purchases, purchase_items
- inventory_movements, stock_transfers
- payments (shape reused for invoices)

**New for GarageOS**
- vehicles, vehicle history
- job_cards, job_card_items, job_card_parts
- services, service_categories
- technician_profiles
- estimates, estimate_items, invoices, invoice_items
- appointments, reminders
- inspections, inspection_items, attachments
- notifications_log

> **Free reuse:** A "part" is a product. RetailOS's `products`, `categories`, and `inventory` tables can be reused with zero schema changes — a spark plug and a bag of rice are the same row shape. Same for `customers`: a vehicle owner is a customer with vehicles attached.

---

## 6. Database schema — new tables

All tables carry `organization_id`, `branch_id`, `created_at`/`updated_at` the same way every RetailOS table does — omitted below for brevity.

**vehicles** — customer's vehicles
| Column | Type | Notes |
|---|---|---|
| customer_id | bigint | FK → customers |
| registration_no | varchar(20) | unique per organization |
| make / model / variant | varchar | |
| year | int | |
| vin | varchar(32) | nullable |
| color | varchar(30) | |
| odometer_km | int | last known reading |
| fuel_type | varchar(20) | petrol / diesel / ev / hybrid |
| insurance_expiry, puc_expiry | date | drive reminders |

**job_cards** — the work order
| Column | Type | Notes |
|---|---|---|
| job_no | varchar(20) | human-readable, unique per branch |
| vehicle_id / customer_id | bigint | FK |
| status | varchar(20) | received / in_progress / quality_check / ready / delivered / on_hold / cancelled |
| odometer_in / odometer_out | int | |
| promised_at | timestamp | ETA shown to customer |
| advisor_id / primary_technician_id | bigint | FK → users |
| customer_complaint | text | reported by customer at intake |
| closed_at | timestamp | nullable |

**job_card_items** — labor lines
| Column | Type | Notes |
|---|---|---|
| job_card_id / service_id | bigint | FK |
| technician_id | bigint | who performed this line |
| price / discount | numeric(12,2) | copied from catalog, editable |
| status | varchar(20) | pending / done |

**job_card_parts** — parts consumed
| Column | Type | Notes |
|---|---|---|
| job_card_id / product_id | bigint | product_id → reused products table |
| quantity | numeric(10,2) | |
| unit_price | numeric(12,2) | snapshot at time of use |

**services / service_categories** — the labor catalog
| Column | Type | Notes |
|---|---|---|
| service_category_id | bigint | e.g. "Periodic maintenance", "Electrical" |
| name | varchar(150) | e.g. "Oil & filter change" |
| standard_price | numeric(12,2) | |
| estimated_minutes | int | drives scheduling & promised_at |
| applies_to_vehicle_type | varchar(20) | car / bike / commercial / all |

**estimates & invoices** — money documents
| Column | Type | Notes |
|---|---|---|
| job_card_id | bigint | FK |
| status | varchar(20) | draft/sent/approved/rejected (estimate) · unpaid/partial/paid (invoice) |
| subtotal, tax_amount, total | numeric(12,2) | |
| approved_via | varchar(20) | otp / signature / in_person — estimate only |
| invoice_no | varchar(20) | sequential, unique per branch — invoice only |

**appointments & reminders** — the calendar
| Column | Type | Notes |
|---|---|---|
| vehicle_id / customer_id | bigint | FK |
| scheduled_at | timestamp | appointment only |
| source | varchar(20) | landing_page / phone / walk_in |
| due_type | varchar(30) | service_due / insurance_expiry / puc_expiry / follow_up — reminder only |
| due_date, sent_at, channel | date/timestamp/varchar | sms / whatsapp / email — reminder only |

**inspections, inspection_items, attachments** — condition & proof
| Column | Type | Notes |
|---|---|---|
| job_card_id | bigint | FK |
| stage | varchar(10) | intake / handover |
| checklist_item, result | varchar | e.g. "Tyre tread" → ok / worn / replace |
| file_path, file_type | varchar | attachments: photo/video, stored in object storage (not yet implemented — see README "What's a placeholder") |

---

## 7. Roles & permissions

Same RBAC engine as RetailOS (`roles` → `role_permissions` → `permissions`, users can hold more than one role). Only the seeded roles and permission codes are garage-specific.

| Role | Job cards | Estimates / invoices | Parts & purchases | Reports & settings |
|---|---|---|---|---|
| **Owner** | Full | Full | Full | Full |
| **Manager** | Full | Full | Full | View + edit (no org/license) |
| **Service Advisor** | Create / edit | Create / send | View only | None |
| **Technician** | Own assigned only, status + notes + photos | None (no pricing visibility) | Request only | None |
| **Accountant** | View only | Full | View only | Financial reports |
| **Parts Manager** | View only | None | Full | Stock reports |

---

## 8. Modules & screens

| Module | Screens | Phase |
|---|---|---|
| Auth & RBAC | Login, session, role-gated navigation | MVP |
| Dashboard | Today's job cards, revenue, technician load, low-stock parts, due appointments | MVP |
| Customers & Vehicles | List/search, profile, vehicle history timeline | MVP |
| Job Cards | Kanban by status, intake form, inspection + photos, technician assignment | MVP |
| Estimates & Invoices | Build from job card, PDF/print, WhatsApp/email share, payment capture | MVP |
| Parts & Inventory | Stock levels, reorder point, purchases, stock movement log | Phase 2 |
| Appointments & Reminders | Booking calendar, automated service/expiry reminders | Phase 2 |
| Reports | Revenue, technician productivity, parts usage, retention | Phase 2 |
| Technician mobile view | Assigned jobs, status update, notes/photos — phone-first layout | Phase 3 |
| Customer portal | Live job status, approve estimate, view invoice, book next visit | Phase 3 |
| Settings | Branding, service catalog, tax, working hours, notification templates | MVP |

---

## 9. Deployment architecture

Each customer runs one independent GarageOS instance on infrastructure they own — never a shop PC, never a database shared with any other customer, and never synchronized with anything.

```
Customer's own VPS (Ubuntu/Debian)
├── nginx           HTTPS termination, reverse proxy — serves only public/
├── PHP-FPM          runs GarageOS
├── GarageOS          this application
└── PostgreSQL        this customer's own database — never exposed publicly

              ▲
              │ HTTPS
              │
   ┌──────────┴──────────┬──────────────┬──────────────┐
   │                      │              │              │
Shop's Windows PC       Mac          Owner's phone   Mechanic's tablet
(a browser client,    (a browser    (a browser       (a browser client)
 not the server)        client)      client)
```

Every device is a plain browser pointed at `https://<customer-domain>` — none of them run any part of the application locally, and none of them need to be powered on for the others to keep working. If the shop's PC is switched off entirely, the owner's phone still reaches the same data, because the VPS — not the PC — is what has to stay up.

An optional Cloudflare layer (DNS, proxying, HTTPS, DDoS protection) can sit in front of a customer's own domain if they choose it, but it is never required — the application works over plain HTTPS straight to the VPS, and does not depend on a Cloudflare Tunnel or any other single vendor. See `scripts/install-garageos.sh` and the README's "Production deployment" section for the actual, current install process.

---

## 10. Two-layer delivery model

This is the commercial heart of the plan. Every client gets a landing page nobody else has — that's what wins the deal against a generic-feeling competitor. Behind its "Book a service" / "Client login" button sits the exact same dashboard every other client runs — that's what keeps delivery fast and support sane.

```
┌───────────────────────┐   "Client Login"   ┌─────────────────────────────┐
│   LANDING PAGE          │ ──────────────►   │   CORE APPLICATION           │
│   unique per client     │                    │   identical for every client │
│                         │                    │                              │
│   Branding · services   │                    │   Dashboard · Job cards      │
│   Booking widget        │                    │   Inventory · Invoicing      │
│   Testimonials          │                    │   RBAC                       │
│                         │                    │                              │
│   CUSTOM                │                    │   REUSED                     │
│   — closes the deal     │                    │   — keeps delivery fast      │
└───────────────────────┘                    └─────────────────────────────┘
```

> **Rule of thumb:** If a client asks for something that only affects the landing page (colors, copy, photos, a booking form field), say yes freely — it's isolated. If they ask for something that changes a core screen's behavior, push it into `Settings` as a configuration option available to *every* client, or decline it. The moment one client gets a forked core screen, delivery speed for every future client drops.

---

## 11. One codebase, many clients

Per-client difference should live entirely in data and a small config file, never in a code path:

| Varies per client | Where it lives |
|---|---|
| Business name, logo, colors, contact details | `organizations` row + asset files |
| Service catalog & pricing | `services`/`service_categories` rows, seeded per org at onboarding |
| Tax rate, currency, working hours | `organizations` config columns |
| Notification templates (SMS/WhatsApp wording) | `notification_templates` table |
| Landing page design & copy | A separate, per-client static site/repo — not part of the core app |
| DB credentials, domain, org slug | One `.env` file per VPS install |

Onboarding a new client is: provision their own VPS, run `scripts/install-garageos.sh` (which itself runs the onboarding script that inserts an organization + branch + admin user + seeded service catalog against that VPS's own fresh database), and point their landing page's login button at their own domain. No branch of the core repo is ever created per client, and no other customer's server or database is ever touched.

---

## 12. ID strategy

`BIGSERIAL` primary keys are the right choice and need no further decision: every customer has exactly one database, which is never merged with, replicated to, or reconciled against another database. The ID-collision problem that a client-generated ID scheme (e.g. ULIDs) would solve only exists if records from two different databases might ever need to occupy the same table — under this architecture, that never happens. `organization_id`/`branch_id` columns are kept on every table for continuity with the RetailOS schema and because a single customer may eventually run multiple branches from one database, but they no longer serve any cross-customer or cross-database purpose.

---

## 13. API surface

Following the `public/api/*.php` convention already in this repo (see `public/api/job-cards/update-status.php`, `public/api/parts/search.php`, `public/api/vehicles/search.php`). Session-cookie auth, same as every other page — there is no separate sync worker or cross-server API key, since a single customer's GarageOS instance never talks to another server.

| Endpoint | Used by | Notes |
|---|---|---|
| `GET /api/job-cards` | Dashboard, technician view | filter by status, technician, date |
| `POST /api/job-cards` | Intake screen | creates job card + inspection shell |
| `PATCH /api/job-cards/{id}/status` | Kanban board, job card page | status transition, RBAC-checked — **implemented** as `public/api/job-cards/update-status.php` |
| `GET /api/vehicles/search` | Intake screen | by plate number or customer phone — **implemented** |
| `POST /api/estimates/{id}/send` | Advisor screen | dispatches WhatsApp/SMS/email — not yet built |
| `GET /api/portal/job-cards/{token}` | Customer portal | token-scoped, read-only, no login required — not yet built |

---

## 14. Production deployment

- **Target** — one Ubuntu/Debian VPS per customer: nginx (HTTPS termination, serves only `public/`), PHP-FPM, PostgreSQL, all owned by the customer's own cloud account. No shop-PC installer, no Windows/macOS packaging, no local service.
- **Install** — `scripts/install-garageos.sh`, run once on a fresh VPS. Installs and configures nginx/PHP-FPM/PostgreSQL/Certbot, hardens PHP for production (`display_errors=Off`, `expose_php=Off`), configures the firewall (UFW: only SSH/HTTP/HTTPS reachable, PostgreSQL never public), creates a dedicated non-superuser database role, runs migrations and onboarding, and requests an HTTPS certificate for the customer's own domain. **Implemented and locally verified; not yet run on a real VPS** — see the README for current status.
- **Releases** — `scripts/build-release.sh` produces a checksummed, verified tarball (application code only — no `.env`, no `.git`, no dev artifacts) from the GarageOS codebase. The installer lays out a `releases/<version>/` + `current` symlink structure so a future update only ever swaps a symlink rather than overwriting a live install.
- **Updates** — not yet built. The release/install foundation above is the prerequisite for it; the actual update-checker/admin-UI/backup-before-migrate/rollback flow is still on the roadmap (see README "What's a placeholder").
- **Backups** — not yet built. Every customer's data lives in one PostgreSQL database on their own VPS; a nightly `pg_dump` with off-box storage and a tested restore path is a near-term priority, not yet implemented.
- **Licensing** — out of scope. There is no license-key/cloud-validation mechanism, and none is currently planned — each customer simply owns and runs their own instance.

---

## 15. Reliability checklist

- [ ] Every screen the service advisor and technician touch must work with zero internet.
- [ ] Financial fields (prices, totals, payments) are validated server-side, never trusted from the client alone.
- [ ] Daily automated backup, tested restore path documented and rehearsed before the first client goes live — not yet built (see §14).
- [ ] Migrations are safe to run against a database with real data — already true by construction (`database/migrate.php` tracks applied migrations and only ever runs new ones), but worth re-confirming with a real upgrade scenario once updates are built.

---

## 16. Security checklist

- [x] Passwords hashed with `password_hash()` (bcrypt).
- [x] Session cookie: `httponly`, `samesite=Lax`, and `secure` follows the real request scheme (correctly detects HTTPS behind nginx via `X-Forwarded-Proto`) — real HTTPS via Let's Encrypt on the customer's own domain, not a self-signed LAN certificate.
- [x] RBAC enforced at the route/handler level, not just hidden in the UI — verified live: a Technician's direct API/page requests are rejected server-side (403), not just hidden buttons.
- [x] CSRF token required on every POST.
- [x] Login rate-limiting (5 failed attempts / 15 minutes per email).
- [ ] Firewall (UFW): only SSH/HTTP/HTTPS reachable; PostgreSQL never exposed publicly — written into `scripts/install-garageos.sh`, not yet verified on a real VPS.
- [x] PHP hardened for production: `display_errors=Off`, `expose_php=Off`, errors go to server logs, never to a visitor — installer writes this config; the underlying app-level behavior (generic error, real exception only in the server log) is verified locally against real PHP/Postgres.
- [ ] Audit log for financial edits (invoice changes, discounts, refunds) and role/permission changes — not yet built.
- [ ] Per-organization data isolation — enforced by `organization_id`/`branch_id` scoping today (multi-org-per-database model); the production model instead isolates customers at the database level (one database per customer), which is the stronger guarantee and needs verifying end-to-end on two real, separate VPS installs.

---

## 17. Client onboarding playbook

The whole point of the shared-core strategy is that this list gets shorter every quarter, not longer.

| Step | Owner | Typical time |
|---|---|---|
| Demo with seeded sample data (no client data needed) | Sales | 30 min |
| Collect branding, service list & pricing, logo, working hours | Sales | 1 form, 1 call |
| Provision the client's own VPS and run `scripts/install-garageos.sh` (creates the database, runs migrations, and onboards the org/branch/admin/catalog as part of the same script) | Ops | < 1 hour |
| Build the client's custom landing page from the brand kit | Design | 2–4 days |
| Staff training walkthrough (job card → invoice → payment) | Ops | 1 hour |
| **Go live** | — | **~1 week total** |

---

## 18. Build roadmap

| Phase | Scope | Estimate |
|---|---|---|
| 0 — Fork the core | Port auth, RBAC, org/branch, sessions from RetailOS | 2–3 days |
| 1 — MVP | Customers, vehicles, job cards, service catalog, invoices/payments, dashboard | 2–3 weeks |
| 2 — Operations | Parts/inventory (mostly reused), purchases, appointments, reminders | 1–2 weeks |
| 3 — Production deployment | VPS installer, release/build tooling, firewall + PHP hardening, real VPS validation | in progress — see README |
| 4 — Delivery tooling | Landing page template system, backup/restore, update mechanism | 1–2 weeks |
| 5 — Growth features | Customer portal, WhatsApp/SMS notifications, reports | ongoing |

---

## 19. Suggested repo structure

```
garageos/
├── app/                  domain classes (Auth, JobCards, Invoicing…), plus Support/ (Env, Version)
├── config/               database.php — reads its connection details from .env
├── database/
│   ├── migrations/       numbered raw SQL, same convention as RetailOS
│   ├── seeders/          local-dev seed scripts (demo data only)
│   └── onboard-client.php   creates the org/branch/admin/catalog inside one customer's own database
├── public/               document root — the only web-exposed directory
│   ├── dashboard.php
│   ├── job-cards.php
│   ├── health.php        production health check
│   └── api/
│       ├── job-cards/
│       ├── parts/
│       └── vehicles/
├── scripts/
│   ├── build-release.sh     packages a clean, checksummed release artifact
│   └── install-garageos.sh  the whole VPS install: nginx, PHP-FPM, PostgreSQL, firewall, HTTPS
├── docs/                 this document
└── clients/              one folder per client's landing page (separate, static)
    ├── acme-motors/
    └── city-garage/
```

---

## 20. Open decisions

- **Product name** — "GarageOS" used throughout this document as a placeholder, parallel to RetailOS.
- **Notification provider** — WhatsApp Business API vs. a cheaper SMS gateway for reminders/estimates.
- **VPS provider per customer** — the installer targets any Ubuntu/Debian VPS; Oracle Cloud's Always Free ARM tier is the current pilot/test target, but nothing in the application or installer is tied to one provider.
- **Shared core vs. separate repo** — whether GarageOS and RetailOS should literally share an `app/` layer (auth, RBAC) via a common package, or stay two repos that follow the same conventions independently.

---

*Draft documentation — reflects the RetailOS codebase as of commit `68284e5`. Numbers in the roadmap and onboarding sections are estimates, not commitments.*
