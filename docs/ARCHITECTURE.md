# BusinessOS — Architecture Document

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Language | PHP 8.2+ (prefer 8.3) |
| Framework | Laravel 12.x |
| Database | MySQL 8+ |
| Template | Blade |
| CSS | Tailwind CSS |
| JS Interactivity | Alpine.js |
| JS | Vanilla JavaScript (Fetch API) |
| Build | Vite |
| ORM | Eloquent |

### Avoided Technologies
- React, Vue, Angular, Inertia, Livewire (unless extreme justification)
- AdminLTE, Filament, Nova, CoreUI, Metronic (custom UI only)
- Repository Pattern, CQRS, Event Sourcing (unless real value)

---

## Folder Structure

```
app/
├── Actions/                    # Orchestration layer (thin)
│   ├── Customer/
│   ├── Invoice/
│   ├── Product/
│   └── ...
├── Console/
│   └── Commands/
├── Enums/
│   ├── InvoiceStatus.php
│   ├── PaymentMethod.php
│   ├── StockMovementType.php
│   └── ...
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   │   ├── App/               # Main application controllers
│   │   │   ├── CustomerController.php
│   │   │   ├── InvoiceController.php
│   │   │   ├── ProductController.php
│   │   │   └── ...
│   │   ├── Auth/              # Authentication controllers
│   │   ├── Api/               # Future API controllers
│   │   └── Installer/         # Installation wizard
│   ├── Middleware/
│   │   ├── EnsureBusinessSelected.php
│   │   ├── EnsureModuleActive.php
│   │   ├── PreventInDemoMode.php
│   │   └── ...
│   └── Requests/
│       ├── Customer/
│       ├── Invoice/
│       └── ...
├── Listeners/
├── Mail/
├── Models/
│   ├── Business.php
│   ├── User.php
│   ├── Customer.php
│   ├── Product.php
│   ├── Invoice.php
│   └── ...
├── Observers/
├── Policies/
├── Providers/
├── Services/
│   ├── Currency/
│   │   └── CurrencyService.php
│   ├── DocumentNumbering/
│   │   └── NumberingService.php
│   ├── Invoice/
│   │   └── InvoiceService.php
│   ├── Payment/
│   │   └── PaymentService.php
│   ├── Accounting/
│   │   └── AccountingService.php
│   ├── Inventory/
│   │   └── StockService.php
│   ├── Settings/
│   │   └── SettingsService.php
│   ├── Module/
│   │   └── ModuleRegistry.php
│   └── ...
└── Traits/
    ├── BelongsToBusiness.php
    ├── HasActivityLog.php
    └── ...

config/
├── app.php
├── modules.php               # Module registry configuration
├── business.php              # Business types and defaults
├── numbering.php             # Numbering prefix defaults
└── ...

database/
├── migrations/
│   ├── 0001_01_01_000000_create_businesses_table.php
│   ├── 0001_01_01_000001_create_users_table.php
│   └── ...
├── seeders/
│   ├── DatabaseSeeder.php
│   ├── ModuleSeeder.php
│   ├── RoleSeeder.php
│   ├── DemoSeeder.php
│   └── ...
└── factories/

resources/
├── css/
│   └── app.css
├── js/
│   ├── app.js
│   ├── components/            # Alpine.js components
│   └── utils/
├── views/
│   ├── components/            # Blade components
│   │   ├── button.blade.php
│   │   ├── input.blade.php
│   │   ├── card.blade.php
│   │   ├── table.blade.php
│   │   ├── modal.blade.php
│   │   └── ...
│   ├── layouts/
│   │   ├── app.blade.php     # Main authenticated layout
│   │   ├── auth.blade.php    # Auth pages layout
│   │   ├── installer.blade.php
│   │   └── minimal.blade.php
│   ├── app/                   # Application pages
│   │   ├── customers/
│   │   ├── invoices/
│   │   ├── products/
│   │   └── ...
│   ├── auth/
│   ├── installer/
│   ├── components/            # Reusable complex components
│   └── partials/
└── lang/
    └── en/

routes/
├── web.php                    # Main routes
├── auth.php                   # Auth routes
├── installer.php              # Installer routes
├── api.php                    # Future API routes
└── console.php

Modules/                       # Optional: domain-organized route files
├── customers.php
├── invoices.php
├── products.php
└── ...
```

---

## Architectural Patterns

### Modular Monolith
The application is a single deployable unit organized into logical domains. Each domain contains its own models, controllers, services, requests, and policies.

