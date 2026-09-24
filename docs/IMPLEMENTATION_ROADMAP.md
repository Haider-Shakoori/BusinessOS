# BusinessOS — Implementation Roadmap

## Overview

Development is organized into 9 phases with 52 implementation batches. Each batch is a cohesive unit of work that can be completed, tested, and verified independently.

**Phases:** 9
**Total Batches:** 52
**Estimated Complexity Distribution:**
- Small: 8 batches
- Medium: 30 batches
- Large: 14 batches

### Design System Priority

The custom Tailwind design system and application shell are established in Phase 1, immediately after project initialization and BEFORE business modules are built. Every business-module batch reuses these shared components. No module invents its own UI.

---

## Phase 1 — Foundation (Batches 1-9)

### Batch 1: Project Initialization
- **Objective:** Laravel project setup with core configuration
- **Features:** Fresh Laravel 12 install, Vite + Tailwind CSS setup, Alpine.js, folder structure, environment configuration, base layout
- **Dependencies:** None
- **Files/Domains:** composer.json, package.json, vite.config.js, app/, config/, resources/views/layouts/, routes/
- **Database:** None (Laravel defaults)
- **Security:** APP_KEY generation, .env protection, HTTPS in production
- **Verification:** Laravel serves welcome page, Tailwind compiles, Vite builds
- **Complexity:** Small

### Batch 2: Tailwind Design Foundation & Blade Components
- **Objective:** Establish the custom design system and reusable component library (primary commercial differentiator)
- **Features:** Design tokens (color, typography, spacing, radius, shadows), shared Blade components (button, input, select, textarea, checkbox, radio, toggle, card, badge, alert, modal, table, pagination, dropdown, status badge, page header, breadcrumb, empty state, skeleton, loading), dark-mode-ready class conventions, responsive and RTL-ready component conventions
- **Dependencies:** Batch 1
- **Files/Domains:** resources/css/ (design tokens in `@theme`, dark variant), resources/views/components/ui/ (`x-ui.*` Blade components), resources/js/components/ (Alpine.js behaviors), layouts/showcase.blade.php + ui-preview (local-only showcase)
- **Database:** None
- **Security:** N/A
- **Verification:** Components render correctly on desktop/tablet/mobile, dark mode works, RTL class names follow Tailwind logical-property conventions
- **Complexity:** Medium

### Batch 3: Application Shell & Navigation
- **Objective:** Application chrome: collapsible sidebar, top header, mobile navigation
- **Features:** Sidebar (collapsible, responsive, overlay on mobile), top header (global search placeholder, notifications placeholder, user dropdown), mobile menu, breadcrumbs, page structure container
- **Dependencies:** Batch 2
- **Files/Domains:** layouts/app.blade.php, sidebar component, header component, mobile navigation, navigation renderer scaffolding
- **Database:** None
- **Security:** N/A
- **Verification:** Layout responsive, sidebar collapses, mobile fullscreen drawer works, dark mode toggle functional
- **Complexity:** Large

### Batch 4: Localization Foundation
- **Objective:** Establish translation and formatting conventions before any Blade business pages are built
- **Features:** Laravel language files (English), `__()` / `trans()` conventions, locale architecture (per-user / per-business), formatting helpers (date, number, currency), RTL-aware component conventions, timezone handling
- **Dependencies:** Batch 2
- **Files/Domains:** lang/en/, app/Services/Localization/, locale middleware, formatting helpers, RTL CSS conventions
- **Database:** None
- **Security:** N/A
- **Verification:** All future-facing Blade strings use translation keys, formatting helpers exist, locale switching works at basic level
- **Complexity:** Medium

### Batch 5: Authentication
- **Objective:** User authentication system
- **Features:** Login, logout, password reset, remember me, registration (admin only), session management
- **Dependencies:** Batches 2, 4
- **Files/Domains:** Auth controllers, auth views, login/register/reset-password blades (localized), auth middleware
- **Database:** users table (default), sessions table, password_reset_tokens table
- **Security:** CSRF, rate limiting, password hashing, session security
- **Verification:** Can log in, log out, reset password, remember me works
- **Complexity:** Medium

