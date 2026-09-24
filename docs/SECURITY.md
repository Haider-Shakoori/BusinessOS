# BusinessOS — Security Design

## Authentication

### Implementation
- Laravel's built-in authentication (Breeze or custom)
- Email + password login
- Password hashing: bcrypt (Laravel default)
- Session-based authentication (not token-based for MVP)
- Rate limiting on login attempts: 5 per minute per IP
- "Remember me" with secure token

### Password Requirements
- Minimum 8 characters
- Enforced at registration and password change
- Stored with bcrypt hashing
- Never logged or stored in plaintext

### Session Security
- Session timeout: configurable (default 24 hours)
- Session regeneration on login
- Session invalidation on password change
- Secure cookie flags (HttpOnly, Secure in production)
- Single session per user (configurable)

### Future: Two-Factor Authentication
- TOTP-based 2FA
- Optional per user
- Recovery codes
- Phase 2+ consideration

---

## Authorization

### Permission Model
Permissions follow `entity.action` pattern:
```
customers.view
customers.create
customers.update
customers.delete
invoices.view
invoices.create
invoices.update
invoices.cancel
invoices.print
payments.view
payments.create
payments.reverse
reports.sales
settings.manage
```

### Enforcement Layers

#### 1. Route-Level (Middleware)
```php
Route::middleware('can:invoices.create')->group(function () {
    Route::get('/invoices/create', [InvoiceController::class, 'create']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
});
```

#### 2. Controller-Level (Authorization)
```php
public function destroy(Invoice $invoice)
{
    $this->authorize('delete', $invoice);
    // ...
}
```

#### 3. Blade-Level (UI Hiding)
```html
@if($user->can('invoices.create'))
    <a href="{{ route('invoices.create') }}">Create Invoice</a>
@endif
```

#### 4. Policy-Level (Business Logic)
```php
class InvoicePolicy
{
    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.delete', $invoice->business)
            && $invoice->status === 'draft';
    }
}
```

### Role System
- Business-scoped roles
- Each role has a set of permissions
- Users are assigned roles via business_memberships
- A user can have one role per business
- System roles (owner, admin) have special handling

---

## Tenant Isolation (Critical)

### Architecture
Every business-owned table has `business_id`. All queries are automatically scoped.

### BusinessContext Resolution
```php
// Resolved server-side from authenticated user's membership
// NEVER from request input
$businessId = auth()->user()->currentBusinessId;
```

### Global Scope
```php
// In BelongsToBusiness trait
protected static function booted(): void
{
    static::addGlobalScope('business', function ($query) {
        $query->where('business_id', auth()->user()->currentBusinessId);
    });
}
```

### Protected Against

| Attack Vector | Protection |
|---------------|------------|
| URL manipulation | Global scope on model; business_id from session |
| IDOR | Every query auto-scoped; can't access other business's records |
| Cross-business search | Search queries auto-scoped |
| Cross-business exports | Export queries auto-scoped |
| Cross-business attachments | File path includes business_id; validated on access |
| Cross-business reports | Report queries auto-scoped |
| API manipulation | Business context resolved server-side |

### Test Requirements
Every business-owned endpoint must have tests verifying:
1. User cannot read records from another business
2. User cannot create records in another business
3. User cannot update records in another business
4. User cannot delete records from another business
5. User cannot export records from another business
6. User cannot access attachments from another business

---

## CSRF Protection

- Laravel's built-in CSRF token verification on all POST/PUT/PATCH/DELETE requests
- CSRF meta tag in all Blade layouts
- Token included in AJAX requests via header

---

## XSS Protection

- Blade's `{{ }}` escaping on all output
- Never use `{!! !!}` without explicit sanitization
- Rich text content (if any) sanitized before storage
- File upload: validate MIME type, never execute uploaded files
- Content Security Policy headers (configurable)

---

## SQL Injection Protection

- Eloquent ORM parameterized queries (automatic)
- Query Builder parameterized queries (automatic)
- Never use raw SQL with string concatenation
- Validate all input before database operations

---

## Mass Assignment Protection

### Model $fillable
Every model explicitly defines fillable attributes:
```php
protected $fillable = [
    'business_id',
    'name',
    'email',
    // ... specific fields only
];
```

### Form Requests
All input validated through Form Request classes. Never pass raw request data to model creation.

### Guard Columns
These columns are NEVER in $fillable and never set from frontend:
- `business_id` — Set from BusinessContext
- `created_by` — Set from auth()->id()
- `updated_by` — Set from auth()->id()
- `created_at` — Managed by Eloquent
- `updated_at` — Managed by Eloquent

---

## File Security

### Upload Validation
```php
// In Form Request
'file' => [
    'required',
    'file',
    'max:10240', // 10MB
    'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,csv,txt',
]
```

### Storage Rules
- Files stored in `storage/app/businesses/{business_id}/`
- Safe filenames: hash-based, no user-controlled names in path
- No PHP files stored in upload directories
- Web server configured to deny execution in upload directories
- MIME type validated on upload AND on access

