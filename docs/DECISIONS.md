# BusinessOS — Architectural Decisions

## Decision: ID Strategy — BIGINT Auto-Increment

**Decision:** Use BIGINT auto-increment for all primary keys.

**Reason:**
- Smaller index size than UUID (8 bytes vs 16 bytes)
- Better query performance (integer comparison vs string)
- Simpler for developers to work with
- Better for URL paths (/customers/42 vs /customers/01H5... )
- No import/export conflicts between environments
- Laravel's default and most common approach
- Better for shared hosting (less storage/memory overhead)

**Alternatives Considered:**
- UUID v4: No ordering, 36-char strings, 16-byte indexes, import conflicts
- ULID: Better than UUID but adds complexity, less developer familiarity
- BIGINT + public UUID column: Extra column, adds complexity without clear MVP benefit

**Consequences:**
- Sequential IDs visible in URLs (acceptable for admin panel)
- Auto-increment can be guessed (mitigated by authentication + authorization, not security through obscurity)
- If public-facing IDs needed later, add a `uuid` column or generate public IDs on demand

---

## Decision: Tenancy — Shared Database with business_id

**Decision:** All businesses share one database, distinguished by `business_id` column on every business-owned table.

**Reason:**
- Standard Laravel multi-tenancy approach
- Simple to implement and maintain
- No complex database-per-tenant routing
- Shared hosting compatible (one database)
- Easy to migrate to SaaS later
- Easier backups and maintenance
- Laravel's ecosystem best supports this approach

**Alternatives Considered:**
- Database-per-tenant: Overkill for CodeCanyon product, expensive shared hosting, complex migration
- Separate schema per tenant: Not well supported on shared hosting
- No tenancy (single-business only): Doesn't support future SaaS expansion

**Consequences:**
- All queries must include business_id scope
- Must use global scopes on all business-owned models
- Must test cross-business isolation thoroughly
- Slightly larger tables (acceptable)
- Need for composite indexes on (business_id, ...)

---

## Decision: Money Precision — DECIMAL(16,4)

**Decision:** Use DECIMAL(16,4) for all monetary values. DECIMAL(16,8) for exchange rates. DECIMAL(8,4) for tax rates. DECIMAL(5,2) for percentages.

**Reason:**
- 4 decimal places sufficient for all currencies (most use 2)
- Extra decimals prevent rounding accumulation in intermediate calculations
- DECIMAL is exact — no floating point errors
- 16 integer digits allows values up to 999,999,999,999.9999
- MySQL DECIMAL is stored as binary — efficient storage and arithmetic

**Alternatives Considered:**
- DECIMAL(10,2): Too small for large totals in high-value currencies
- DECIMAL(16,2): Loses precision in intermediate calculations (tax * quantity * price)
- INTEGER (cents): Error-prone for CodeCanyon buyers, different per-currency conventions
- FLOAT/DOUBLE: Absolutely rejected — floating point is unacceptable for financial data

**Consequences:**
- All models use DECIMAL(16,4) casts
- All calculations done via PHP bcmath or BigDecimal
- Display formatting handled by FormattingService
- Rounding strategy: round final amounts only, not intermediate calculations
- Tax rounding: round tax per line item to 4 decimal places

---

## Decision: Module System — PHP Config Array Registry

**Decision:** Modules defined in `config/modules.php` as a PHP configuration array. Module state stored in `business_modules` database table.

**Reason:**
- PHP config is familiar to all Laravel developers
- Easy to read, modify, and extend
- No database migration needed to add new modules
- Config can be published and overridden
- Module metadata easily inspectable
- No complex module loading system needed

**Alternatives Considered:**
- Database-only module registry: Harder to version control, harder to extend
- Composer package per module: Overkill, complex dependency management for CodeCanyon
- Service provider-based: Possible but adds unnecessary complexity
- Package-based module system (like Snipe-IT): Heavyweight for this scope

**Consequences:**
- New modules require code change (config file update)
- Module activation/deactivation is database-driven (per business)
- Module metadata (name, icon, permissions) lives in config
- Navigation rendering reads config + database state
- Acceptable trade-off for simplicity and maintainability

---

## Decision: Stock Movement Architecture — Centralized Movements Table

**Decision:** All stock changes recorded as rows in `stock_movements` table. Product quantities never directly updated by business logic.

**Reason:**
- Complete audit trail for every stock change
- Historical stock levels computable at any point in time
- Multiple movement types supported naturally
- Cost tracking per movement
- Source document linking for traceability
- Standard approach for inventory management systems

**Alternatives Considered:**
- Direct quantity update on product: No audit trail, no history, error-prone
- Separate table per movement type: Over-normalized, complex queries
- Event-sourced stock: Over-engineered for SMB product
- Periodic stock snapshots: Loses detail, hard to debug discrepancies

**Consequences:**
- StockService is the ONLY way to change stock
- Product quantity is a derived/cached value (computed from movements)
- Current stock queries use `SUM(quantity)` or cached equivalent
- All stock operations must go through the service
- Movement records are IMMUTABLE (never edited or deleted)

---

## Decision: Stock Valuation — Weighted Average Costing (V1)

**Decision:** Use Weighted Average Costing (WAC) as the sole valuation method for V1.

**Reason:**
- Simplest costing method to implement correctly
- Easy to understand for CodeCanyon buyers
- No batch tracking required (simpler database)
- Adequate accuracy for SMB use cases
- Lower support burden than FIFO
- Can be upgraded to FIFO later without data loss (new columns/tables)

**Alternatives Considered:**
- FIFO: More accurate but requires batch-level cost tracking, significantly more complex
- LIFO: Not permitted under most accounting standards
- Specific identification: Not practical for most products
- Latest purchase price: Simple but inaccurate for cost reporting

**Consequences:**
- WAC recalculated on each purchase
- Current WAC stored on product's purchase_cost
- COGS uses current WAC at time of sale
- Price fluctuations smoothed over time
- Acceptable approximation for most SMB scenarios
- FIFO can be added post-V1 (aligned with the Accounting phase); WAC design keeps batch-compatible purchase data so no rework is required

---

## Decision: Payment Architecture — Polymorphic with Allocation Table

**Decision:** Payments use polymorphic relationship for multi-context support. A `payment_allocations` table maps payments to invoices for partial payment tracking.