### Batch 6: Business Context & Tenancy
- **Objective:** Multi-business support foundation
- **Features:** Business model, BusinessContext resolution, EnsureBusinessSelected middleware, business scoping trait, business switcher
- **Dependencies:** Batch 5
- **Files/Domains:** Business model, BusinessContext service, BelongsToBusiness trait, EnsureBusinessSelected middleware, BusinessController, migration
- **Database:** businesses table, business_memberships table
- **Security:** Tenant isolation, business_id server-side resolution, IDOR protection
- **Verification:** User can create business, switch between businesses, all queries scoped
- **Complexity:** Large

### Batch 7: Roles & Permissions
- **Objective:** Role-based access control
- **Features:** Roles, permissions, role-permission assignments, user-role assignment, Gate/Policy infrastructure, permission middleware
- **Dependencies:** Batch 6
- **Files/Domains:** Role model, Permission model, RolePermission pivot, role seeder, permission seeder, Gate definitions, AuthServiceProvider
- **Database:** roles table, permissions table, role_permissions table
- **Security:** Server-side permission enforcement, role management authorization
- **Verification:** Can assign roles, permissions enforced on routes and UI, unauthorized access blocked
- **Complexity:** Medium

### Batch 8: Module System
- **Objective:** Module registry and activation system
- **Features:** Module configuration, ModuleRegistry service, module activation/deactivation, dependency enforcement, module middleware, navigation renderer wiring
- **Dependencies:** Batch 6
- **Files/Domains:** config/modules.php, ModuleRegistry service, business_modules migration, module middleware, navigation blade component
- **Database:** business_modules table
- **Security:** Module-level route protection
- **Verification:** Can enable/disable modules, dependencies enforced, navigation updates, routes blocked for disabled modules
- **Complexity:** Medium

### Batch 9: Settings System
- **Objective:** Business settings infrastructure
- **Features:** Settings model, SettingsService with cache, settings CRUD, settings API, settings admin page
- **Dependencies:** Batch 6
- **Files/Domains:** Setting model, SettingsService, SettingsController, settings migration, settings views
- **Database:** settings table, system_settings table
- **Security:** Settings management restricted to admin/owner roles
- **Verification:** Settings saved, cached, invalidated on update, settings page functional
- **Complexity:** Medium

---

## Phase 2 — Core Business (Batches 10-21)

### Batch 10: Customers
- **Objective:** Customer management module
- **Features:** Customer CRUD, list with search/filter/sort, detail page, customer code auto-generation, soft deletes
- **Dependencies:** Batches 8, 9, 3
- **Files/Domains:** CustomerController, CustomerRequest, CustomerPolicy, CustomerService, Customer model, customer views, customer module config
- **Database:** customers table, custom_fields + custom_field_values tables
- **Security:** Business isolation, CRUD permissions, IDOR protection
- **Verification:** Full CRUD works, search/filter, pagination, business isolation verified
- **Complexity:** Medium

### Batch 11: Reference Data — Categories, Units, Taxes
- **Objective:** Reference data management
- **Features:** Category CRUD (product + expense types), Unit CRUD, Tax CRUD, dropdowns for all
- **Dependencies:** Batches 8, 3
- **Files/Domains:** CategoryController, UnitController, TaxController, models, migrations, views
- **Database:** categories, units, taxes tables
- **Security:** Business isolation, CRUD permissions
- **Verification:** CRUD for all three, tax configuration (enabled/disabled, rate, inclusive/exclusive)
- **Complexity:** Small

### Batch 12: Products & Services
- **Objective:** Product and service catalog (Marketplace V1)
- **Features:** Product CRUD, SKU auto-generation, image upload, product types (product, service), category/unit assignment, pricing, tax assignment, track_stock flag reserved for Phase 4. No product variants in V1.
- **Dependencies:** Batches 10, 11
- **Files/Domains:** ProductController, ProductRequest, ProductService, Product model, product views, product module config
- **Database:** products table (product_variants / product_variant_values tables designed but NOT implemented until Phase 4)
- **Security:** Business isolation, CRUD permissions, file upload validation
- **Verification:** Full CRUD, image upload, product types (physical product, service) work
- **Complexity:** Medium