### Access Control
- Files only accessible to authenticated users of the owning business
- Direct file access blocked (served through controller)
- File download headers set correctly

### Disallowed Uploads
- PHP files (.php, .php3, .php4, .php5, .phtml)
- Executables (.exe, .bat, .cmd, .sh)
- Scripts (.js, .vbs, .ps1)
- Archive files containing executables

---

## Export Security

### Authorization
- Export requires relevant module permission
- Export queries are business-scoped
- User cannot export data from other businesses

### Implementation
```php
public function export(Request $request)
{
    $this->authorize('customers.view');

    $customers = Customer::query()
        ->where('business_id', auth()->user()->currentBusinessId) // Explicit scope
        ->applyFilters($request)
        ->get();

    // Generate CSV...
}
```

---

## Rate Limiting

| Endpoint | Limit | Duration |
|----------|-------|----------|
| Login | 5 attempts | 1 minute |
| Password reset | 3 attempts | 1 hour |
| API (future) | 60 requests | 1 minute |
| File upload | 10 requests | 1 minute |
| Export | 5 requests | 1 minute |

---

## Sensitive Data Protection

### Never Stored in Plain Text
- Passwords (bcrypt)
- API keys (encrypted)
- SMTP credentials (encrypted)
- Payment gateway keys (encrypted)

### Never Logged
- Passwords
- API tokens
- Session tokens
- Payment card numbers
- SMTP credentials

### Application Key
- Stored in `.env`
- Used for encryption
- Never exposed to frontend
- Installer protects key generation

### Environment File
- `.env` not accessible via web
- `.env.example` included for reference
- Installer writes `.env` during setup

---

## Demo Mode Security

### Centralized Restriction
A `DemoRestriction` middleware/service checks:
```php
class DemoRestriction
{
    public static function check(string $action): void
    {
        if (!config('app.demo_mode')) return;

        $blocked = [
            'delete_critical_users',
            'change_admin_password',
            'edit_smtp_settings',
            'expose_backups',
            'change_app_key',
            'dangerous_maintenance',
        ];

        if (in_array($action, $blocked)) {
            throw new DemoModeException();
        }
    }
}
```

### Demo Restrictions
1. Cannot delete the main admin user
2. Cannot change admin credentials
3. Cannot modify SMTP/mail settings
4. Cannot access backup functionality
5. Cannot change application key
6. Cannot modify .env settings
7. Data may reset periodically
8. All operations visible to demo users

### Implementation
- NOT scattered `if (demo)` checks
- Central `DemoMode` service/middleware
- Service provider checks config at boot
- Policy methods check demo mode

---

## Session Safety

### Cookie Configuration
```
SESSION_DRIVER=database
SESSION_LIFETIME=1440 (24 hours)
SESSION_ENCRYPT=true
SESSION_HTTPONLY=true
SESSION_SECURE=true (in production)
SESSION_SAMESITE=lax
```

### Session Management
- Session table in database (not file-based for security)
- Session regeneration on privilege escalation
- Session invalidation on logout
- Concurrent session control (configurable)
- Session timeout enforcement

---

## Financial Operation Safety

### Invoice Safety
- Draft invoices can be edited
- Sent/Paid invoices CANNOT be edited (only cancelled)
- Cancellation requires reason and creates audit log
- Payment records are immutable (reversal only)
- Payment amounts validated against invoice balance

### Stock Operation Safety
- Stock movements are immutable (no edit, no delete)
- Corrections via new adjustment movements
- Stock reversal on invoice cancellation
- Concurrency protection on stock deductions

### Accounting Safety
- Journal entries are immutable after posting
- Corrections via reversal entries
- Balance check enforced before posting
- Period lock prevents backdated entries

---

## Middleware Stack

### Application Middleware
```
web session stack + Authenticate + EnsureBusinessSelected + PreventInDemoMode
```

### Permission Middleware
```
can:permission.name
```

### Module Middleware
```
module:invoices  — Ensures invoices module is enabled
```

### Business Scope Middleware
```
EnsureBusinessSelected — Resolves and validates business context
```

---

## Security Checklist (Pre-Release)

- [ ] All routes have authorization (no unprotected routes)
- [ ] All form inputs validated via Form Requests
- [ ] All database queries use parameterized queries
- [ ] All output escaped
- [ ] CSRF tokens on all forms
- [ ] Business isolation on all business-owned queries
- [ ] File uploads validated (MIME, size, extension)
- [ ] No secrets in version control
- [ ] No sensitive data in logs
- [ ] Rate limiting on auth endpoints
- [ ] Password hashing is bcrypt
- [ ] Session security configured
- [ ] Demo mode restrictions centralized
- [ ] Financial records immutable
- [ ] Stock movements immutable
- [ ] Permission enforcement server-side (not just UI)
- [ ] IDOR protection on all endpoints
- [ ] Open redirect protection
- [ ] Mass assignment protection on all models