**Reason:**
- Single payment record can apply to multiple invoices (split payment)
- Single payment can relate to different entities (invoice, purchase, credit)
- Allocation table cleanly handles partial payments
- Payment number is globally unique within business
- Reversal is clean (mark payment as reversed, don't delete)

**Alternatives Considered:**
- Direct foreign key to invoice: Can't handle split payments or multiple contexts
- Separate payment tables per entity: Duplicated logic, hard to maintain
- Payment amounts only on invoices (no separate payment entity): Loses payment detail, audit trail

**Consequences:**
- PaymentService must update invoice amount_paid/amount_due on allocation
- Invoice status computed from amount_paid vs total
- Reversal must un-allocate and update affected invoices
- PaymentAllocations are immutable (reversed by new negative allocations)

---

## Decision: Invoice Numbering — Centralized Service with Database Sequences

**Decision:** Document numbers generated by a centralized NumberingService using database-stored sequences per document type per year.

**Reason:**
- Concurrency-safe (database-level sequence)
- Configurable per business (prefix, length, format)
- Year-based reset (INV-2026-00001, INV-2027-00001)
- Handles gaps gracefully (cancelled numbers not reused)
- Central service prevents duplication across modules

**Implementation:**
```
Format: {prefix}-{year}-{padded_sequence}
Example: INV-2026-00001
```

**Alternatives Considered:**
- UUID-based numbers: Not human-friendly, not sequential
- Date-based with random: Not sequential, unpredictable
- Auto-increment only: No prefix, no year reset
- Redis sequences: Requires Redis (not shared-hosting friendly)

**Consequences:**
- Sequence gaps possible (acceptable, standard practice)
- Concurrent requests safe (SELECT FOR UPDATE or atomic increment)
- Format configurable per business
- Each document type has its own sequence

---

## Decision: Cancellation/Reversal Policy — Status Change + Reversal Records

**Decision:** Financial documents are never deleted. They are cancelled via status change. Corrections use reversal records.

**Reason:**
- Complete audit trail maintained
- Historical data integrity preserved
- Accountants and auditors expect this
- Reversal is clean and traceable
- No orphaned references

**Rules:**
- Draft documents: Can be edited and deleted
- Sent/Finalized documents: Can be cancelled (not edited)
- Cancellation requires reason and creates audit log
- Payment reversal creates reversal record (not deletion)
- Stock reversal on invoice cancellation creates positive movement
- Accounting reversal creates offsetting journal entry

**Alternatives Considered:**
- Soft delete on financial records: Loses audit trail, confuses accountants
- Edit in place on finalized documents: Dangerous, no history
- Void without record: Invisible, untraceable

**Consequences:**
- All financial statuses are append-only (never back to draft)
- Reports must handle reversed/cancelled records appropriately
- Historical reports include cancelled records with appropriate filtering
- More database rows but complete auditability

---

## Decision: Settings Architecture — Database with Cache Layer

**Decision:** Settings stored in database with business_id scope, cached per business, invalidated on update.

**Reason:**
- Database storage allows runtime modification without code changes
- Per-business settings naturally supported
- Cache layer prevents repeated queries
- Grouped organization prevents chaos
- Type hints prevent data type errors

**Cache Strategy:**
- First access: query database, cache in application cache
- Cache key: `settings:{business_id}:{group}` or `settings:{business_id}:all`
- Cache driver: database (default), configurable to Redis
- Invalidation: On any settings update, clear cache for that business
- Fallback: If cache fails, query database directly

**Alternatives Considered:**
- Config files only: Can't be modified at runtime
- Environment variables only: Requires file system access
- Redis-only: Not shared-hosting friendly
- JSON column on business table: Hard to manage, no type safety

**Consequences:**
- Settings updates take effect immediately (cache invalidated)
- Cache warm-up on first request after invalidation
- Settings changes are not atomic with business operations (acceptable)
- Settings backup included in business data backup

---

## Decision: Accounting Posting — Full Double-Entry in Phase 6

**Decision:** Full double-entry accounting (chart of accounts, journal entries, financial statements) is deferred to Phase 6. Marketplace V1 ships **Finance / Financial Tracking** only (invoices, payments, expenses, customer balances, customer ledger, receivables, financial summaries) — never full accounting, and never a competing "light accounting" engine.

**Reason:**
- Full accounting significantly increases V1 scope and complexity
- Most CodeCanyon buyers need invoicing before accounting
- Financial tracking is sufficient for the initial release
- Full accounting can be added later without breaking existing data
- Allows faster time to first marketplace release

**Marketplace V1 — Finance / Financial Tracking:**
- Customer balance tracking (computed from invoices and payments)
- Customer ledger (transaction list)
- Customer statement
- Expense tracking with categories
- Receivables report
- Multi-currency transaction fields (currency, exchange rate, base amount)

**Phase 6 Accounting:**
- Chart of accounts
- Journal entries (double-entry)
- Posting + reversal engines
- Automatic posting from invoices, payments, expenses, purchases
- General ledger
- Trial balance
- Profit & Loss
- Balance Sheet

**Alternatives Considered:**
- Full accounting in V1: Dramatically increases scope, delays release
- No financial tracking at all: Insufficient for many businesses
- Simple single-entry ledger presented as "accounting": Misleading, creates a competing implementation

**Consequences:**
- Phase 6 requires migration to add accounting tables
- Early financial domains are designed to be accounting-compatible from the start: immutable finalized identifiers, forward-only status rules, business scope, currency + base amounts, timestamps, cancellation/reversal records, source references, and domain events (InvoiceCreated, InvoicePaid, PaymentReceived, etc.)
- Existing V1 transaction data is retroactively postable without schema redesign
- Domain events on financial finalization serve as the Phase 6 posting hooks
- Exactly one accounting implementation exists; financial tracking never does debit/credit posting

---

## Decision: UI Framework — Custom Blade + Tailwind + Alpine.js

**Decision:** Completely custom UI using Blade templates, Tailwind CSS, and Alpine.js. No admin template or framework.

**Reason:**
- Unique visual identity for marketplace differentiation
- Tailwind allows precise design control
- Alpine.js provides interactivity without SPA complexity
- Blade is familiar to all Laravel developers
- No framework lock-in
- CodeCanyon buyers can understand and modify
- Lighter than React/Vue/Inertia for this use case

**Alternatives Considered:**
- AdminLTE/Filament: Common on CodeCanyon, not unique
- React/Vue SPA: Overkill, alienates Blade developers
- Livewire: Good but adds dependency and complexity
- Bootstrap: Less flexible than Tailwind for custom design

**Consequences:**
- Must build all components from scratch (one-time investment)
- Must maintain design consistency across modules
- Must support dark mode and RTL in all components
- More initial UI work but better long-term differentiation
- All Blade components are reusable across modules

---

## Decision: Sales vs Invoices — Unified Architecture

**Decision:** Invoices ARE the primary sales record. No separate `sales` table for MVP. Invoices handle service sales, product sales, and quotations converted to sales.

**Reason:**
- Eliminates duplication between sales and invoices
- Most SMB businesses think in terms of invoices, not sales orders
- Simpler data model
- Quotation → Invoice conversion is natural
- Payment allocation to invoices is straightforward
- Sales orders can be added later as a separate concept if needed

**Phase 4 Addition:**
Sales orders can be added as a separate pre-invoice concept for wholesale businesses that need order-fulfillment tracking.

**Alternatives Considered:**
- Separate sales table: Duplication, confusion, unnecessary complexity for most businesses
- Sales = Invoice + line items: Conceptual confusion
- No distinction needed: Most SMBs just need invoices

**Consequences:**
- Invoice statuses must accommodate "sales" workflows
- POS creates invoices directly (not a separate sale record)
- Reports based on invoice data
- When sales orders added later, they reference invoices

---

## Decision: Product Variants — Deferred to Phase 4

**Decision:** Product variants are NOT part of Marketplace V1. The V1 catalog supports **Physical Product** and **Service** types only. Variants are introduced in Phase 4, together with inventory, barcode, purchasing, warehousing, POS and manufacturing.

**Reason:**
- Variants touch inventory, barcode lookup, purchasing, warehousing, POS and manufacturing
- Introducing variants mid-V1 would multiply testing and calculation surface
- Businesses that need variants are the same businesses that typically need inventory
- V1 delivers the service/light-retail workflow with a simple catalog

**Alternatives Considered:**
- Variants in V1: Broad impact across stock, pricing and documents for marginal V1 buyer value
- Variants only via separate products: Not a real variant system, breaks barcode/stock workflows

**Consequences:**
- V1 `products` table keeps a reserved `has_variants` flag and variant bootstrap columns so migration to Phase 4 requires no redesign
- `product_variants` / `product_variant_values` tables are designed in the database plan but not implemented until Phase 4, Batch 31
- V1 UI never exposes variant fields; Phase 4 adds them alongside inventory

---

## Decision: Document Themes & Centralized PDF Service

**Decision:** No document type implements its own PDF pipeline. Quotation and Invoice batches produce **print-ready HTML** with a document-ready Blade structure only. Phase 3 introduces ONE centralized PDF/print service (used for invoices, quotations, statements; receipts added with POS) that shares a single **safe document-theme architecture** (config/settings-driven theme selection of static view files — never executable Blade stored in the database).

**Reason:**
- Avoids duplicated, throwaway PDF implementations in early batches
- One rendering pipeline gives consistent output and one place to fix print/PDF defects
- A single theme system prevents invoice, quotation, statement and receipt themes diverging
- No arbitrary user-supplied template code is ever executed — themes are shipped view files selected by settings

**Alternatives Considered:**
- Temporary PDF per document type in Phase 2 then a "real" one in Phase 3: wasted effort, drift, disposal cost
- Storing theme markup in the database: security risk (arbitrary Blade execution), rejected
- One generic Blade view for all themes: not customizable enough for buyers

**Consequences:**
- Early batches ship `document-ready` Blade structure + print CSS that the Phase 3 PDF service renders directly
- PDF generation is deferred until Phase 3, Batch 25 by design
- License works for themes without code edits; custom themes are document-view files CodeCanyon buyers can copy and register

---

## Decision: Multi-Currency — Included in Marketplace V1

**Decision:** Multi-currency transaction support ships in Marketplace V1 (Phase 2, Batch 19): base currency, transaction currency, historical exchange rates attached to each transaction, base-currency equivalent (`base_amount`), and currency formatting.

**Reason:**
- CodeCanyon buyers are global; cross-border invoicing is a baseline expectation
- Currency fields must exist on financial documents anyway for the future accounting module
- Historical rates attached to transactions are mandatory for auditability

**Alternatives Considered:**
- V1 single-currency only: unacceptable for a global marketplace product
- Live-rate fetching each time: wrong (recalculating history) and fragile
- Multi-currency only in Accounting phase: forces a schema migration of every financial table later

**Consequences:**
- Financial documents store `currency_id`, `exchange_rate` and base-currency totals from V1
- **Never recalculate past transactions with current rates** — rates are snapshotted at transaction time
- Base-currency reporting is compute-ready for the Phase 6 accounting module

---

## Decision: Batch 1 — Database-Independent Bootstrap

**Decision:** Use file sessions and file cache for the initial installation, with MySQL configured through environment variables and database queues retained as the primary queue option. No migrations or queue workers run automatically. Hosts without queue processing can explicitly select `QUEUE_CONNECTION=sync`.

**Reason:** Batch 1 must render without a configured database and support ordinary shared hosting. The planned database-backed settings cache remains a later-batch concern; Laravel's database cache/session drivers remain available.

**Consequences:** Only Laravel's default migrations are present. Database queues require configured MySQL and migrations before use. Infrastructure timezone is environment-driven (`APP_TIMEZONE`, UTC fallback); business timezone handling remains deferred. Tailwind 4 uses its Vite plugin and CSS source configuration; design tokens and components are delivered by Batch 2.

---

## Decision: Batch 2 — CSS-First Tailwind v4 Configuration & System Font Stack

**Decision:** Skip `tailwind.config.js` entirely; configure design tokens, elevation shadows, fonts, and branding through Tailwind v4 `@theme` in `resources/css/app.css`. Dark mode is class-based via `@custom-variant dark` with a pre-paint script (localStorage `bos-theme`, `prefers-color-scheme` fallback). Fonts use an explicit system font stack with Inter preferred — no external font downloads (CodeCanyon/offline-friendly).

**Reason:** Tailwind v4 is CSS-first by design; a JS config adds duplication and complexity. Class-based dark mode avoids flash-of-wrong-theme. A vendored or system font keeps the distributable self-contained and legal to ship.

**Consequences:** All design customization lives in one CSS file; utilities reference `brand-*`, `shadow-card/popover/overlay`. The `x-ui.*` Blade component library, Alpine behaviors (`resources/js/components/ui.js`), and the local-only `/ui-preview` showcase constitute the Batch 2 deliverable. App shell/navigation remains Batch 3.

---

## Decision: Batch 2 — Component Interaction Contracts

**Decision:** Overlays open via window events (`bos:open-modal` / `bos:open-drawer`) carrying `detail.id`, matched against each overlay's Alpine `id`. Dropdowns, modals, and drawers lock page scroll while open, close on Escape, and expose `close()` to component consumers.

**Reason:** Overlays can be triggered from anywhere without prop drilling; a single broadcast mechanism suits Blade components decoded by a shared Alpine feature layer.

**Consequences:** `<x-ui.modal id="x">` pairs with `$dispatch('bos:open-modal', { id: 'x' })`; no teleport is used — overlays render in place with `position: fixed`. All directional classes use Tailwind logical properties (`ms/me/ps/pe/start/end`) for RTL readiness, with `.rtl-flip` mirroring directional icons.

---

## Decision: Batch 3 — CSS-First Sidebar Collapse & Shell State

**Decision:** The desktop sidebar collapse is a pure UI preference handled entirely in CSS + Alpine + localStorage, mirroring the Batch 2 dark-mode approach. A Tailwind v4 custom variant `@custom-variant sidebar-collapsed (&:where(.sidebar-collapsed, .sidebar-collapsed *))` drives the shell widths — sidebar `w-64` ↔ `sidebar-collapsed:lg:w-16` and content padding `lg:ps-64` ↔ `sidebar-collapsed:lg:ps-16`. Shell state lives in an Alpine `appLayout` behavior (`resources/js/app-shell.js`): a reactive `collapsed` boolean mirrored to `<html class="sidebar-collapsed">`, persisted in `localStorage` key `bos-sidebar-collapsed`, and restored before first paint by a tiny inline pre-paint script so the shell never flashes the wrong width.

**Reason:**
- CSS-driven widths mean collapse is simply a class toggle — no JS recalculation, no Tailwind config changes, works with the existing `hidden lg:flex` responsive strategy.
- Pre-paint inline script (same pattern as the Batch 2 theme script) eliminates flash-of-wrong-sidebar-width while keeping the HTML non-JS-crawler-friendly.
- `localStorage` persistence requires no database or settings (no backend settings exist yet; Batch 4+/settings batches are not involved).
- Reusing Polly's Alpine scope chain (components read `collapsed` directly, mobile nav shadows it with local `false`) keeps the component API flat.

**Consequences:**
- Any element that responds to collapse must use the `sidebar-collapsed:*` variant or Alpine `collapsed` bindings; a new UI feature that needs collapse-awareness must opt in explicitly.
- The mobile navigation always renders the expanded form (its own `<nav x-data="{ collapsed: false }">` shadows the parent state).
- Batch 8 (and the eventual settings engine) may persist collapse per-user later without changing the view layer, since only the pre-paint/localStorage source would change.
- Blade gotcha reinforced: Alpine-reactive attributes in `.blade.php` views must use `x-bind:*` (literal passthrough); `:attr` shorthand is PHP-evaluated by Blade and fails on Alpine state.

---

## Decision: Batch 3 — Navigation Data Seam (Batch 8 Swap Point)

**Decision:** Navigation is consumed by the shell entirely through a single static placeholder `App\Support\Navigation` (`items()`, `isActive()`, `currentRoute()`). Items carry exactly `label`, `icon`, `route`, and `href`; the shell ignores everything else. Batch 8 builds the module-based registry/database-driven navigation by replacing the data SOURCE (config + `business_modules` state) behind the same contract without touching the rendering components.

**Reason:**
- Matches the existing DECISIONS module design ("navigation rendering reads config + database state") without building the registry prematurely.
- The shell (sidebar, mobile-nav, page chrome) stays module-agnostic — no `ModuleRegistry` dependency leaks into Batch 3.
- Active-state (route-name matching) is centralized in one place, so it degrades gracefully while only the preview route exists.

**Consequences:**
- Batch 3 ships no fake module routes; out-of-scope items link to `#` as safe inert destinations.
- The only implemented destination is the local `app.preview` route; `isActive()` currently matches by route name only.
- Batch 8 swaps `Navigation::items()` internals to read `config/modules.php` + business module state and keeps the same item contract; no shell component changes required.

---

## Decision: Batch 4 — Session-Based Locale Persistence & Translation Architecture

**Decision:** Locale is persisted in the session via `SetLocale` middleware (not database, not auth). The middleware reads `session('locale')`, validates against `config('localization.supported')`, and applies via `App::setLocale()`. Translation files use a domain-based structure (`resources/lang/{locale}/{domain}.php`) with keys like `common.dashboard`, `navigation.customers`, `actions.save`. RTL direction is determined by `config('localization.supported.{locale}.direction')` and rendered as `dir` on the `<html>` element. Locale switching uses `GET /locale/{locale}` with session persistence and redirect-back — no URL prefix architecture.

**Reason:**
- Session-based persistence requires no database, no auth, no user model — matches the current state (Batch 4 has no authentication).
- Domain-based translation files (`common.php`, `navigation.php`, `actions.php`) prevent a single massive `messages.php` and align with how later modules will organize their own translation domains.
- `dir` attribute on `<html>` is the standard RTL mechanism; Tailwind logical utilities (`ms/me/ps/pe/start/end`) and `.rtl-flip` (Batch 2) already handle the CSS side.
- No URL prefix (`/en/...`, `/fa/...`) simplifies routing and avoids complexity that would need to be unwound later. URL prefix architecture is explicitly deferred to Batch 26 (localization completion) if needed.
- `config('localization.supported')` is the single source of truth for available locales, their directions, and display names.

**Consequences:**
- The `SetLocale` middleware is registered globally on the web stack; every request applies the session locale.
- Locale fallback uses `config('app.locale')` = `en` when session is empty or stored locale is invalid.
- Translation keys follow `{domain}.{key}` convention; future modules add their own domain files (e.g., `customers.php`).
- The `x-app.locale-switcher` component reads `config('localization.supported')` and renders a dropdown with native labels and a check icon for the active locale.
- Batch 26 (localization completion) will sweep all remaining hard-coded strings and may introduce URL prefix routing if the architecture decision is revisited.

---

## Decision: Batch 5 — Never Trust Client-Supplied Redirect Targets (Referer / Intended)

**Decision:** No redirect in the application may be derived from an unvalidated client value. All authentication validation failures and form-request errors redirect to hard-coded named routes (`login`, `password.request`, `password.reset`). The two places that legitimately echo a requested destination — the post-login `url.intended` and the locale switcher's redirect-back — pass through `App\Support\SafeRedirect::path()`, which accepts only same-origin absolute URLs (scheme, host, and port must match `config('app.url')`) or local paths, and rejects protocol-relative `//host`, `%2f`/backslash-encoded separators, userinfo, scheme-only values, and any control/whitespace bytes. Additionally, the password-reset page is served with `Referrer-Policy: no-referrer` and `Cache-Control: no-store`, and reset mail throws a `LogicException` if the configured mailer's transport logs messages.

**Reason:**
- Validation errors and `intended()` helpers commonly fall back to the request Referer; an attacker-controlled Referer can turn a form error into an open redirect (phishing). Fixing this at every call site is fragile — a single enforcement point (`SafeRedirect`) plus named-route-only defaults makes the behavior non-negotiable.
- `url.intended` (already possible to set via a session fixation/link) and the locale-switch redirect-back are the only features that must accept a user-chosen destination.
- Reset tokens are bearer credentials; suppressing the Referer and caching of the reset page plus banning logging mail transports closes token-leak paths.

**Consequences:**
- New redirects that echo request input MUST go through `SafeRedirect::path()`; all other redirects should use named routes.
- The prior open-redirect through validation `Referer` fallback is closed and covered by the focused auth suite (`test_redirects_reject_external_and_protocol_relative_destinations`).
- `SafeRedirect::path($target)` defaults to `/app` on any non-conforming value; the locale switch falls back to `/`.
- Reset mail transport safety is enforced at runtime — switching `auth.password_mailer` to a logging transport (log/syslog/null) throws before any message is built.

---

## Decision: Batch 6 — Current-Business Context: Single Authoritative Mechanism

**Decision:** One container service, `App\Services\BusinessContext`, bound as **scoped**, is the single authoritative resolver for "which business am I in right now." Resolving the current business id:
1. Reads the authenticated user (never a guest).
2. Loads that user's member businesses (`business_memberships`).
3. Validates the session selection (`config('business.context.session_key')` = `current_business`) against those memberships — a stored id that does not belong to the user is **discarded, never trusted**.
4. When no valid selection exists, chooses the smallest accessible id deterministically and persists it to the session.

`Business::current()`, `Business::currentId()`, and `Business::isCurrent()` are thin static aliases that delegate to this service so the codebase has exactly one current-business mechanism. Switching (`BusinessContext::switchTo`) re-validates membership server-side and returns `null` on any non-owned id; `EnsureBusinessSelected` middleware (alias `business-selected`) sends users with zero businesses to onboarding and re-resolves stale selections on every guarded request.

**Reason:**
- Session values alone are untrusted (forgeable/stale); a single resolver prevents every route/controller re-implementing membership checks.
- Scoped binding gives one consistent context per request and is trivially testable; deterministic fallback keeps behavior identical across devices/sessions and avoids "no business selected" dead states.
- Static aliases on `Business` keep call sites readable while routing through one implementation.

**Consequences:**
- Routes that need a business must be behind `business-selected` (or resolve via the service). Business-owned controllers must call `app(BusinessContext::class)` — not read `session('current_business')` directly.
- Because Laravel 12.69 no longer clears scoped container instances between HTTP requests, `App\Http\Middleware\ForgetScopedInstances` is prepended globally to restore the per-request scoped lifecycle (see Batch 6 scoping note below). Without it, a long-running worker (Octane/FrankenPHP) or a feature test making multiple requests can reuse stale cached context.
- No permission/role checks exist yet — memberships are the only isolation boundary until Batch 7.

---

## Decision: Batch 6 — Tenancy Scoping Convention: Named Global Scope that is a No-Op Without Context

**Decision:** Tenant-owned models apply tenancy through `App\Traits\BelongsToBusiness`, which registers a global scope named `business` (filters by `business_id` = `BusinessContext::currentId()`) and auto-fills `business_id` from the current context on `creating` when it is null. `business_id` must never appear in `$fillable`. Critical rule: when **no current business exists** (guest, console command, queued job, or test), the scope is a deliberate **no-op** — the query runs unscoped.

**Reason:**
- The documented shared-database tenancy decision requires every business-owned query to filter by `business_id`; a trait applied to every tenant-owned model centralizes that so developers cannot forget it.
- Auto-fill on `creating` from context keeps creation correct without callers passing `business_id` (which would be forgeable).
- Making the scope a no-op without context is deliberate: it avoids batch processes/tests silently hiding entire tables and (for single-business-admin use) avoids a permanent dead-end where no business exists in workbench/testing yet.

**Consequences:**
- Query builders are externally silent unless `$model->withoutGlobalScope('business')` is used for cross-tenant operations — at this stage those are rare and must be reviewed.
- With no logged-in user or on a journey before onboarding, `currentId()` is `null` and the scope does nothing; routes that must enforce a business context sit behind the `business-selected` middleware (guaranteeing a valid context inside the authenticated shell), and the `BelongsToBusiness` scope is the query-level enforcement.
- Batch 7 adds role/permission authorization on top of this membership+`business_id` isolation; roles do NOT replace the scope.

---

## Decision: Batch 6 — Per-Request Scoped-Instance Lifecycle Restored via Middleware

**Decision:** Register `App\Http\Middleware\ForgetScopedInstances`, prepended to the global middleware stack. At the start of every request it calls `$app->forgetScopedInstances()` **and** flushes every cached route controller (`Route::flushController()`). This re-establishes the per-request lifecycle that Laravel ≤10 provided via its built-in middleware of the same name but that Laravel 12.69 no longer applies automatically to HTTP requests.

**Reason:**
- `BusinessContext` is bound as `scoped`; correct behavior depends on a fresh instance per request. Without the reset, the cached user/current-business leaks across requests when many requests share one application instance (feature tests, Octane/FrankenPHP/roadrunner, prolonged PHP-FPM workers).
- Resolving scoped services alone is NOT enough: `Route::getController()` caches the resolved controller on the Route object, and that controller captures its constructor-injected scoped services (e.g. `QuotationService` → `DocumentNumberService` → `BusinessContext`) at first use. Even with the container cleared, a cached controller keeps the STALE service graph of the previous request — observed as a cross-business numbering leak in feature tests. Hence the additional `flushController()` pass so the whole service graph is rebuilt per request, matching the fresh-process guarantee of classic FPM.
- The fix is a tiny, framework-aligned middleware (performing exactly the same work Laravel itself does for queue workers) with no dependency cost, restoring documented semantics rather than weakening them.

**Consequences:**
- `app(BusinessContext::class)` (or anything else bound `scoped`) is guaranteed fresh at each request start; tests that resolve scoped services between requests should re-resolve through HTTP calls or `forgetScopedInstances()` to avoid stale instances.
- Cached controllers are flushed per request; `$route->computedMiddleware` is recomputed (cheap) and middleware penetration costs one `getRoutes()` pass per request.
- Cross-user feature tests must also start a fresh session between users (e.g., `flushSession()`), because the `auth.session` middleware logs out when a session's `password_hash_*` no longer matches the acting-as user.

---

## Decision: Batch 7 — Custom Authorization: Global Permissions, Business-Scoped Roles, Membership-Scoped Assignment

**Decision:** Authorization is a custom (non-Spatie) system on top of the existing tenancy foundation, with three distinct scoping layers:
- **Permissions** are **global** rows (`permissions`), not business-scoped, keyed `entity.action` (e.g., `users.manage`, `settings.view`) and organized in groups (`config/permissions.php`).
- **Roles** are business-owned (`roles.business_id NOT NULL`, `(business_id, slug)` unique, `is_system` flag) with a bounded default set per business (`config/roles.php`: `owner`, `admin` = all permissions, `viewer` = users.view + settings.view).
- **Role assignment** is **membership-scoped** through the `business_membership_role` pivot — a role is assigned to a `business_memberships` row, never to a user row directly, so a user can hold different roles in different businesses.

Onboarding (`POST /onboarding`) provisions the default roles and assigns `owner` to the creator's membership atomically inside the same transaction that creates the business.

**Reason:**
- The roadmap and security plan define permission keys (`entity.action`) as the enforcement vocabulary; making them global means one permission grants the same meaning in every business while the effective grant is always evaluated inside the current business.
- Membership-scoped assignment (vs. `role_id` on `business_memberships`, as DATABASE_PLAN sketched) is what lets Scenario B work: the same user is Admin in Business A and Viewer in Business B, with the current business the sole determinant of effective permissions.
- No package: spatie/laravel-permission has no first-class notion of membership-scoped, per-business roles and would fight the tenancy model; a ~hundred-line service delivers exactly the required semantics.

**Consequences:**
- Only registered permission keys are enforceable — free-form `Gate::allows('anything')` falls through and is denied.
- Roles are provisioned per business (idempotent `provisionDefaultRoles()`); existing businesses created before seeding can obtain defaults on demand.
- Both pivots (`role_permission`, `business_membership_role`) are unique with timestamps: duplicate grants are impossible.
- No permission packages installed; governance for future batches: roles attach to memberships (never users), permission checks always go through `MembershipAuthorization` under the current business, and middleware (`permission:key`) guards state-changing/resource routes.

---

## Decision: Batch 7 — Roles Do NOT Use the Tenant Global Business Scope

**Decision:** The `Role` model does **not** apply `BelongsToBusiness`. Roles carry a real `business_id` foreign key with `(business_id, slug)` uniqueness, are reachable only through a business/membership relationship in code, and every authorization check filters roles to the membership's own business (`BusinessMembership::hasPermission`). The tenant global scope remains on user-facing tenant data; role isolation comes from the FK + relation chain + authorization-time filtering.

**Reason:**
- Roles are authorization **metadata**, not user-facing tenant data. There is no code path where a user queries roles across a business boundary: roles load only via `$business->roles()` or `$membership->roles*()`, and permission grants are recomputed per membership with an explicit `roles.business_id = <membership business>` filter (defense-in-depth against forged pivot rows).
- A global `business` scope on `Role` proved hostile to context-neutral operations: provisioning `owner`/`admin`/`viewer` defaults for a brand-new business inside the onboarding transaction, audit/maintenance queries, and cross-business test scaffolding all silently queried an *empty set* whenever a request context was set to a different business (e.g., User B's test quickly failing to find User A's role). The scope added isolation nothing else needs and broke legitimate use.

**Consequences:**
- `Role` queries are deterministic regardless of the current business; cross-tenant safety is enforced by the FK (a Role cannot attach to a different business's membership meaningfully via `assignRole`, which also throws on mismatches) and by `hasPermission`'s explicit business filter.
- `BelongsToBusiness` remains the standard for all future business-owned data models (customers, invoices, products, …); `Role` is the documented exception, notable because roles reference business-owned rows without owning user data.
- Tests must still verify isolation through the HTTP surface + membership API (Scenario A/B suites do), not by relying on scoped query behavior.

---

## Decision: Batch 7 — Single Authorization Service + Gate Integration

**Decision:** `App\Services\MembershipAuthorization` (scoped like `BusinessContext`) is the single authorization API: `can(string $permission, ?User, ?Business)` — defaults resolve to the context user + current business, guests/no-membership deny, and `currentRole()` reports the current membership's roles. `BusinessContext` caches a `membership()` resolver used by the service. The Gate is wired in `AppServiceProvider::boot`: `Gate::before` intercepts ONLY registered permission keys (flattened from `config('permissions.groups')`) and returns the service result; any other ability returns `null` so future Policies compose normally.

**Reason:**
- One service + "the current business" gives a single decision point the whole app (controllers, policies, Blade) can trust; `Gate::before` makes `@can('settings.manage')` and `Gate::allows(...)` work natively with zero per-view plumbing.
- Returning `null` for non-permission abilities preserves the policy architecture for Batch 10+ resource authorization instead of hijacking the Gate.

**Consequences:**
- Blade uses `@can('entity.action')` everywhere; controllers use middleware or the service; the middleware and Blade share the same key space.
- Tests assert service and Gate agree and that deny-without-context holds (guest, or user with no membership, or no current business).

---

## Decision: Batch 7 — Middleware Is the Boundary; Hidden UI Is a Convention

**Decision:** Enforcement splits by trust level: `App\Http\Middleware\EnsurePermission` (alias `permission:users.manage`) guards routes — guests raise `AuthenticationException` (→ login redirect) and unauthorized members get a plain localized `abort(403)` (never a redirect to another business, never a peek at the resource). `@can(...)` only hides/disables navigation items and buttons and is explicitly not a security boundary.

**Reason:**
- A hidden dropdown is cosmetic, not protection; a Route-level deny that short-circuits before the controller is authoritative. Keeping the two apart makes the security model auditable and prevents "hidden UI is authorization" bugs.

**Consequences:**
- State-changing and data-revealing routes carry `permission:writ.e` middleware; Blade filters presentation; the 403 page is localized (en/fa/ar) via `resources/views/errors/403.blade.php` + `resources/lang/*/authorization.php`.
- The `auth/home` page renders the current business's roles and permission grants for transparency (localized role labels via `Lang::has` fallback because `__()`'s third argument is the locale, not a default value).
- No super-admin, no system-wide roles, no permission packages — remaining authorization scope (module activation keys, per-module permission groups) belongs to Batch 8.

---

## Decision: Batch 8 — Module Enablement Is Not Authorization

**Decision:** Module availability and authorization are two strictly independent gates. `module:key` (`App\Http\Middleware\EnsureModule`) checks availability only — the module is registered in the config registry AND enabled for the current business. Guests raise `AuthenticationException` (login redirect); unregistered or disabled modules get a plain localized `abort(403)`. The middleware never switches businesses, never redirects elsewhere, and never evaluates a permission. The separate `permission:key` middleware (Batch 7) is the authorization gate. Batches compose them per route: `['business-selected', 'module:customers', 'permission:customers.view']`.

**Reason:**
- The roadmap treats module activation as a business-level concern while permissions are a user-level concern; merging them (e.g., a `module.*` permission that implies access) would silently collapse the two and make "module installed but user not entitled" impossible to express.
- Keeping them separate means Batch 9 Settings can toggle modules without ever touching the authorization model, and Batch 10+ can ship module CRUD routes that remain individually permission-guarded.

**Consequences:**
- A disabled module rejects users who hold the matching permission; a missing permission rejects users inside an enabled module. Both must pass for access (and navigation shows an item only when both true).
- Permission keys for module scopes exist as ordinary global keys (`customers.view`); the module metadata may reference a permission, but `module:` middleware never enforces it.
- No module-management routes or toggles exist in this batch; `business_modules` rows are written by provisioning and tests only.

---

## Decision: Batch 8 — Code-Backed Module Registry + Per-Business Persistence (Implementation Confirmed)

**Decision:** Batch 8 implements the long-standing "PHP Config Array Registry" decision as exactly two layers: `config/modules.php` is the single code-backed registry (stable keys, localized label keys, icons, navigation group/order/route, optional permission, and `default_enabled`) — never duplicated as master DB rows; `business_modules` (business FK, `module_key`, `enabled`, unique `(business_id, module_key)`) holds per-business enablement only. `App\Services\ModuleManager` answers "available right now" by requiring a registered key AND an enabled current-business row, resolving the current business exclusively through `BusinessContext`, with per-request memoization reset by `ForgetScopedInstances`.

**Reason:**
- Config keeps module metadata version-controlled, inspectable, and user-overridable; a DB master table would add a second source of truth and require seeding.
- Only per-business state is genuinely dynamic, so it is the only part stored per row; the unique key makes default provisioning idempotent by construction.

**Consequences:**
- New modules are code changes (registry entry), activation/deactivation per business is a `business_modules` row — matching the roadmap's "config + database state" navigation rendering.
- Default provisioning (dashboard + settings) is idempotent and runs inside the onboarding transaction so a created business is always immediately operable; customers/sales/etc. remain opt-in for Batch 9+.

---

## Decision: Batch 8 — Navigation Filters at the Data Source Behind the Shell Contract

**Decision:** `App\Support\Navigation::items()` — the single seam consumed by the shell components — now builds the sidebar from the module registry filtered by (a) module enabled for the current business (`ModuleManager`) and (b) the module's declared `permission` when present (`Gate::allows`). Items keep exactly `label`, `icon`, `route`, `href`. The `app.preview` route keeps its legacy full nav (local preview has no business context); with no current business the nav is a minimal Home-only section. There is never a "hidden item equals forbidden route" assumption — hiding is presentation only, the middleware/Gate is the boundary.

**Reason:**
- The Batch 3 seam was designed for exactly this swap: changing the data source behind an unchanged item contract keeps the shell (sidebar, mobile-nav, nav-link) module-agnostic with zero component changes.
- Filtering at one data source prevents per-component drift (sidebar vs. mobile nav must always agree) and keeps RTL/localized labels (`__('modules.*')`) in one place.

**Consequences:**
- Module + permission gating is recomputed every request from the current business; switching businesses re-derives the navigation without any shell state.
- Out-of-scope destinations stay inert `#` links (no fake feature routes or controllers in this batch).
- Future modules declare navigation metadata in the registry; the shell contract does not change.

---

## Decision: Batch 9 — Settings as Sparse Overrides over Code-Backed Defaults

**Decision:** `config/settings.php` is the single source of settings definitions (groups `general` = address/phone/email and `regional` = timezone/date_format/time_format/locale, each key carrying `default` + `type`) plus the allowed date/time-format option lists. `App\Services\BusinessSettings` (scoped, running under the current business resolved through `BusinessContext` only) resolves `get('group.key')` as stored override → definition default, persists overrides via `updateMany`, clears back to default on stored `null`, and rejects any unknown key (there is no write path to a definition outside the whitelisted groups). The `settings` table holds sparse per-business override rows only — a brand-new business needs zero rows. Business `name` lives on `businesses.name`, never on settings.

**Reason:**
- Runtime-editable behavior needs DB storage, but config remains the readable, version-controlled catalogue of WHAT can exist; sparse rows keep the settings table tiny and new businesses instant.
- One scoped service (mirroring `ModuleManager`) keeps current-business resolution and per-request freshness identical to the rest of the app.
- An unknown-key rejection is simpler and safer than dynamic key creation (no typo-driven settings, no admin-only keys enforceable purely by convention).
- Definitions are NESTED arrays because Laravel's config repository does not traverse literal dotted keys (`config('settings.definitions.regional.timezone')` is unreachable if the key physically contains dots) — verified empirically in batch 9.

**Consequences:**
- Business locale (`regional.locale`) feeds `SetLocale` as the business-default layer: session locale → business default → `config('app.locale')`.
- The settings UI is guarded by `module:settings` (availability) AND `permission:settings.view` / `permission:settings.manage` (authorization) — the Batch 8 composition convention; the page is read-only for viewers and saves through a whitelist `FormRequest` whose `validationData()` remaps flat dotted form names (`regional.timezone`) to nested arrays because the Validator treats dotted rule keys as nested data paths.
- No batch-9 work writes `businesses.status/slug/currency`, no settings cache layer (per-request memoization only, per the no-Redis constraint), no currency/numbering/logo-upload settings.

---

## Decision: Batch 10 — Customers as a Lean Tenant-Scoped Registry with Soft Deletes

**Decision:** The `customers` table carries only registry fields (`name`, `company_name`, `email`, `phone`, `address`, `notes`), the `business_id` FK with cascade delete, and `deleted_at` (soft delete). There is deliberately no `status` column, no customer code/numbering (deferred to Batch 13 centralized numbering), and no financial aggregates (`balance`, `credit_limit`, `opening_balance`, etc. — those belong to the invoice/payment/ledger batches and are never denormalised onto the registry row). The permission catalogue gains exactly one new key: `customers.manage`, granted to the `owner` + `admin` default roles. The `customers` module is NOT enabled by default (opt-in only, same as Batch 8 registry design) but carries a `route` key in the navigation config so the sidebar links to the real route.

**Reason:**
- A minimal registry is sufficient for the CRUD and search needs identified in the roadmap. Early columns like `status` were removed because the roadmap does not require customer statuses, and the guidance is to "not invent a status column without a clear business rule."
- Financial aggregates on the customer row would create denormalisation and concurrency hazards once invoices and payments are added. The approved convention (Batch 9's sparse-overrides pattern applied to money later) is to compute or read from the ledger/payment source-of-truth, never from the customer row.
- Customer code numbering depends on a centralised `NumberingService` that belongs to Batch 13. Adding an `auto_increment` customer code in Batch 10 would predate that service and create a conflicting scheme.
- Soft deletes preserve FK integrity for future invoices/payments referencing a customer, while permanently hiding the row from reads (the `business` global scope plus `SoftDeletes` default scope make soft-deleted rows invisible to normal queries).
- `customers.manage` is a new permission key following the same `entity.action` convention as `settings.manage`, without inventing granular sub-permissions for individual fields.

**Alternatives Considered:**
- Adding `status` (active/inactive) — removed because the roadmap does not require customer statuses in this batch and early YAGNI is safer than removing a prematurely shipped column.
- Adding `auto_increment` customer code or `code` column — rejected as conflicting with the approved Batch 13 `NumberingService`.
- Hard deletes — rejected: would break future invoice/payment foreign keys and the roadmap's "soft delete by default" convention.
- Denormalised `balance`/`total_due` — rejected: must be computed by the payment/ledger batches.

**Consequences:**
- A soft-deleted customer row stays in the DB with `deleted_at` set; future financial records can retain a valid FK while the customer is hidden from UI and API reads.
- The `business_id` FK with `cascadeOnDelete` enforces the tenancy boundary at the DB level; the `BelongsToBusiness` global scope enforces it at the query level; the owner/admin roles have `customers.manage` already mapped so no post-seed migration is needed for new businesses, though existing businesses should call `provisionDefaultRoles()` after upgrading the code.
- No customer status model exists yet; adding statuses in a later batch can either add a `status` column or introduce a `customer_statuses` config depending on the decided degree of flexibility.
- The module remains opt-in (disabled by default) until the business owner activates it, matching the Batch 8 convention.

---

## Decision: Batch 11 — Optional Taxes Are a Permanent, Per-Business Feature Flag (`general.tax_enabled`)

**Decision:** Taxes ship as a permanently optional feature controlled by the per-business setting `general.tax_enabled` (default `false`) in `config/settings.php`. The `taxes` table, `Tax` model, routes, and `taxes.*` permissions exist unconditionally and follow the exact same tenancy + module + permission conventions as categories and units; only the ROUTES and the navigation item are gated by the new `tax-enabled` middleware (`App\Http\Middleware\EnsureTaxEnabled` — guests raise `AuthenticationException`, feature off yields a plain localized `abort(403)`, no business/route switching). Disabling the feature NEVER deletes tax rows and NEVER auto-deletes anything; re-enabling restores the same definitions. There is no "enable then create" coupling: creating a tax never flips the flag on.

**Reason:**
- The marketplace requirement is to deliver taxes as optional functionality without degrading the non-tax experience; a settings-toggle (already the Batch 9 mechanism) is the smallest, releasable control and is visible/manageable from the existing Settings page.
- Keeping the schema/model/permissions present-but-latent avoids later migrations and lets businesses adopt taxes mid-flight without data loss; the simple state machine (any combination of enabled/disabled × rows exist/empty) is fully testable.
- The flag is permanent and business-scoped (like every setting), not phase-gated: future tax-aware code (products' tax links, invoice tax computation, reports) MUST respect `general.tax_enabled` and must never infer feature state from `Tax::exists()` — data existence and feature state are orthogonal.

**Alternatives Considered:**
- Shipping taxes unconditionally in V1: conflicts with the roadmap's optional-tax framing and taxes default-on businesses that never asked.
- Hiding the `Tax` model/schema until a later phase: forces a structural migration later and leaves product/invoice batches unable to reference taxes.
- Inferring the feature from `Tax::exists()`: wrong — a business that defined taxes then disabled the feature would stay "enabled".

**Consequences:**
- Tax routes carry `['auth','auth.session','business-selected','module:products','tax-enabled']` atop the per-route `taxes.view`/`taxes.manage` permissions; categories/units never see the tax gate.
- The Settings useEffect stays a hidden `0` + `x-ui.toggle` so an unchecked box still submits the "off" value; `UpdateSettingsRequest` whitelists `general.tax_enabled` (boolean) with a localized invalid message.
- Tests assert every quadrant: feature off + rows absent, feature off + rows present (403 while data persists), feature on + rows present (restored), and that define/disable/enable cycles preserve rows.

---

## Decision: Batch 11 — Master-Data Name Uniqueness via a Business-Scoped Rule, Not a DB Unique Constraint

**Decision:** Unique-within-business names for categories, units, and taxes are enforced by the reusable `App\Rules\UniqueInBusiness` validation rule — it queries the target table scoped to the current business (`BusinessContext::currentId()`), ignores soft-deleted rows (`whereNull('deleted_at')`), and supports an optional `ignoreId` for updates. There is NO DB-level unique constraint on `(business_id, name)`.

**Reason:**
- Soft deletes are the established "delete" convention (Batch 10); a hard DB unique constraint on the name column would permanently block re-creating a deleted name, which users expect to be possible.
- The rule centralizes the business-scoped, soft-delete-aware check once instead of at every Store/Update form request, keeps `name` validation readable, and matches how updates must compare within the same business only.
- A unique DB index adds no defense the rule plus the tenancy FK do not already provide, and it would fight future master-data rename/merge features.

**Alternatives Considered:**
- Unique index on `(business_id, name)` (and partial/unfiltered queries to bypass soft deletes): fragile, surprises soft-delete re-creation, harder to extend.
- Controller-level duplicate checks: repeated, easy to miss on one route.
- Global (non-business) uniqueness: incorrect — two businesses must be allowed the same category/unit/tax names.

**Consequences:**
- Validation failures surface as the standard `validation.unique` message; updates use `new UniqueInBusiness(..., ignoreId: $model->id)` so editing a record doesn't reject its own name.
- Cross-business duplicates are allowed and asserted; duplicates within a business (including against a soft-deleted row) are rejected.
- Records intentionally have NO hard-delete path with names freed up — only soft delete, so the rule is the only place that must stay soft-delete-aware.

---

## Decision: Batch 11 — Master Data Reuses the `products` Module (No New Module Keys)

**Decision:** Categories, units, and taxes introduce NO new module keys and no `business_modules` rows of their own. All three route groups guard with the existing `module:products`; the `products` navigation entry in `config/modules.php` gained a `children` array (categories, units, taxes — each with its own `permission`; the taxes child adds `'setting' => 'general.tax_enabled'`), and `App\Support\Navigation::items()` now renders children gated by child permission (`Gate::allows`) plus the optional committed setting (`BusinessSettings::get`), replacing the parent item when children exist.

**Reason:**
- These are sub-entities of the product catalogue — enabling `products` is the single, meaningful on/off decision a business makes; per-entity toggles would be noise and a second source of truth.
- The module registry is also locked: `ModuleSystemTest` asserts exactly 7 keys, and master data didn't need a new registry scope.
- Reusing the existing navigation machinery keeps the sidebar change additive (children under an existing entry) and the taxes item is correctly hidden by the same feature gate the routes enforce.

**Alternatives Considered:**
- New `categories`/`units`/`taxes` module keys with their own enablement rows: redundant business decisions plus registry/test churn for no behavioral gain.
- A separate master-data module: over-engineering — no business requirement distinguishes "product catalog master data" from "products".

**Consequences:**
- `test_navigation_uses_the_real_route` (per entity) asserts the child metadata (route/permission/setting) and that the sidebar shows/hides the right items per permissions and the tax flag.
- `TenancyTest` scope guard stays at the Batch 11 boundary (categories/units/taxes tables present, no products table yet, registry unchanged at 7 keys).
- Future catalogue work (products themselves) continues under the same `products` module with its own `products.view`/`products.manage` keys in Batch 12.

---

## Decision: Batch 12 — Single Typed `products` Table Is the Product & Service Registry

**Decision:** Products and services live in ONE table (`products`) distinguished by a `type` column (`App\Enums\ProductType::Product` / `Service`, string(20), default `product`), under the existing `products` module. The catalog ships with `sale_price` DECIMAL(16,4) as the only money value, a nullable `sku` (business-scoped unique via the Batch 11 rule, never auto-generated), nullable `category_id`/`unit_id`/`tax_id` FKs to the Batch 11 master data with `nullOnDelete`, `description` text, soft deletes, and an index on `(business_id, type)`. There is deliberately NO `has_variants`/variant columns, no stock/quantity/cost columns, no `image`, no barcode, no purchase price, and no financial aggregates — those arrive with Phase 4 inventory and the purchasing batches, and resurrection after shipping would be a migration (à la the Batch 2 decision against premature variant columns).

**Reason:**
- Services share the entire catalog lifecycle (types, SKU, pricing, master-data links, deletion) with physical products; a parallel `services` table would duplicate every column and every future catalogue feature for zero behavioral gain.
- `type` on one table keeps the Batch 13 centralized numbering, invoicing lines, and later inventory extension composable — Phase 4 adds its columns to this same row.
- DECIMAL(16,4) implements the permanent money-precision decision (`999,999,999,999.9999` max) exactly two decimal places beyond any currency's needs, consistent with `taxes.rate` (DECIMAL(8,4)).
- A business-user-visible SKU is a free-text field: enforcing "unique SKU" at the application layer (the proven `UniqueInBusiness` soft-delete-aware rule) avoids blocking re-use of a deleted SKU and keeps cross-business duplicates legal; auto-generation is deferred to Batch 13 numbering.

**Alternatives Considered:**
- Separate `products` and `services` tables: column duplication, dual permission/routing/empty-state surface, harder to extend one catalogue.
- `products` without a type but services filtered by a nullable `unit_id`/child table: implicit, unmaintainable typing.
- Auto-increment SKU at create time: conflicts with the Batch 13 `NumberingService` (the same rationale as customer codes).

**Consequences:**
- `ProductController` search matches `name` OR `sku`; the index filters by `?type=` via `ProductType::tryFrom` (invalid values silently fall back to "all"); the create screen offers a type select defaulting to `product`.
- Master-data FKs are `nullOnDelete` so soft-deleting a category/unit/tax never orphans or hides referencing products (asserted); selectable dropdowns list only current-business ACTIVE rows (per the scope's `whereNull('deleted_at')`).
- The type enum is enforced at request time with `Rule::enum`, at the DB by the `type` column, and in memory by the model's enum cast — no free-form catalogue kinds.
- Stock/variant/barcode/purchase-price columns remain strictly out of scope until Phase 4 (Batch 31), locked by a `TenancyTest` schema guard.

---

## Decision: Batch 12 — SKU Uniqueness (Business-Scoped, Soft-Delete Aware) Without a DB Constraint

**Decision:** Product/SKU uniqueness inside a business (with soft-delete reuse and cross-business allowance) is enforced by reusing `App\Rules\UniqueInBusiness` on the `products.sku` column — `whereNull('deleted_at')`, scoped to `BusinessContext::currentId()`, ignoring the record's own `sku` on update via the `ignoreId` argument. No DB unique index on `(business_id, sku)`.

**Reason:**
- Identical reasoning to Batch 11 names: a hard unique constraint would permanently block re-creating a deleted product with the same SKU (soft-delete is the deletion convention), and the same rule already centralizes the business-scoped soft-delete-aware check.
- SKU is optional and free-text; the DB cannot know "null means no SKU" is distinct from "repeat SKU" across businesses without application logic anyway.
- The rule keeps store/update validation readable and consistent with categories/units/taxes (same message, same semantics).

**Alternatives Considered:** DB unique index (fights soft deletes and cross-business duplicates); controller-level checks (repeated, easy to miss).

**Consequences:**
- Same-business duplicate SKUs are rejected; cross-business duplicates allowed; re-creating a soft-deleted SKU succeeds; empty/missing SKU is always accepted; updates never reject the record's own SKU. All four asserted in `ProductTest`.
- SKU stays a plain `string(50)` display/scan field — human-readable formatting/numbering belongs to Batch 13, not auto-generation here.

---

## Decision: Batch 12 — Master-Data References Are Validated Tenant-Scoped; Tax Is Optional and Toggle-Aware

**Decision:** `category_id`, `unit_id`, and (when the tax feature is on) `tax_id` are validated with a tenant-scoped `Rule::exists($table,'id')` that adds `business_id = BusinessContext::currentId()` AND `whereNull('deleted_at')` — a foreign-business or soft-deleted id fails validation with the standard `validation.exists` error. `tax_id` follows the Batch 11 feature flag strictly: while `general.tax_enabled` is OFF the key is omitted from the form-request rules entirely, so (a) it never enters `validated()` and unrelated updates PRESERVE the stored `tax_id`, (b) a forged `tax_id` can neither assign nor change the link, and (c) re-enabling the feature restores the stored value without any reconciliation. The Product model does NOT derive its tax from `Tax::exists()` and no tax math is computed in this batch.

**Reason:**
- Plain `Rule::exists` would accept ids from other businesses — the tenancy + soft-delete enforcement must live IN the rule so no caller can forget it (same spirit as `BelongsToBusiness`).
- Omitting the disabled key (rather than `nullable|prohibited`) is the only choice that satisfies all three of preserve-when-disabled, refuse-forgery-while-disabled, and restore-on-re-enable with zero write-path branch complexity — mirrors the Batch 11 "data existence never implies feature state" mandate at the product level.
- `nullOnDelete` + a validation-time soft-delete filter means a permanently hidden (soft-deleted) master row can never be selected, while historic products keep their FK.

**Alternatives Considered:**
- `whereNull` on a plain exists rule per field: three call sites duplicating the same closure (rejected — one helper).
- `Nullable|Exists` even while disabled: would let a forged `tax_id` silently persist a link the business never wanted while the feature is off.
- `prohibited_if`/`present_if` gymnastics: reads terribly, still fails the forged-value guarantee cleanly.

**Consequences:**
- Both `StoreProductRequest` and `UpdateProductRequest` share a private `scopedExists(string $table)` helper (returns `Illuminate\Validation\Rules\Exists` — `Rule::exists()`'s return type, not the `Rule` facade).
- The product form renders the tax selector only when `$taxesEnabled`; the every-quadrant tax test matrix (enabled→valid, disabled→preserved/forged-ignored/re-enable-restored, foreign/soft-deleted ids rejected) is asserted in `ProductTest`.

---

## Decision: Batch 12 — Navigation Parent Rendering for Child-Bearing Modules

**Decision:** `App\Support\Navigation::items()` renders the module's own item FIRST when the module declares a `route`, then its `children` beneath it — instead of replacing the parent item when children exist (Batch 11 behavior). Concretely, `config/modules.php` gives the `products` module `route => 'products.index'` + `permission => 'products.view'` (module-level permission now gates the whole entry, matching customers) above its existing categories/units/taxes children. Only `products` carries both children and a route, so the general branch is minimal and the shell contract (label/icon/route/href) is unchanged.

**Reason:**
- Batch 12's real catalogue index is the natural landing page for the products navigation entry; hiding it because children exist would bury the registry behind master-data sub-items.
- The parent's declared module permission (already the customers convention) makes the products entry hide for viewers lacking `products.view` regardless of children — one Gate check instead of duplicating it per child.
- Keeping children' per-child permission/setting gates intact preserves the Batch 11 tax-item hide/show behavior.

**Alternatives Considered:** New menu group for products; duplicating the permission onto every child; a second nav "hub" page.

**Consequences:**
- `Navigation::items()` has exactly one new branch; `ModuleSystemTest` navigation filters still pass, `CategoryTest`/`UnitTest`/`TaxTest` child metadata assertions are untouched, and `ProductTest` asserts both the configuration (`products.index` route) and the rendered sidebar href.
- The parent item renders for the owner when the module is enabled and `products.view` granted; individual children remain gated by their own permissions and the tax setting.

---

## Decision: Batch 13 — Document Numbering Is Monotonic Per Business + Document Type (Supersedes the Year-Based Format Sketch)

**Decision:** Document numbers are allocated by `App\Services\DocumentNumberService::next(DocumentType): string` as a single **monotonic, never-resetting** series per business + document type, formatted `PREFIX-000001` (defaults: `config/numbering.php` — QUO/INV/PAY/EXP/PO, padding 6, prefix `[A-Z0-9_-]`, prefix ≤10, padding 1–12). This **supersedes the earlier planning-era "Invoice Numbering" decision** (`{prefix}-{year}-{padded_sequence}`): numbers never auto-reset on day/month/year/fiscal boundaries. Year-segmentation remains possible later as an additive feature (a format/segment change) because the counter is per business+type and the suffix is pure formatting.

**Reason:**
- Reset boundaries are a business-policy choice and the wrong default: auto-resetting on 1 Jan forces reconfiguration and destroys cross-period URL stability for customers, while monotonic series stay valid, sequential, and audit-friendly for every document kind with zero policy assumptions (the roadmap's configurable-prefix/settings requirement is already met).
- Per business+document_type counters keep every kind independent (a quotation and an invoice for the same business never share a sequence) and keep the number globally meaningful *within* the business.
- The stable `App\Enums\DocumentType` values (quotation/invoice/payment/expense/purchase_order) are machine keys shared by the table column, the `numbering.{type}_prefix` settings keys, and the batches that later create those documents.

**Alternatives Considered:** Year/day/month-reset sequences (deferred, additive later); shared single counter across all document kinds (rejected — kinds must interleave independently); random/hash numbers (not sequential, not human-friendly); auto-increment-only (no format).

**Consequences:**
- Gaps are possible (a rolled-back document consumes a number) and acceptable; numbers are never reused.
- Settings overrides affect the FORMAT only, never the counter; clearing an override restores the config default.
- The earlier DECISIONS entry "Invoice Numbering — Centralized Service with Database Sequences" is amended by this decision for the format and reset semantics only; its core (centralized service + DB sequences + per-type series + `SELECT FOR UPDATE`) is confirmed and implemented here.

---

## Decision: Batch 13 — Row-Locked Allocation With a Real Unique Constraint (No MAX()+1)

**Decision:** Every allocation runs inside a `DB::transaction` and `SELECT ... FOR UPDATE` on the `document_number_sequences` row for `(business_id, document_type)` before incrementing. First use seeds a zeroed row; the composite unique DB constraint on `(business_id, document_type)` is the concurrency gate, and a loser of the seed race catches the integrity `QueryException`, re-reads the winner's now-locked row, and proceeds (a single bounded retry). `MAX(last_number)+1` scanning is never used. The counter is BIGINT UNSIGNED and the service refuses (throws) rather than wrap at the limit.

**Reason:**
- A row lock serializes increments so two concurrent requests cannot observe the same `last_number`; the unique constraint makes the first-use race safe without app-level checks that lose to timing.
- `MAX()+1` is inherently racy (two readers can compute the same next number without a lock) and is explicitly forbidden; the seeded row + lock + constraint is the standard correct pattern.
- Laravel's `DB::transaction` nests as savepoints, so the allocation composes with the document's own outer transaction for free — rolling back the document also rolls back the counter, keeping numbering consistent with committed documents.

**Alternatives Considered:** Auto-increment PK as the number signal (`id` reuse gaps + no per-type format + needs a row regardless); separate lock table (extra table, no gain over locking the committed row); Redis sequences (not shared-hosting friendly, violates the no-Redis constraint).

**Consequences:**
- Verified live on dev MySQL: 10 concurrent worker processes produced exactly `INV-000001..INV-000010` with zero duplicates/errors and `last_number = 10`.
- `DocumentNumberSequence` is an infrastructure model: `$guarded = ['*']` (no CRUD/mass assignment) and deliberately NO `BelongsToBusiness` global scope (the service scopes explicitly by the current business id, mirroring `Setting`) — the documented exception to the tenant-scope convention, because the row is shared mutable counter state, not a business-owned entity.
- The business is resolved ONLY through `BusinessContext`; without a current business the service throws instead of inventing a tenant.

---

## Decision: Batch 13 — Formatting Via Sparse Settings Overrides Over Code-Backed Defaults

**Decision:** Number formatting defaults to `config/numbering.php` and may be overridden per business through the new `numbering` settings group (five `{type}_prefix` keys + `padding`), each with `default => null` so a business that does not override stores zero rows and falls back to the code default. `UpdateSettingsRequest` whitelists the keys (`sometimes|nullable`: prefixes constrained by the config `prefix_pattern` + max length, padding integer 1–12) with localized messages in en/fa/ar. A stored override that violates the pattern at read time is a configuration error the service refuses loudly rather than emitting a malformed/ambiguous number. No new permissions, module keys, routes, or UI — numbering has no screens in this batch; the settings keys are validated by the existing settings endpoint but rendered nowhere.

**Reason:**
- The roadmap requires configurable prefixes "via settings per document type"; sparse overrides over `config/settings.php` definitions reuse the Batch 9 machinery exactly (definitions are required for overrides to persist — the config comment deferring numbering to its own module is now satisfied by this batch, and `config/settings.php`'s scope note is updated accordingly).
- Defaults stay code-backed for version control and instant new-business behavior; the settings view renders only `general.*`/`regional.*`, so adding a group affects no UI.
- Refusing an invalid override keeps every emitted number within the documented `[A-Z0-9_-]` character set instead of silently guessing.

**Alternatives Considered:** No overrides at all (fails the roadmap's configurable-prefix requirement); store prefixes in `businesses` columns (denormalization, opposes sparse settings); trusting unvalidated overrides at read time (can emit malformed numbers).

**Consequences:**
- `TenancyTest` scope guard asserts `document_number_sequences` is the Batch 13 boundary and no document entity tables exist; the permission catalogue stays at 14 keys (numbering adds none).
- No numbering UI and no consumer refactor in this batch (the first consumer is Batch 14 — Quotations); the service is fully unit- and concurrency-tested before any document uses it.

---

## Decision: Batch 14 — Quotations Line-/Document-Level Field Set (Schema Fidelity)

**Decision:** `quotations` implements the roadmap's explicit "tax/discount calculation" as a **document-level discount** (DATABASE_PLAN `discount_type` + `discount_amount`) plus per-line tax, and **no per-line discount** (the Batch 14 item enumeration lists only `quantity, unit_price, tax_id, tax_rate, line_subtotal, line_tax, line_total` and no per-line discount columns). The following DATABASE_PLAN `quotations` columns are **dropped as speculative/deferred** in this batch: `currency_id`, `exchange_rate`, `additional_charges`, `converted_to_invoice_id`. `created_by`, `notes`, `terms`, `date`, `expiry_date`, `status` are retained.

**Reason:** DATABASE_PLAN is not final (it carries Phase-6 accounting scaffolding like `converted_to_invoice_id` and multi-currency fields whose consumers are not built); the roadmap is the normative scope, and three prior batches followed the "no consumers, no column" precedent. Fixed-discount arithmetic, per-line markup, and conversion wiring are additive migrations for Batch 15 and later. Header columns follow the approved source: `subtotal`, `discount_type`, `discount_amount`, `tax_amount`, `total`; item total columns are named `line_subtotal`, `line_tax`, `line_total`. `customer_id` is nullable FKs with `nullOnDelete` (soft-delete survival + hard-delete safety), consistent with the master-data convention in Batches 1–13.

**Consequences:** No quotation→invoice conversion exists anywhere in Batch 14 (no invoices table, no conversion button/route, `status = converted` is not settable — see below). `quotation_items` has `quotation_id` FK with `cascadeOnDelete` and is NOT business-scoped itself (no `business_id` column); tenancy flows through the parent quotation.

---

## Decision: Batch 14 — BCMath Half-Away-From-Zero Decimal Convention (Permanent)

**Decision:** All money/tax arithmetic uses BCMath (`bcadd`/`bcsub`/`bcmul`/`bcdiv`) through a single static helper `App\Support\Decimal` with **4-decimal half-away-from-zero rounding** and 8-decimal intermediate precision; values are always decimal strings internally. This is the app's permanent decimal convention — Batch 15 Invoices and later finance modules reuse the same helper (`QuotationCalculator` composes `Decimal`, it is not an inheritance chain). If BCMath is missing the helper throws loudly at first use instead of degrading to float arithmetic.

**Reason:** Floats cannot represent money exactly; the DECISIONS rule "all money arithmetic must be exact" forces a decimal engine. BCMath is the lightest exact decimal engine available on standard shared hosting (GNU bc is present in essentially every PHP build), and a single helper centralizes rounding mode + scale so every module rounds identically. Half-away-from-zero is the familiar "round half up for positives, half down for negatives" convention expected by bookkeeping; scale 4 (0.0001) matches the `DECIMAL(16,4)` columns established in Batches 9–13.

**Alternatives Considered:** Decimal strings with manual rounding (duplicated, error-prone); scaled-integer arithmetic (harder to read, no printf-friendly values); Float with `round()` (the DECISIONS ban); the `moneyphp/money` package (heavyweight, not config-backed, and the app avoids utility packages where built-ins suffice).

**Consequences:** `Decimal` normalizes inputs to strings, validates numerics, and refuses NaN/INF. Unit tests pin the rounding semantics (`1.23445 → 1.2345`, `1.23444 → 1.2344`). All `DECIMAL` columns store the 4-dp string produced by the calculator.

---

## Decision: Batch 14 — Calculation Order: Exclusive Tax on Line Subtotals, Discount Applied On Document Subtotal

**Decision:** Deterministic, documented order-of-operations: `line_subtotal = round4(qty × unit_price)`; `line_tax = round4(line_subtotal × tax_rate / 100)`; `line_total = line_subtotal + line_tax`; `subtotal = Σ line_subtotal`; `tax_amount = Σ line_tax`; discount resolves to `discount_applied` (percentage: `round4(subtotal × amount / 100)`; fixed: `min(amount, subtotal)`; both clamped to `subtotal`), stored as raw `/type`+`amount` on the header; `total = round4(subtotal − discount_applied + tax_amount)`. Presenting and line totals are each rounded before summation (half-away-from-zero), so sums are exact integer-like string additions of 4-dp values.

**Reason:** Batch 14's item spec fixes tax as exclusive per line; applying the discount to the document subtotal (before adding tax) is the standard SMB quotation convention and keeps discount referential to the pre-tax base. Clamping guarantees `total ≥ 0`. Rounding each line before summing avoids drift between the UI preview and the stored header and keeps the arithmetic fully reproducible.

**Consequences:** The `QuotationCalculator` is a pure, static, stateless class returning 4-dp strings; it never touches the DB or the request. Server-side values are authoritative; the Alpine line editor only previews (non-authoritative) totals.

---

## Decision: Batch 14 — Quotation Lifecycle: Draft-Editable Documents; `converted` Reserved

**Decision:** Quotations are read/write only in status `draft`. Draft quotations can be created, edited (including status change to any of the five non-converted statuses: `draft/sent/accepted/rejected/expired`), and **deleted** (soft, number never reused). Once a quotation leaves `draft` it is immutable in Batch 14: update/delete → `403`; it remains fully readable (`show`/`index` render for all statuses). `status = converted` is enum-defined for Batch 15 but **not settable** by any form/validation/route in this batch.

**Reason:** The roadmap's status workflow (`draft → sent → accepted/rejected/expired`) must be reachable, and DECISIONS fixed "draft documents: editable + deletable; sent/finalized documents: cancelled, not edited". Quotations are pre-sales documents (no ledger postings yet), so they get a restricted lifecycle rather than a full cancel/reversal machinery; restricting destructive/editing actions to drafts keeps the financial-integrity spirit with minimal scope. `converted` is contractually the invoice-conversion terminal state, so userland must not write it early.

**Consequences:** `QuotationService::update`/`destroy` branch on status and throw `AccessDeniedHttpException` on non-draft; the Authorization middleware (`quotations.manage`) is the first gate and the status check is the second. Deleting a non-draft is a 403, not a cascade.

---

## Decision: Batch 15 — Invoice Lifecycle: `draft` Editable, `sent` Final; No Payment Statuses in This Batch

**Decision:** `App\Enums\InvoiceStatus` defines exactly **Draft** and **Sent**. Draft invoices are created/edited (including a status change to `sent`) and deleted (soft, number never reused — the Batch 13 guarantee). `sent` — every converted invoice is stored as `sent` — is **final in Batch 15**: edit/update/delete → hard `403`, full readability retains. Payment states (`paid`, `partially_paid`, `overdue`) and `cancelled` are deliberately NOT in the enum in this batch: the form requests' `settableStatuses()` = `[draft, sent]`, so posting them fails validation (`status.in`) and no such column/value can ever be written. The invoices table ships no `amount_paid`/`amount_due`/`paid_at` columns.

**Reason:** The roadmap timeline places payments (Batch 16) after invoices; shipping "paid/overdue" values now would either be uncomputable (nothing can change the balance yet) or force a half-built payment subsystem into the invoice batch. The financial-integrity convention from Batch 14 applies identically: once a document leaves draft it is immutable, and the conversion path produces exactly this `sent`+immutable state. Payment statuses and balance columns are additive to `invoices` in Batch 16, so nothing in this decision blocks the roadmap.

**Alternatives Considered:** Including a computed-on-the-fly `paid`/`overdue` status derived only from request data (rejected — status must be factual, and nothing records payments yet); a `cancelled` status now (deferred with the cancellation/reversal machinery); allowing edits after `sent` (rejected — same rule as quotations).

**Consequences:** `InvoiceService::update`/`destroy` assert draft (`AccessDeniedHttpException` → 403); validation (not the service) rejects payment statuses with the localized `status_in` message; the schema guard in `InvoiceTest` asserts `amount_paid`/`amount_due`/`paid_at` are absent; Batch 16 adds the payment statuses + allocation columns as an additive migration.

---

## Decision: Batch 15 — Quotation → Invoice Conversion Is One-to-One, Transactional, and No-Drift

**Decision:** Conversion (`POST /quotations/{quotation}/convert`, named `quotations.convert`) is guarded by `module:sales` + `permission:quotations.view` + `permission:invoices.manage` and runs entirely inside `InvoiceService::convert` in ONE `DB::transaction`: (1) `BusinessContext::isCurrent($quotation)` (cross-business → route-level 404 first, plus this re-check), (2) `$quotation->lockForUpdate()` re-read inside the transaction, (3) if already `converted` → throw a safe `ValidationException` (`quotations.validation.not_convertible`), (4) allocate `DocumentNumberService::next(DocumentType::Invoice)` (nested savepoint), (5) copy `customer_id`, discount type/amount, and notes plus every item snapshot VERBATIM, (6) RECOMPUTE all totals from those locked snapshots via `QuotationCalculator` (`subtotal`/`tax_amount`/`discount_amount`/`total` — invoice totals byte-for-byte equal the source quotation: no drift), (7) persist the invoice as `sent`, (8) mark the quotation `converted` (terminal, both documents immutable thereafter). The invoices table has a DB **UNIQUE constraint on `quotation_id`** so even a raw second insert cannot slip through.

**Reason:** The DECISIONS "Sales vs Invoices" roadmap treats quotation→invoice as the natural sales path and "conversion" is the roadmap's own term; a one-to-one, terminal mapping (never one-to-many, never reversible) keeps the audit trail simple and prevents a quotation being invoiced twice. Recomputing from the locked snapshots (rather than copying stored header totals) guarantees the invoice is the mathematical image of the source at conversion time — the same no-drift convention Batch 14 applied to update, now enforced across two documents. The row lock serializes racers at the application layer; the DB unique index is the final backstop; a lost race fails with zero orphan rows and zero consumed numbers (verified live on MySQL with two parallel OS processes).

**Alternatives Considered:** Copying stored header totals (rejects the recompute guarantee and disguises any pre-existing mismatch); one quotation → N invoices (not terminal, breaks the audit story); soft-flag only (no hard unique) (a concurrent double-convert would eventually be caught but could leave two invoices referencing one quotation); conversion as draft (final invoices cannot be a draft — Batch 15's `sent` is exactly the conversion outcome).

**Consequences:** Converted quotations and their invoices are BOTH immutable (edit/update/delete 403 on either side); deleting a converted quotation is a soft delete that never changes the invoice; the `invoices.quotation_id` unique index is asserted by schema tests and the backstop test. Conversion never rewrites documents after creation (rate/discount edits on source rows are ignored by design).

---

## Decision: Batch 15 — Invoice Children Have No Soft Deletes; Header Soft-Delete Is the Only Delete; History Survives Customer Soft-Deletes

**Decision:** `invoice_items` has **no `deleted_at`** and **no `business_id`** — it is a pure child of `invoices` (`invoice_id` FK `cascadeOnDelete`), mirroring `quotation_items`. Deleting an invoice is the header-only soft delete from the quotation convention: the header row keeps `deleted_at`, the item snapshot rows stay attached (invisible to normal queries through `SoftDeletes`). `Invoice.customer` and `Invoice.quotation` resolve with `withTrashed()` so a soft-deleted customer or source quotation never breaks historical rendering.

**Reason:** The Batch 14 schema convention was approved for this exact pattern: soft-delete only the header and keep item rows as the immutable historical snapshot — per-item soft deletes would let an invoice silently under-delete lines and per-item tenancy is redundant (tenancy flows through the parent). With translated FK visibility (`nullOnDelete` + `withTrashed`), a customer who is later soft-deleted (or even hard-deleted — deletes cascade the header) cannot orphan or corrupt the financial record.

**Alternatives Considered:** Per-item soft deletes (rejected — snapshot columns would be shipped and history could be partially hidden); per-item `business_id` (rejected — the parent is where tenancy is enforced); hard-deleting item rows with the header (rejected — loses the preserved history that the "never delete finalized data" convention requires).

**Consequences:** Schema tests assert `invoice_items` has no `deleted_at`/`business_id`; soft-delete tests assert the header is `assertSoftDeleted` while its item rows remain; customer soft-delete tests assert the show page still renders the historical customer name. Direct invoice CRUD can never create an item row without a header (cascade delete mirrors creation through `InvoiceItem::create` under the transaction).

---

## Decision: Batch 15 — Viewer Reads Invoices by Default; Sales Navigation Becomes Children-Only

**Decision:** The default **Viewer** role receives `invoices.view` (in addition to `users.view` + `settings.view`) but NOT `invoices.manage` and NOT `quotations.view` — a viewer is a read-only invoice observer while quotations stay hidden from them. The `sales` module navigation entry becomes **children-only**: no parent `route`, no module-level `permission`; its two children `Quotations` (`quotations.index` + `quotations.view`) and `Invoices` (`invoices.index` + `invoices.view`) carry their own per-entry permission. Owner + admin hold both `invoices.*` keys.

**Reason:** An invoice is the priced, sent commercial record a small business shows an accountant or a junior partner — read access is the ordinary expectation, while managing invoices stays privileged (matches how settings.view works for viewers). The children-only nav gives each top-level sales document its own distinct landing route without inventing a new sales-dashboard page, and each entry hides independently by its own permission (a viewer sees Invoices but not Quotations in the sidebar).

**Alternatives Considered:** Viewer with no invoice access (too strict — breaks the ordinary read workflow); a parent `sales` route that both children point through (a fake hub page with no real controller); module-level `permission` on the sales entry (would gate both children together and cannot express the viewer split).

**Consequences:** The `AuthorizationTest` permission catalogue is 18 keys; `ModuleSystemTest`'s nav assertions for sales now check the children config; `QuotationTest`'s navigation test was rewritten to assert the children entry + visibility per business; conversion requires `quotations.view` AND `invoices.manage`, so a viewer can never convert, while an operator holding quotations-view-instead-of-invoices-manage is still blocked — the two permissions stay independent.

---

## Decision: Batch 18 — Ledger Is a Transaction-Based Read Model (Never Aggregates on the Customer Row)

**Decision:** The customer ledger computes everything from source-of-truth rows on demand. `CustomerLedgerService::summary()` and `ledger()` aggregate **finalized** (non-draft) invoice totals via `invoices.items` subtotals and **active/all** payment allocations from the payments Batch 16 read model — never `amount_paid`, `amount_due`, or any denormalised customer column. Opening balance is the **only** user-entered balance and lives on the customer row as `opening_balance` (DECIMAL(16,4)) + `opening_balance_date`, excluded from the counter-graph/summary "all-time" totals and shown as the first ledger row and a dedicated stat card. A customer with a soft-deleted invoice or customer keeps its rows (history survives, `withTrashed()` resolution) — consistent with Batch 15.

**Reason:** The Batch 10 decision (line 728) reserved all financial aggregates off the registry row; computing balances from real rows keeps one source of truth, makes customer soft-deletes non-destructive to money history, and needs no write-path maintenance with invoice/payment lifetime changes. The Financial Tracking convention (ACCOUNTING_DESIGN §Financial Tracking Ledger) prescribes a transaction-based, non-journal ledger for Marketplace V1.

**Alternatives Considered:** Denormalised `balance`/`total_due` on `customers` (rejected — creates concurrency/consistency hazards, already foreclosed by DECISIONS line 728); journal/GL postings (rejected — Accounting is Phase 6); computing from draft invoices (rejected — draft = not yet a financial record).

---

## Decision: Batch 18 — Ledger/Statement Presentation Contract (Filters, Reversal, Print, Viewer)

**Decision:** One `ledger()` row source powers both pages with two headings (Ledger / Statement), and the statement reuses the identical rows in a print-ready layout. Filters: `?date_from`/`?date_to` → prepends a **"Balance brought forward"** row and switches the closing footer to **"Closing balance (period)"** (all-time outstanding is still shown as a separate stat/footer line); `?search` (reference/description) or `?type=invoice|payment|opening` → matches source-of-truth rows and, when a filter is active, **hides the running Balance column** (its running totals are meaningless on a filtered subset). Reversal presentation (Payments Batch 16): the original allocation row gets badge **Reversed** and a new explicit reversal row is emitted, so the ledger states the true position. Print: statements hide the app shell (`#app-sidebar`, `header.app-header`, breadcrumbs, `.no-print`) through `@media print` in `resources/css/app.css` — no server-side "print template" yet (the centralized PDF service is Phase 3). **Viewer** receives `customers.view` (reads list/show/ledger/statement) but not `customers.manage` (edit/create/delete remain 403); ledger/statement routes sit under the `customers` module route group. Opening balance is only editable through the customer form: `opening_balance` (decimal) + `opening_balance_date` (`<input type="date">`, bound as `Y-m-d` — a raw ISO datetime is invalid for the input and silently dropped by browsers).

**Reason:** A single shared row-model keeps the two pages truthful to each other; hiding the balance column under filters prevents a misleading running total; "closing balance (period)" + brought-forward is the standard statement format for a date range; the reversal-row presentation mirrors Batch 16's reversal model so the ledger never misstates collectable amounts; viewer read access matches the Batch 15 viewer precedent (read-only observer).

**Alternatives Considered:** Server-side dedicated print template (rejected — duplicated markup, preempts the Phase 3 shared document service); always show balance column under filters (rejected — misleads); keep date input bound as Carbon string (rejected — browsers clear invalid `type="date"` values, regression found in QA and fixed with `->format('Y-m-d')`); giving viewer `customers.manage` (rejected — must stay write-protected).

**Consequences:** `TenancyTest` asserts `customers` has `opening_balance`/`opening_balance_date` and **no** `balance`/`credit_limit`/`total_due`/`total_paid`. `ModuleSystemTest`'s no-permission legs now use a **role-less membership** (member without any role) for "no `customers.view`" since viewer legitimately holds it. `CustomerLedgerTest` (24 tests) locks the row model, filters, reversal, viewer 403 on edit/write, and form prefill `value="YYYY-MM-DD"`. Print CSS ships in the compiled blade asset; `npm run build` regenerates it.

---

## Decision: Batch 19 — Base Currency Is a Per-Business Setting (AFN Default), Not a Column

**Decision:** Every business has exactly one base currency for reporting and ledger aggregation, stored as the `regional.currency` setting (`config/settings.php`, default `'afn'` — never a `businesses.currency` column). Exchange rates are a `rate` DECIMAL(16,8) on `exchange_rates` per `(business_id, currency_code, effective_date)` with the semantics **1 unit of the transaction currency costs `rate` units of the base currency** (base currency always has rate 1). A rate is provided per currency per date; the newest `effective_date <= document date` applies (ties broken by newest row). Legacy rows backfill to AFN/1.

**Reason:** The roadmap (MASTER_PLAN "must support multi-currency") and the marketplace definition name multi-currency a V1 feature; a single default with opt-in per-business currency lists keeps V1 finance reporting coherent (a ledger can only aggregate in one base). Storing `currency` on the business row was the tempting seam, but settings already own regional policy and the base-currency gate (below) is a settings-update concern, so reusing `regional.currency` keeps `businesses` minimal per the Batch 6/A/B decisions.

**Alternatives Considered:** `businesses.currency` column (rejected — new column where a settings key exists, per-setting lifecycle already proven in Batch 9); multiple simultaneous reporting bases (rejected — ledger/report aggregation must pick one; the alternate display happens later via Phase 3 formatting).

---

## Decision: Batch 19 — Documents Snapshot Currency + Rate and Compute `base_amount` (Never Re-read Rates at Display Time)

**Decision:** Invoices, quotations, payments, and expenses (headers AND items) snapshot `currency_code` + `exchange_rate` (DECIMAL(16,8)) and carry `base_amount` (DECIMAL(16,4), per line and per header). The snapshot is taken **at write time** (`CurrencyService::resolveOrFail`), so a `sent`/final document is immutable in both its native and base value; only draft edits re-resolve against rates in effect on the edited date. Payments inherit the invoice's currency + rate (a request-supplied `currency_code` is `prohibited`) with rate-resolution fallback for non-invoice payments. Quotation→invoice conversion copies the quotation's currency + rate verbatim and recomputes base — no drift, matching the Batch 15 no-drift contract.

**Reason:** Historical documents must never change meaning when an exchange rate is edited later (a $1200 sale at 70.5 AFN/USD is `84600.0000` AFN forever). Computing `base_amount` at write time keeps the ledger/report read paths free of rate lookups and immune to rate deletion. The deferred question — "can a user re-rate a sent document" — is explicitly NO by the snapshot design, consistent with Batch 14/15 immutability.

**Alternatives Considered:** Resolve rates live on every read (rejected — rate deletion/edits rewrite financial history); store only a `currency_code` and present native amounts only (rejected — breaks the base-currency ledger aggregate requirement); a separate `document_currencies` table (rejected — snapshot columns are simpler and match the existing additive-column precedent from Batch 15).

---

## Decision: Batch 19 — The Base-Currency Change Gate Uses Financial-History Signals, Driven by a Write-Path `Locked`

**Decision:** Changing `regional.currency` is **locked** (`settings.validation.currency_locked`) once a business has ANY non-draft financial record (finalized invoices/quotations, payments, expenses — via `CurrencyService::hasFinancialHistory()`). Drafts never lock. Disabling a currency with financial history fails with `currencies.validation.already_enabled`-style guard messages; re-enabling restores the same currency and rates. Rate rows once deleted can be re-added (unique business+code+date allows versioned rates to accumulate).

**Reason:** Re-basing a business with money history would silently rescale every historical `exchange_rate`/`base_amount`; locking confines the base choice to the pre-historical window while the follow-up tasks (re-basing with an explicit conversion) are deferred. Guarding at the settings write path (single choke point, already the settings.manage surface) means pages/controllers need no bespoke checks.

---

## Decision: Batch 19 — Ledger/Statement Aggregate in Base Currency with Nominal Foreign-Currency Rows

**Decision:** `CustomerLedgerService` emits each row with nominal `debit`/`credit` + `currency_code` AND converted `base_debit`/`base_credit` (from the row's snapshot rate). Summary/stat cards/running balance aggregate on `base_*` values; when a row's `currency_code` ≠ the business base, the UI shows the code + `base_amount` and a note (`customers.ledger.base_currency_note`). Rate-1 rows are byte-identical to Batch 18 output, and the entire pre-existing `CustomerLedgerTest` remains green unchanged.

**Reason:** A ledger must sum a single currency to be meaningful; the snapshot makes those sums exact and historical. Keeping the nominal columns preserves the "what actually happened" view per document, and the rate-1 parity means no regression to Batch 18.

---

## Decision: Batch 19 — Rates/Enablement Live Under `settings.manage` with No New Permission Keys; UI Add/Delete Forms Stay Plain-Rendered

**Decision:** No new permission keys (catalogue stays 18): enabling/disabling currencies and exchange-rate CRUD reuse `settings.manage`; the page is read-only for `settings.view`. Routes are named `settings.currencies.store/destroy` and `settings.exchange-rates.store/destroy` under `permission:settings.manage`. The currency/rate interaction on the Settings page uses `x-ui.button`/`x-ui.icon-button` with an explicit `type` prop (member attribute rather than Blade attribute bag — tests for `x-ui.button`/`x-ui.icon-button` already cover `type` pass-through) instead of GridView.

**Reason:** Multi-currency is configuration, exactly what Settings owns; new keys would expand the role matrix for pure configuration. The `type` prop keeps DELETE buttons `type="button"` (no unintended form submit) while the surrounding per-currency forms post each rate/currency action their own row — the correct behavior for a repeatable edit surface.

**Consequences:** `MultiCurrencyTest` (26 tests) locks schema/seeding, enabled-currency API + cross-business isolation, the base-select + lock gate (financial history → 403, drafts pass, unlock after), currency enable/disable lifecycle, rate addition/ordering/deletion scoping, write-time snapshotting with exact Decimal math and draft re-resolution, conversion no-drift, payment inheritance + prohibition, the mixed-currency ledger, permissions (viewer read-only via `settings.view`/`settings.manage`), and the mandatory multi-business switch isolation. `TenancyTest` asserts Batch 19 schema and `assertRate()` (Decimal-normalized) for all DECIMAL(16,8) reads because SQLite numeric affinity returns float `70.5` not the string `'70.50000000'`.

---

## Decision: Batch 20 — Import Pipeline Is Preview-Before-Write with Server-Side Revalidation, Never Create-On-Upload

**Decision:** Customer/product CSV import is a three-step flow: (1) `POST /{type}/import` validates + stores the file and returns a preview token; (2) `GET /preview/{token}` scans/validates/maps EVERY row (header first, then rows) and renders per-row errors, ready vs total counts, and the Confirm action only when not a single row is invalid; (3) `POST /preview/{token}/execute` re-reads and re-validates the CSV **from disk** inside one `DB::transaction` and writes everything or nothing, deletes the staged file, and consumes the token. A serialized preview is never trusted — the execute path re-runs the mapper, so a file edited between preview and execute cannot slip invalid rows in.

**Reason:** Create-on-upload would leave a partially-valid file writing some rows and silently dropping others, and it removes the user's chance to see exactly which rows failed before anything is written. Revalidating server-side at execute keeps the guarantee even against tampered intermediate state; the single transaction preserves the ledger/`DocumentNumberService` precedent that a write is atomic.

**Alternatives Considered:** Process rows server-side in a single POST with a two-phase "dry run + apply" in memory (rejected — the staged file + token is the durable artifact, survives refresh, and gives the preview its own URL); Excel/PhpSpreadsheet parsing (rejected — `.csv` only per batch constraints, streaming `fgetcsv` keeps us on the core); direct DB insert during scan (rejected — nothing may be written before Confirmation).

---

## Decision: Batch 20 — Import Token Is a Superseding Session-Bound 64-Char Random String; No New Table

**Decision:** `ImportService::createToken()` issues `Str::random(64)`, keys it under `import_tokens` in the session, and **supersedes** any earlier pending token for the same user/business/type (a newer upload invalidates the older preview with a localized "stale" error). The token is scoped by the session + business in the route middleware (a customer token can never be consumed as a product import, and Business A's token 404s in Business B). `max_rows` (2000) and `preview_rows` (10) cap scanning; a file over the cap throws `TooManyRowsException` and is rejected during scan.

**Reason:** A dedicated `import_staging` table would add schema for a transient artifact; the session already carries tenancy/auth, and the token build + supersede rule gives the same "one pending import" guarantee the unique business+type constraint would. 64 random chars make guessing infeasible, and scoping by type + business in the route prevents cross-context consumption without any cross-table key.

**Alternatives Considered:** Staging table with FK + status column (rejected — no new tables this batch; session + disk is sufficient); fixed short token (rejected — 64 chars from `Str::random` matches Laravel's own reset-token convention); keeping every uploaded file until GC (rejected — supersede + single pending import bounds disk usage).

---

## Decision: Batch 20 — Mappers Resolve Reference Names Case-Insensitively and Business-Scoped; SKU/Price Validation Happens per Row

**Decision:** `ProductImportMapper` resolves `category`, `unit`, and `tax` by name with a case-insensitive lookup scoped to the current business (a lookup builds an in-memory map keyed by `mb_strtolower(name)`); a value that matches nothing fails its row with a localized "not found" message, one that matches multiple rows fails with an "ambiguous" message; the same applies to tax gating (tax-set-while-disabled fails the row). SKUs are matched exactly (leading zeros preserved) against the business's products AND against everything else in the same file (within-file duplicate blocks the import), and `sale_price`/`opening_balance` normalize through `Decimal::normalize` to exact DECIMAL(16,4) with an integer-digit limit and scientific-notation rejection.

**Reason:** Users type `ELECTRONICS` into an `Electronics` catalog — case-insensitive name mapping is what makes the template human-usable, and scoping the lookup to the current business preserves the master-data isolation contract (Batch 12). Exact-SKU matching keeps "001" and "1" distinct (Batch 12's SKU uniqueness), and the within-file check catches a user error no database constraint can because rows haven't been written yet.

**Consequences:** `ProductImportTest`/`CustomerImportTest` (47 tests) lock header validation, row column-count/required rules, reference resolution (unknown/ambiguous/foreign/soft-deleted), SKU within-file + existing-business duplicates, tax gating, price/balance normalization, cross-business and cross-type token rejection, supersede/cancel/queue behavior, template downloads, and the row-cap guard.

---

## Decision: Batch 20 — Upload Validation Is MIME-Allowlisted But the Parse Is the Security Boundary

**Decision:** `POST /{type}/import` requires `extensions:csv` + `mimes:csv` + `mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel` + `max:{max_file_kb}` (2048 in config). That validation is best-effort: the pipeline treats the FILE CONTENT as the boundary. `CsvReader` streams with `fgetcsv` (BOM/CRLF/blank-line tolerant), header cells must be a known/accepted column set, and every row must have exactly the header's column count before any mapping — so a rename-to-.csv binary is either rejected by MIME sniffing or, if it slips through, fails cleanly at parse time and creates nothing.

**Reason:** MIME sniffers guess from content and are not a reliable security boundary (the test suite demonstrated `UploadedFile::fake()->createWithContent()` guessing MIME from bytes rather than extension). Structure validation of the actual parsed CSV — header column set, exact column count, per-row type/value rules — is the deterministic defense, and the test asserts the invariant that matters: binary content can never create records, whatever the sniffers decide.

---

## Decision: Batch 20 — Import Language Keys Avoid the `imports.row` String/Array Collision

**Decision:** The `imports.php` lang file uses a NESTED `row` array (`row.required`, `row.column_count`) for mapper row errors and a FLAT `row_label` key for the preview table's "Row" heading. PHP later-key-wins semantics mean defining `'row'` twice (once as a string label, once as an array) silently collapses the earlier definition — `__('imports.row')` then resolves to an ARRAY and `htmlspecialchars()` blows up in the preview view. Naming the UI heading `row_label` keeps the two uses distinct in every locale (en/fa/ar).

**Reason:** The collision is invisible until a service reads `__('imports.row')` and gets an array; segregating label vs nested keys makes the shape self-documenting and matches the existing flat-vs-nested convention in `common.php`/`products.php`.

**Consequences:** `preview.blade.php` renders `__('imports.row_label')`; mappers consume `__('imports.row.required')` / `__('imports.row.column_count')`. The 47-test import suite exercises both paths (header-invalid pages, per-row error lists, ready counts) in the default en locale.

---

## Decision: Batch 20 — Tests Treat MIME Sniffing, DECIMAL Readback, Carbon Casts, and CSV Cell Counts as First-Class Fixture Concerns

**Decision:** Import test fixtures and assertions are written to the pipeline's REAL behavior rather than to idealizations: (a) the binary-upload test asserts "no records created" instead of a specific validation error, because `createWithContent()` lets content drive the sniffed MIME type; (b) exact-value asserts compare 4-decimal strings (`'100.5000'`) since SQLite returns DECIMAL(16,4) as strings; (c) `opening_balance_date` compares via `->toDateString()` because it casts to Carbon; (d) every fixture row is verified to have exactly the header's column count and the intended values in the intended columns.

**Reason:** These four were the root causes of the batch's entire nine-test failure cluster: a decimal:4 assert expected `100.50` not `'100.5000'`, a Carbon object was compared to a date string, several product rows had 7 cells against an 8-column header (so column-count errors masked duplicate-SKU and within-file checks), and one "unknown category" fixture literally placed the bad value in the unit column (the page correctly said "Related Unit not found"). Asserting the real contract keeps the suite meaningful instead of testing stripped expectations.

---