### Batch 13: Document Numbering
- **Objective:** Centralized document number generation
- **Features:** NumberingService, configurable prefixes, year-based sequences, concurrency-safe generation, settings for each document type (invoice, quotation, etc.)
- **Dependencies:** Batch 9
- **Files/Domains:** NumberingService, document_numbering migration, numbering settings
- **Database:** document_numbering table
- **Security:** Concurrency handling
- **Verification:** Numbers generated correctly, no duplicates under concurrent access, configurable prefixes
- **Complexity:** Medium

### Batch 14: Quotations
- **Objective:** Quotation/estimate management
- **Features:** Quotation CRUD, line items, tax/discount calculation, total calculation, status workflow (draft → sent → accepted/rejected/expired → converted), quotation number, print-ready HTML document structure. NO PDF yet — PDF arrives via the centralized document service in Phase 3.
- **Dependencies:** Batches 10, 12, 13
- **Files/Domains:** QuotationController, QuotationRequest, QuotationService, models, views, quotation module config
- **Database:** quotations table, quotation_items table
- **Security:** Business isolation, CRUD permissions, calculation integrity
- **Accounting compatibility:** quotation carries currency/exchange-rate/totals fields usable by future posting; conversion link to invoice stored as `converted_to_invoice_id`
- **Verification:** Create/edit quotations, calculations correct, status transitions, print-ready HTML renders
- **Complexity:** Large

### Batch 15: Invoices
- **Objective:** Invoice management (core revenue module, Marketplace V1)
- **Features:** Invoice CRUD, line items, tax/discount calculations, status lifecycle (draft → sent → paid → overdue → cancelled), invoice number, duplicate, print-ready HTML document structure, amount fields (amount_paid / amount_due) DESIGNED but populated only by the Payment module. NO partial payment/allocation logic here.
- **Dependencies:** Batches 10, 12, 13
- **Files/Domains:** InvoiceController, InvoiceRequest, InvoiceService, models, views, invoice module config
- **Database:** invoices table, invoice_items table
- **Security:** Business isolation, CRUD permissions, calculation integrity, financial record safety
- **Accounting compatibility:** immutable finalized documents, status rules, currency + exchange rate columns, cancellation (cancelled_at/cancelled_by/reason) without deletion, source references for future posting
- **Verification:** Full CRUD, calculations correct, lifecycle transitions, print-ready HTML renders; payment-specific behavior NOT present
- **Complexity:** Large

### Batch 16: Payments
- **Objective:** Payment recording and allocation (sits on top of the Invoice module)
- **Features:** Payment CRUD, payment allocation to invoices, partial and full payments, payment number generation, invoice amount_paid/amount_due synchronization, Paid/Partially Paid status synchronization, multiple payment methods, payment reversal
- **Dependencies:** Batch 15
- **Files/Domains:** PaymentController, PaymentRequest, PaymentService, models, views, payment module config
- **Database:** payments table, payment_allocations table
- **Security:** Business isolation, financial record safety, reversal authorization
- **Accounting compatibility:** payment carries currency/exchange-rate/base_amount and polymorphic payable reference for future posting; reversal is a reversal record, never a delete
- **Verification:** Record payments, partial payments, full payments, invoice balances and statuses update, reversal works
- **Complexity:** Large

### Batch 17: Expenses
- **Objective:** Expense tracking
- **Features:** Expense CRUD, expense categories, expense number generation, receipt upload, date filtering, expense reports
- **Dependencies:** Batch 11
- **Files/Domains:** ExpenseController, ExpenseRequest, models, views, expense module config
- **Database:** expenses table
- **Security:** Business isolation, CRUD permissions, file upload validation
- **Accounting compatibility:** expense carries currency/exchange-rate/totals and category/account mapping hooks for future posting
- **Verification:** Full CRUD, receipt upload, expense reports, category filtering
- **Complexity:** Medium

### Batch 18: Customer Ledger & Statements
- **Objective:** Customer balance tracking and statements (Marketplace V1)
- **Features:** Customer balance computation, customer ledger (transaction list of invoices and payments), customer statement (print-ready HTML), opening balance support. Supplier ledger is NOT implemented here — it ships with the Supplier/Purchase phase.
- **Dependencies:** Batches 15, 16
- **Files/Domains:** LedgerService, customer ledger views, customer statement templates
- **Database:** No new tables (uses existing)
- **Security:** Business isolation
- **Verification:** Balances correct, ledger shows all transactions, statement accurate
- **Complexity:** Medium