### Thin Controllers → Service Layer → Eloquent

```
Controller (validation, response)
    → Service (business logic, transactions)
        → Model/Eloquent (data access)
```

Controllers handle HTTP concerns only. Services encapsulate business rules. Eloquent handles data access.

### Action Classes (Orchestration)
For complex operations spanning multiple services, use Action classes:

```php
// Example: Creating an invoice involves
// - Number generation
// - Item calculation
// - Invoice creation
// - Stock reservation (if inventory module active)
// - Journal entry (if accounting module active)
CreateInvoiceAction::execute($validatedData);
```

Action classes are the integration point between modules.

### Service Classes
Each domain area has a focused service class:

- `InvoiceService` — Invoice CRUD, calculations, status transitions
- `PaymentService` — Payment recording, allocation, balance updates
- `StockService` — Stock movements, valuation, adjustments
- `NumberingService` — Document number generation (concurrency-safe)
- `SettingsService` — Settings access with cache layer
- `CurrencyService` — Exchange rates, multi-currency calculations
- `ModuleRegistry` — Module metadata, dependency checking, navigation

---

## Business Context (Tenant Isolation)

### Architecture
Every business-owned table has a `business_id` column. The active business is resolved server-side from the authenticated user's membership.

### BusinessContext Service
A central `BusinessContext` service (or helper) resolves the current business:

```php
Business::current()           // Returns current Business model
Business::currentId()         // Returns current business_id (int)
Business::isCurrent($model)   // Checks model belongs to current business
```

### Middleware
`EnsureBusinessSelected` middleware:
- Verifies user has an active business context
- Resolves business_id from session or user membership
- Attaches business scope to request

### Model Scope
All business-owned models use a global scope:

```php
// In BelongsToBusiness trait
protected static function booted(): void
{
    static::addGlobalScope('business', function ($query) {
        $query->where('business_id', Business::currentId());
    });
}
```

### Security Rules
1. Never trust `business_id` from frontend requests
2. Always resolve server-side from authenticated user
3. Every query on business data automatically scoped
4. Manual scope override only in admin-level operations with explicit authorization
5. Export endpoints validate business ownership
6. File attachments validate business ownership

---

## Module System

### Registry
Modules are defined in `config/modules.php` as a PHP array. Each module entry contains:

```php
return [
    'customers' => [
        'key'         => 'customers',
        'name'        => 'Customers',
        'description' => 'Manage your customer database',
        'icon'        => 'users',
        'category'    => 'core',
        'enabled_by_default' => true,
        'dependencies' => [],
        'permissions' => [
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',
        ],
        'navigation' => [
            'group' => 'Contacts',
            'order' => 1,
        ],
    ],
    // ...
];
```

### Module Activation
- Stored in `business_modules` table (business_id + module_key)
- At boot, resolved and cached per business
- Checked via `Module::isEnabled($key)` or middleware

### Dependency Enforcement
When enabling a module, the system:
1. Checks all dependencies are satisfied
2. Enables required dependencies automatically (with confirmation)
3. Prevents disabling a module if dependent modules are active
4. Shows clear error messages explaining dependencies

### Navigation Rendering
Navigation component reads enabled modules, filters by permissions, groups by category, and renders sidebar/menu.

---

## Settings Architecture

### Storage
Settings stored in `settings` table:

| Column | Purpose |
|--------|---------|
| business_id | Business scope (null for system settings) |
| group | Settings group (general, tax, invoice, etc.) |
| key | Setting identifier |
| value | Setting value (nullable) |
| type | Data type hint (string, integer, boolean, json) |

### Access
```php
Settings::get('tax.rate', 0)                    // With default
Settings::set('tax.rate', 15)                   // Set value
Settings::group('invoice')                       // Get all in group
Settings::all()                                  // All settings for business
```

### Cache
- Settings cached on first access per business
- Cache key: `settings:{business_id}`
- Invalidated on any settings update
- Cache uses `Cache::tags` where supported, falls back to key-based invalidation

### Groups
| Group | Contents |
|-------|----------|
| general | Business name, address, phone, email, logo |
| currency | Base currency, format, decimal places |
| tax | Tax enabled, rate, inclusive/exclusive, label |
| invoice | Invoice prefix, terms, notes, theme, logo |
| quotation | Quotation prefix, validity, terms |
| pos | POS settings, receipt template |
| inventory | Stock tracking, negative stock policy |
| numbering | Document prefixes and sequences |
| modules | Active modules per business |
| email | Mail configuration |
| localization | Locale, timezone, date format |
| security | Session, password requirements |
| backup | Backup settings |