### Batch 19: Multi-Currency
- **Objective:** Multi-currency transaction support (Marketplace V1)
- **Features:** Base currency per business, transaction currency selection, exchange rate management, historical exchange rates attached to transactions, base currency equivalent (base_amount), currency formatting
- **Dependencies:** Batches 15, 16
- **Files/Domains:** CurrencyService, exchange rate views, currency settings, migration
- **Database:** currencies table, exchange_rates table
- **Security:** Historical rates preserved; never recalculate past transactions with current rates
- **Verification:** Create invoice in foreign currency, rate preserved on document, base amount correct, report totals in base currency
- **Complexity:** Medium

### Batch 20: Import (Customers & Products)
- **Objective:** CSV import for entities available at this stage
- **Features:** CSV upload, template download, validation, preview, import execution, error reporting. Customers and products only. Supplier import arrives with the Supplier module in Phase 4.
- **Dependencies:** Batches 10, 12
- **Files/Domains:** ImportController, ImportService, import views, import jobs
- **Database:** No new tables
- **Security:** File validation, business isolation, queue for large imports
- **Verification:** Import customers/products from CSV, errors reported correctly
- **Complexity:** Medium

### Batch 21: Export
- **Objective:** Data export for all modules currently available
- **Features:** CSV export for customers, products, invoices, expenses, reports; export with filters; export authorization
- **Dependencies:** Batches 10, 12, 15, 17
- **Files/Domains:** ExportController, export jobs
- **Database:** No new tables
- **Security:** Business isolation, permission checks, filter-scoped exports
- **Verification:** Export CSV with correct data, respects filters and permissions
- **Complexity:** Small

---

## Phase 3 — Commercial Polish (Batches 22-28)

### Batch 22: Dashboard
- **Objective:** Configurable dashboard with widgets
- **Features:** Revenue widget, sales widget, expense widget, receivables widget, top customers, recent activity, date range filtering, widget visibility based on enabled modules
- **Dependencies:** Batches 15, 16, 17
- **Files/Domains:** DashboardController, dashboard widgets, dashboard views
- **Database:** No new tables (aggregated queries)
- **Security:** Business isolation on all queries
- **Verification:** Dashboard loads fast, widgets show correct data, responsive
- **Complexity:** Medium

### Batch 23: Core Reports
- **Objective:** Core reporting system (Marketplace V1)
- **Features:** Sales summary report, sales by product, sales by customer, expense reports, receivable report, date range filtering, filter bar component, CSV export from reports
- **Dependencies:** Batches 15, 16, 17, 22
- **Files/Domains:** ReportController, report views, report service
- **Database:** No new tables (aggregated queries)
- **Security:** Business isolation, report permissions
- **Verification:** Reports show correct data, filtering works, export works
- **Complexity:** Medium

### Batch 24: Document Theme Architecture & Invoice Themes
- **Objective:** Safe, centralized theme system shared by all printed/PDF documents
- **Features:** Safe document-theme architecture (config + settings driven; NO executable Blade stored in database), 2 invoice themes (Modern, Minimal), theme selection, logo upload, accent color, header/footer customization, terms template, bank details, signature line
- **Dependencies:** Batch 15
- **Files/Domains:** Document theme registry, invoice theme blade templates, theme settings
- **Database:** No new tables (settings)
- **Security:** N/A — themes are static view files selected by config, not user-supplied templates
- **Verification:** Invoice renders in selected theme, customization persists, theme switching works
- **Complexity:** Medium

### Batch 25: PDF Generation Service & Print
- **Objective:** One centralized PDF/print service for all document types
- **Features:** Central PDF service rendering invoices, quotations, customer statements (receipts added with POS) through the shared document-theme architecture; proper formatting, logo embedding, multi-currency display; print helper
- **Dependencies:** Batch 24
- **Files/Domains:** PDF service, Dompdf/Snappy configuration, document render controller
- **Database:** No new tables
- **Security:** PDF generation on server, no user code execution
- **Verification:** PDFs generate for invoices, quotations, statements; look professional; all themes work; print paths reuse the same document structure
- **Complexity:** Medium

### Batch 26: Localization Completion
- **Objective:** Finalize localization for Marketplace V1
- **Features:** Language selector, RTL validation across all components and layouts, date/number/currency formatting coverage, translation coverage sweep (no hard-coded strings remain), localization QA
- **Dependencies:** Batch 4
- **Files/Domains:** lang/, locale middleware, formatting helpers, RTL check pass
- **Database:** No new tables
- **Security:** N/A
- **Verification:** Locale switching works app-wide, RTL layout correct, zero hard-coded strings remain in Blade/JS
- **Complexity:** Medium

### Batch 27: Installer
- **Objective:** Web-based installation wizard
- **Features:** All installer steps (welcome, requirements, permissions, database, settings, business setup, installation, completion), post-install lock, .env generation
- **Dependencies:** Batches 1, 6
- **Files/Domains:** Installer controllers, installer views, InstallerController, installer middleware
- **Database:** Migrations run during installation
- **Security:** Installer lock, .env protection, APP_KEY generation, no CLI required
- **Verification:** Fresh install works, all steps complete, post-install lock prevents re-install
- **Complexity:** Large

### Batch 28: Demo Mode & Demo Data
- **Objective:** Demo restrictions and sample data
- **Features:** Demo mode flag, centralized restrictions, demo data seeders (realistic data), demo banner, demo data reset capability
- **Dependencies:** Batch 27
- **Files/Domains:** DemoRestriction service, demo seeders, demo middleware
- **Database:** Demo seed data
- **Security:** Centralized demo restrictions, no scattered if-checks
- **Verification:** Demo restrictions work, data looks professional, reset works
- **Complexity:** Medium

> **--- CODECANYON MARKETPLACE V1 RELEASE (after Batch 28) ---**
> Marketplace V1 contains: custom Tailwind design + application shell, authentication, business onboarding, users/roles/permissions, module system, settings, localization foundation, customers, products & services, categories, units, taxes, document numbering, quotations, invoices, payments, expenses, customer ledger, multi-currency, import (customers/products), export, dashboard, core reports, document themes, PDF/print, dark mode, RTL, installer, demo mode and demo data. Finance is delivered as **Finance / Financial Tracking**; the double-entry **Accounting** module ships later. Product variants, suppliers/purchases, inventory/warehouses, POS, accounting, manufacturing, CRM and SaaS are NOT in V1.

---

## Phase 4 — Inventory & Purchasing (Batches 29-37)

### Batch 29: Suppliers
- **Objective:** Supplier management
- **Features:** Supplier CRUD, supplier code, balance tracking fields reserved (filled by ledger batch), opening balance
- **Dependencies:** Batches 3, 13
- **Files/Domains:** SupplierController, SupplierRequest, models, views, supplier module config
- **Database:** suppliers table
- **Security:** Business isolation, CRUD permissions
- **Verification:** Full CRUD works, supplier module can be toggled
- **Complexity:** Medium

### Batch 30: Warehouses
- **Objective:** Multi-warehouse support
- **Features:** Warehouse CRUD, default warehouse, warehouse selection on documents
- **Dependencies:** Batch 3
- **Files/Domains:** WarehouseController, models, views
- **Database:** warehouses table
- **Security:** Business isolation
- **Verification:** Warehouse CRUD, default assignment, appears on documents
- **Complexity:** Small

### Batch 31: Product Variants
- **Objective:** Introduce variants alongside inventory/barcode capabilities
- **Features:** Variant CRUD (size/color/packaging), variant SKU/barcode, variant pricing, stock tracking per variant, integration with inventory, purchasing, POS and manufacturing
- **Dependencies:** Batches 12, 30
- **Files/Domains:** ProductVariantController, VariantService, model relationships, variant views
- **Database:** product_variants table, product_variant_values table (introduced here)
- **Security:** Business isolation, product permissions
- **Verification:** Variants created, priced, and stock-tracked per variant; products with variants work across inventory and sales
- **Complexity:** Medium