---

## Event System

Use Laravel events for module decoupling:

### Key Events
```php
InvoiceCreated
InvoicePaid
InvoiceCancelled
PaymentReceived
PaymentReversed
CustomerCreated
StockLow
QuotationAccepted
```

### Listeners
Each module registers its own listeners. Example:
- `InvoicePaid` → Update customer balance, record journal entry, send notification
- `StockLow` → Create low-stock alert notification
- `QuotationAccepted` → Enable quotation-to-invoice conversion

### Usage
Events are used sparingly and only where module decoupling provides real value. Not every operation needs an event.

### Accounting Posting Hooks
The financial events (`InvoiceCreated`, `InvoicePaid`, `InvoiceCancelled`, `PaymentReceived`, `PaymentReversed`, `ExpenseRecorded`, `PurchaseReceived`) are the Phase 6 accounting-posting hooks. The journal-posting listener ships with and is registered by the Accounting module only; while inactive, these events simply drive customer balances, notifications, and reporting as built in earlier phases.

---

## Queue System

### Driver
**Primary:** Database driver (shared hosting compatible)
**Optional:** Redis, SQS (documented for enhanced setups)

### Queued Jobs
- Email sending
- Export generation (CSV, Excel, PDF)
- Bulk imports
- Large report generation
- Notification delivery
- Demo data cleanup

### Fallback
For environments without queue workers, jobs are processed synchronously via `ShouldQueue` fallback. Document cron-based queue processing for shared hosting.

---

## Scheduler

```php
// Kernel.php or routes/console.php
Schedule::command('invoices:check-overdue')->daily();
Schedule::command('quotations:expire')->daily();
Schedule::command('demo:reset')->daily();
Schedule::command('backups:cleanup')->weekly();
Schedule::command('cache:clear-expired')->hourly();
```

### Shared Hosting
Provide simple cron setup instructions:
```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

---

## File Storage

### Default
Local filesystem storage in `storage/app/`.

### Configurable
Settings allow changing storage disk (local, S3-compatible).

### Uploaded Files
- Organized by business_id and entity type
- Safe filenames (hash-based)
- MIME and extension validation
- Size limits enforced
- No executable uploads

### Structure
```
storage/app/
├── businesses/
│   ├── {business_id}/
│   │   ├── avatars/
│   │   ├── invoices/
│   │   ├── expenses/
│   │   ├── products/
│   │   └── ...
```

---

## API Extension Strategy

### Current
Blade-based application with AJAX endpoints for interactive features.

### Future API
Architecture permits adding REST API with:
- Versioned routes (`/api/v1/...`)
- Laravel Sanctum authentication
- Form Request validation reuse
- Service layer reuse
- Resource transformers

### AJAX Endpoints
Used within Blade for:
- Search (global search, entity search)
- Inline updates (status changes, quick edits)
- File uploads
- Dynamic form behavior (state/city loading)
- Dashboard widget refresh

---

## Performance Strategy

### Database
- Proper indexes on all frequently queried columns
- Composite indexes for common filter combinations
- Business_id indexed on all multi-tenant tables
- No N+1 queries (eager loading)
- Server-side pagination for all list views
- Aggregated dashboard queries (cached)

### Application
- Settings cached per business
- Module configuration cached
- Selective column selection (no `SELECT *`)
- Chunked processing for bulk operations
- Background jobs for expensive operations

### Frontend
- Vite for asset bundling
- Minimal JS (Alpine.js only)
- Lazy loading where appropriate
- Optimized Blade rendering
- CDN for Tailwind if desired

---

## Testing Strategy

### During Development
- Targeted tests for high-risk areas only
- Business isolation tests
- Permission tests
- Invoice calculation tests
- Stock movement tests
- Number generation tests

### Pre-Release
- Feature tests for all modules
- Integration tests for critical workflows
- Permission audit
- Tenant isolation audit
- Fresh install test
- Upgrade test

### High-Risk Test Areas
1. Tenant isolation (cross-business access)
2. Permission enforcement
3. Invoice calculations (tax, discount, currency)
4. Payment allocation
5. Stock movements and valuation
6. Document number generation (concurrency)
7. Accounting journal entries
8. Module dependency enforcement