### Batch 32: Inventory & Stock
- **Objective:** Stock tracking and management
- **Features:** StockService, stock movements, current stock view, stock history, stock adjustment, low stock alerts, opening stock entry, negative stock configuration, warehouse-level stock
- **Dependencies:** Batches 12, 30, 31
- **Files/Domains:** StockService, StockController, stock movement views, stock report views, stock module config
- **Database:** stock_movements table
- **Security:** Business isolation, stock permission enforcement
- **Verification:** Stock tracked correctly, movements recorded, adjustments work, low stock alerts
- **Complexity:** Large

### Batch 33: Purchases
- **Objective:** Purchase management
- **Features:** Purchase CRUD, purchase orders, goods receiving, stock increase on receipt, purchase number, purchase payments (via Payment module)
- **Dependencies:** Batches 29, 32, 16
- **Files/Domains:** PurchaseController, PurchaseRequest, PurchaseService, models, views, purchase module config
- **Database:** purchases table, purchase_items table
- **Security:** Business isolation, financial record safety
- **Accounting compatibility:** purchase carries currency/exchange-rate/totals and supplier reference for future posting
- **Verification:** Purchase CRUD, stock increases on receipt, payments track
- **Complexity:** Large

### Batch 34: Warehouse Transfers
- **Objective:** Stock transfer between warehouses
- **Features:** Transfer CRUD, transfer workflow (draft → in_transit → received), stock movements for both warehouses
- **Dependencies:** Batches 30, 32
- **Files/Domains:** TransferController, TransferService, models, views
- **Database:** warehouse_transfers table, warehouse_transfer_items table
- **Security:** Business isolation, stock movement integrity
- **Verification:** Transfers work, stock decreases in source, increases in destination
- **Complexity:** Medium

### Batch 35: Returns (Sales & Purchase Returns)
- **Objective:** Return management
- **Features:** Purchase return workflow, sales return workflow, stock reversal, credit note / refund planning, accounting impact preparation
- **Dependencies:** Batches 33, 15
- **Files/Domains:** ReturnController, ReturnService, models, views
- **Database:** returns table, return_items table
- **Security:** Business isolation, stock integrity
- **Verification:** Returns processed correctly, stock reversed, amounts correct
- **Complexity:** Medium

### Batch 36: Supplier Ledger & Statements
- **Objective:** Supplier balance tracking and statements (moved here from earlier phase)
- **Features:** Supplier balance computation, supplier ledger (transaction list of purchases and payments), supplier statement (print via central document service), opening balance support
- **Dependencies:** Batches 29, 33, 16
- **Files/Domains:** SupplierLedgerService, supplier ledger views, supplier statement templates
- **Database:** No new tables (uses existing)
- **Security:** Business isolation
- **Verification:** Balances correct, ledger shows all transactions, statement accurate
- **Complexity:** Medium

### Batch 37: Extended Import — Suppliers
- **Objective:** Supplier CSV import
- **Features:** CSV upload, template download, validation, preview, import execution, error reporting for suppliers
- **Dependencies:** Batches 29, 20
- **Files/Domains:** Extended import controller/service additions, import views
- **Database:** No new tables
- **Security:** File validation, business isolation
- **Verification:** Import suppliers from CSV, errors reported correctly
- **Complexity:** Small

---

## Phase 5 — POS (Batches 38-40)

### Batch 38: POS Screen
- **Objective:** Point of sale interface
- **Features:** Product grid/list, barcode scanner support, product search, category filtering, cart management, quantity adjustment, customer selection, walk-in default
- **Dependencies:** Batches 12, 15, 32
- **Files/Domains:** POSController, POS views (full-screen), POS Alpine.js components
- **Database:** No new tables (uses invoice/payment tables)
- **Security:** Business isolation, POS permissions
- **Verification:** POS screen loads, products display, cart works, responsive
- **Complexity:** Large

### Batch 39: POS Transactions
- **Objective:** POS payment processing
- **Features:** Multiple payment methods, split payment, hold/resume sale, quick payment, invoice generation from POS (reusing Invoice + Payment services — no parallel logic)
- **Dependencies:** Batches 38, 16
- **Files/Domains:** POS payment processing
- **Database:** No new tables
- **Security:** Payment processing integrity
- **Verification:** POS sales complete, payments correct, hold/resume works
- **Complexity:** Medium

### Batch 40: Receipt Printing
- **Objective:** Thermal receipt printing
- **Features:** Receipt template (thermal width) rendered through the central document theme/print service, print button, auto-print option
- **Dependencies:** Batches 39, 25
- **Files/Domains:** Receipt blade template, print CSS
- **Database:** No new tables
- **Security:** N/A
- **Verification:** Receipts print correctly, formatting proper
- **Complexity:** Small

---

## Phase 6 — Accounting (Batches 41-44)

### Batch 41: Chart of Accounts
- **Objective:** Chart of accounts management
- **Features:** Account CRUD, account types, default chart of accounts seeder, account hierarchy, opening balances
- **Dependencies:** Batch 3
- **Files/Domains:** AccountController, AccountService, models, views
- **Database:** accounts table
- **Security:** Business isolation, accounting permissions
- **Verification:** Chart of accounts editable, default accounts seeded, hierarchy works
- **Complexity:** Medium

### Batch 42: Journal Engine
- **Objective:** Double-entry journal system
- **Features:** JournalEntry/JournalLine models, posting service, reversal service, balance validation, source document linking, manual journal entries
- **Dependencies:** Batch 41
- **Files/Domains:** AccountingService, JournalController, models, views
- **Database:** journal_entries table, journal_lines table
- **Security:** Immutability enforcement, balance validation, posting authorization
- **Verification:** Journal entries balanced, reversals work, source linking correct
- **Complexity:** Large

### Batch 43: Accounting Integration
- **Objective:** Auto-posting from business transactions
- **Features:** Invoice posting, payment posting, expense posting, purchase posting, reversal on cancellation — driven by the domain events emitted since Phase 2, requiring no schema redesign of early financial tables
- **Dependencies:** Batches 42, 15, 16, 17, 33
- **Files/Domains:** AccountingService integration, event listeners
- **Database:** No new tables
- **Security:** Posting integrity, double-check balances
- **Verification:** Invoices post correctly, payments post, expenses post, purchases post, reversals correct
- **Complexity:** Large

### Batch 44: Financial Reports
- **Objective:** Accounting reports
- **Features:** General ledger, trial balance, profit & loss, balance sheet, account statement
- **Dependencies:** Batches 42, 43
- **Files/Domains:** Report views, accounting report service
- **Database:** No new tables
- **Security:** Business isolation, accounting permissions
- **Verification:** All reports accurate, balances consistent
- **Complexity:** Large

---

## Phase 7 — Manufacturing (Batches 45-47)

### Batch 45: BOM
- **Objective:** Bill of materials management
- **Features:** BOM CRUD, material list, cost calculation, version tracking
- **Dependencies:** Batches 12, 32
- **Files/Domains:** BomController, models, views
- **Database:** bills_of_materials table, bom_items table
- **Security:** Business isolation
- **Verification:** BOM CRUD works, cost calculated correctly
- **Complexity:** Medium

### Batch 46: Production Orders
- **Objective:** Production order management
- **Features:** Production order CRUD, workflow (draft → planned → in_production → completed → cancelled), material consumption, finished goods output via StockService
- **Dependencies:** Batches 45, 32
- **Files/Domains:** ProductionController, ProductionService, models, views
- **Database:** production_orders table
- **Security:** Business isolation, stock integrity on completion
- **Verification:** Production orders work, materials consumed, finished goods produced
- **Complexity:** Large

### Batch 47: Manufacturing Reports
- **Objective:** Manufacturing-specific reporting
- **Features:** Production summary, material usage, production cost analysis
- **Dependencies:** Batch 46
- **Files/Domains:** Report views
- **Database:** No new tables
- **Security:** Business isolation
- **Verification:** Reports accurate
- **Complexity:** Small

---

## Phase 8 — CRM & Platform Enhancements (Batches 48-51)

### Batch 48: CRM Leads
- **Objective:** Lightweight CRM (post-V1 enhancement)
- **Features:** Lead CRUD, lead stages, lead activities, lead-to-customer conversion, activity tracking
- **Dependencies:** Batch 10
- **Files/Domains:** LeadController, LeadService, models, views, CRM module config
- **Database:** leads table, lead_activities table
- **Security:** Business isolation, CRM permissions
- **Verification:** Lead CRUD, stage management, customer conversion, activities
- **Complexity:** Medium

### Batch 49: Notifications
- **Objective:** In-app notification system
- **Features:** Notification creation, notification center, read/unread, notification triggers (overdue invoice, low stock, payment received)
- **Dependencies:** Batch 6
- **Files/Domains:** Notification model, notification views, event listeners, notification service
- **Database:** notifications table
- **Security:** Business isolation, user-scoped notifications
- **Verification:** Notifications created on events, display correctly, mark as read
- **Complexity:** Medium

### Batch 50: Global Search
- **Objective:** Cross-module search
- **Features:** Search across customers, suppliers, products, invoices, quotations; permission-aware; business-scoped
- **Dependencies:** Batches 10, 12, 14, 15, 29
- **Files/Domains:** SearchController, search service, search views
- **Database:** No new tables
- **Security:** Business isolation, permission filtering
- **Verification:** Search returns relevant results, respects permissions and business scope
- **Complexity:** Medium

### Batch 51: Activity Log
- **Objective:** Audit trail
- **Features:** Log creation for important actions, activity log viewer, old/new values tracking
- **Dependencies:** Batch 6
- **Files/Domains:** ActivityLog model, HasActivityLog trait, log viewer views
- **Database:** activity_logs table
- **Security:** Business isolation, sensitive data exclusion
- **Verification:** Activities logged correctly, viewer shows history
- **Complexity:** Small

---

## Phase 9 — SaaS (Batch 52)

### Batch 52: SaaS Foundation
- **Objective:** SaaS-ready infrastructure (post-V1, kept separate from core enhancements)
- **Features:** Plans, subscriptions, trial management, usage limits (users, modules, branches, warehouses), super admin panel, business management, system-level vs business-level separation
- **Dependencies:** Batches 6, 8 and all prior platform work
- **Files/Domains:** Plan model, Subscription model, super admin routes, limits enforcement
- **Database:** plans table, subscriptions table (or equivalent)
- **Security:** System-level vs business-level separation
- **Verification:** Plans created, subscriptions managed, limits enforced
- **Complexity:** Large

---

## Batch Dependency Summary

```
Phase 1: 2 → 3; 4 (depends on 2); 5 (depends on 2,4)
         6 → 7; 6 → 8 (via 3);  6 → 9
         3 (app shell, depends on 2)
         Parallel-safe sub-chains: (4) and (5); (7), (8), (9) after 6

Phase 2: 10 (depends on 8,9,3)
         11 (depends on 8,3)
         12 (depends on 10,11)
         13 (depends on 9)
         14 (depends on 10,12,13)
         15 (depends on 10,12,13)
         16 (depends on 15)
         17 (depends on 11)
         18 (depends on 15,16)
         19 (depends on 15,16)
         20 (depends on 10,12)
         21 (depends on 10,12,15,17)

Phase 3: 22 (depends on 15,16,17)
         23 (depends on 15,16,17,22)
         24 (depends on 15)
         25 (depends on 24)
         26 (depends on 4)
         27 (depends on 1,6)
         28 (depends on 27)

         --- CODECANYON MARKETPLACE V1 RELEASE (after 28) ---

Phase 4: 29 (depends on 3,13)
         30 (depends on 3)
         31 (depends on 12,30)
         32 (depends on 12,30,31)
         33 (depends on 29,32,16)
         34 (depends on 30,32)
         35 (depends on 33,15)
         36 (depends on 29,33,16)
         37 (depends on 29,20)

Phase 5: 38 (depends on 12,15,32)
         39 (depends on 38,16)
         40 (depends on 39,25)

Phase 6: 41 (depends on 3)
         42 (depends on 41)
         43 (depends on 42,15,16,17,33)
         44 (depends on 42,43)

Phase 7: 45 (depends on 12,32)
         46 (depends on 45,32)
         47 (depends on 46)

Phase 8: 48 (depends on 10)
         49 (depends on 6)
         50 (depends on 10,12,14,15,29)
         51 (depends on 6)

Phase 9: 52 (depends on 6,8 and prior platform work)
```