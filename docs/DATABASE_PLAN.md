# BusinessOS — Database Plan

## Conventions

- **Engine:** InnoDB (MySQL 8+)
- **Charset:** utf8mb4
- **Collation:** utf8mb4_unicode_ci
- **ID Strategy:** BIGINT auto-increment (see DECISIONS.md)
- **Timestamps:** created_at, updated_at on all tables
- **Soft Deletes:** Only on master data tables (see per-table policy)
- **Money Columns:** DECIMAL(16,4) unless otherwise noted
- **Boolean Columns:** tinyint(1) / boolean
- **Status Columns:** VARCHAR with defined enum values (PHP Enums)
- **Naming:** snake_case tables, singular model names, plural table names
- **Foreign Keys:** Referenced table id, with index
- **Business Scope:** business_id on all business-owned tables, indexed

---

## Table Reference

### businesses
Core tenant table. Every business unit.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| name | VARCHAR(255) | Business display name |
| slug | VARCHAR(255) | URL-safe identifier |
| email | VARCHAR(255) | Nullable |
| phone | VARCHAR(50) | Nullable |
| address | TEXT | Nullable |
| city | VARCHAR(100) | Nullable |
| state | VARCHAR(100) | Nullable |
| postal_code | VARCHAR(20) | Nullable |
| country | VARCHAR(2) | ISO 3166-1 alpha-2 |
| website | VARCHAR(255) | Nullable |
| logo | VARCHAR(500) | Nullable, file path |
| tax_number | VARCHAR(50) | Nullable |
| registration_number | VARCHAR(50) | Nullable |
| fiscal_year_start | DATE | Nullable |
| status | VARCHAR(20) | active, suspended, archived |
| settings | JSON | Additional business settings |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |
| deleted_at | TIMESTAMP | Nullable, soft delete |

Indexes: `slug` (unique), `status`

---

### users
System users. A user can belong to multiple businesses.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| name | VARCHAR(255) | |
| email | VARCHAR(255) | Unique, login |
| email_verified_at | TIMESTAMP | Nullable |
| password | VARCHAR(255) | Hashed |
| avatar | VARCHAR(500) | Nullable, file path |
| phone | VARCHAR(50) | Nullable |
| locale | VARCHAR(10) | Default 'en' |
| timezone | VARCHAR(50) | Default 'UTC' |
| is_super_admin | BOOLEAN | System-level admin for SaaS |
| status | VARCHAR(20) | active, inactive, suspended |
| last_login_at | TIMESTAMP | Nullable |
| remember_token | VARCHAR(100) | |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: `email` (unique)

---

### business_memberships
Links users to businesses with roles.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| user_id | BIGINT FK | → users.id |
| role_id | BIGINT FK | → roles.id |
| status | VARCHAR(20) | active, inactive, invited |
| invited_at | TIMESTAMP | Nullable |
| joined_at | TIMESTAMP | Nullable |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: composite (`business_id`, `user_id`) unique, `user_id`

---

### roles
Business-scoped roles.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id (nullable for system roles) |
| name | VARCHAR(100) | |
| slug | VARCHAR(100) | e.g., 'administrator', 'sales' |
| description | VARCHAR(255) | Nullable |
| is_system | BOOLEAN | System roles can't be deleted |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: composite (`business_id`, `slug`) unique

---

### permissions
System-defined permissions. Not business-scoped.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| name | VARCHAR(100) | e.g., 'customers.create' |
| group | VARCHAR(50) | e.g., 'customers', 'invoices' |
| description | VARCHAR(255) | Nullable |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: `name` (unique), `group`

---

### role_permissions
Pivot: roles ↔ permissions.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| role_id | BIGINT FK | → roles.id |
| permission_id | BIGINT FK | → permissions.id |

Indexes: composite (`role_id`, `permission_id`) unique

---

### business_modules
Tracks which modules are enabled per business.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| module_key | VARCHAR(50) | e.g., 'invoices', 'inventory' |
| enabled_at | TIMESTAMP | |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: composite (`business_id`, `module_key`) unique

---

### settings
Business-scoped settings. Also supports system-level settings (business_id = null).

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id, nullable for system settings |
| group | VARCHAR(50) | e.g., 'general', 'tax', 'invoice' |
| key | VARCHAR(100) | e.g., 'tax_rate' |
| value | TEXT | Nullable |
| type | VARCHAR(20) | string, integer, boolean, json, decimal |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: composite (`business_id`, `group`, `key`) unique, `business_id`

---

### currencies
Available currencies. System-level reference table.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| code | VARCHAR(3) | ISO 4217, e.g., 'USD' |
| name | VARCHAR(50) | |
| symbol | VARCHAR(10) | e.g., '$' |
| decimal_places | TINYINT | Default 2 |
| symbol_position | VARCHAR(10) | 'before' or 'after' |
| thousand_separator | VARCHAR(5) | e.g., ',' |
| decimal_separator | VARCHAR(5) | e.g., '.' |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: `code` (unique)

---

### exchange_rates
Historical exchange rates. Business-scoped (or system-level).

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | Nullable for system rates |
| from_currency_id | BIGINT FK | → currencies.id |
| to_currency_id | BIGINT FK | → currencies.id |
| rate | DECIMAL(16,8) | Higher precision for exchange |
| effective_date | DATE | |
| created_at | TIMESTAMP | |

Indexes: composite (`from_currency_id`, `to_currency_id`, `effective_date`), `business_id`

---

### customers
Business-scoped customer records.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| customer_code | VARCHAR(50) | Auto-generated |
| name | VARCHAR(255) | |
| company | VARCHAR(255) | Nullable |
| email | VARCHAR(255) | Nullable |
| phone | VARCHAR(50) | Nullable |
| phone_alternate | VARCHAR(50) | Nullable |
| tax_number | VARCHAR(50) | Nullable |
| billing_address | TEXT | Nullable |
| billing_city | VARCHAR(100) | Nullable |
| billing_state | VARCHAR(100) | Nullable |
| billing_postal_code | VARCHAR(20) | Nullable |
| billing_country | VARCHAR(2) | Nullable, ISO code |
| shipping_address | TEXT | Nullable |
| shipping_city | VARCHAR(100) | Nullable |
| shipping_state | VARCHAR(100) | Nullable |
| shipping_postal_code | VARCHAR(20) | Nullable |
| shipping_country | VARCHAR(2) | Nullable |
| currency_id | BIGINT FK | → currencies.id, nullable (uses business default) |
| credit_limit | DECIMAL(16,4) | Default 0 |
| payment_terms | VARCHAR(50) | e.g., 'net_30', 'net_15', 'due_on_receipt' |
| opening_balance | DECIMAL(16,4) | Default 0 |
| current_balance | DECIMAL(16,4) | Denormalized, updated on payment/invoice |
| notes | TEXT | Nullable |
| status | VARCHAR(20) | active, inactive |
| deleted_at | TIMESTAMP | Nullable, soft delete |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `customer_code`) unique, (`business_id`, `email`), `business_id`, (`business_id`, `status`)

---

### suppliers
Business-scoped supplier records.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| supplier_code | VARCHAR(50) | Auto-generated |
| name | VARCHAR(255) | |
| company | VARCHAR(255) | Nullable |
| email | VARCHAR(255) | Nullable |
| phone | VARCHAR(50) | Nullable |
| tax_number | VARCHAR(50) | Nullable |
| address | TEXT | Nullable |
| city | VARCHAR(100) | Nullable |
| state | VARCHAR(100) | Nullable |
| postal_code | VARCHAR(20) | Nullable |
| country | VARCHAR(2) | Nullable |
| currency_id | BIGINT FK | → currencies.id, nullable |
| payment_terms | VARCHAR(50) | |
| opening_balance | DECIMAL(16,4) | Default 0 |
| current_balance | DECIMAL(16,4) | Denormalized |
| notes | TEXT | Nullable |
| status | VARCHAR(20) | active, inactive |
| deleted_at | TIMESTAMP | Nullable, soft delete |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `supplier_code`) unique, `business_id`

---

### categories
Reusable categories. Polymorphic or type-scoped.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| parent_id | BIGINT FK | → categories.id, nullable (self-referencing) |
| type | VARCHAR(30) | product, expense, customer_group |
| name | VARCHAR(255) | |
| description | VARCHAR(500) | Nullable |
| sort_order | INT | Default 0 |
| status | VARCHAR(20) | active, inactive |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |
| deleted_at | TIMESTAMP | Nullable, soft delete |

Indexes: composite (`business_id`, `type`), `parent_id`

---

### units
Configurable measurement units.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| name | VARCHAR(50) | e.g., 'Piece', 'Kilogram' |
| short_name | VARCHAR(20) | e.g., 'pcs', 'kg' |
| type | VARCHAR(20) | count, weight, length, volume, time |
| base_unit_id | BIGINT FK | → units.id, nullable (for conversion) |
| conversion_factor | DECIMAL(16,8) | How many base units in 1 of this unit |
| status | VARCHAR(20) | active, inactive |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `short_name`)

---

### products
Unified product/service catalog.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| sku | VARCHAR(50) | Nullable, auto-generated if blank |
| barcode | VARCHAR(100) | Nullable |
| name | VARCHAR(255) | |
| description | TEXT | Nullable |
| product_type | VARCHAR(20) | product, service, raw_material, finished_good |
| category_id | BIGINT FK | → categories.id, nullable |
| unit_id | BIGINT FK | → units.id, nullable |
| brand | VARCHAR(100) | Nullable |
| purchase_cost | DECIMAL(16,4) | Default 0 |
| selling_price | DECIMAL(16,4) | Default 0 |
| tax_id | BIGINT FK | → taxes.id, nullable |
| minimum_stock | INT | Default 0 |
| track_stock | BOOLEAN | Default true |
| image | VARCHAR(500) | Nullable, file path |
| status | VARCHAR(20) | active, inactive |
| has_variants | BOOLEAN | Default false | Reserved V1 flag; activated in Phase 4 |
| deleted_at | TIMESTAMP | Nullable, soft delete |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `sku`), (`business_id`, `barcode`), (`business_id`, `product_type`), `business_id`

---

### product_variants
Simple product variants (size, color, etc.). **Phase 4** — implemented together with Inventory & Purchasing; NOT part of the Marketplace V1 catalog (V1 ships Physical Product / Service types only). Table designed now, migration deferred.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| product_id | BIGINT FK | → products.id |
| name | VARCHAR(255) | e.g., "Large / Red" |
| sku | VARCHAR(50) | Nullable |
| barcode | VARCHAR(100) | Nullable |
| purchase_cost | DECIMAL(16,4) | |
| selling_price | DECIMAL(16,4) | |
| stock_quantity | INT | Denormalized current stock |
| status | VARCHAR(20) | active, inactive |
| deleted_at | TIMESTAMP | Nullable |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `product_id`), (`business_id`, `sku`)

---

### product_variant_values
Variant attribute values (e.g., Color: Red, Size: Large). **Phase 4** — implemented together with Inventory & Purchasing; NOT part of the Marketplace V1 catalog.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| product_variant_id | BIGINT FK | → product_variants.id |
| attribute_name | VARCHAR(50) | e.g., 'Color', 'Size' |
| attribute_value | VARCHAR(100) | e.g., 'Red', 'Large' |

Indexes: `product_variant_id`

---

### taxes
Tax rates and configurations.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| name | VARCHAR(100) | e.g., 'VAT 15%' |
| rate | DECIMAL(8,4) | e.g., 15.0000 |
| type | VARCHAR(20) | percentage, fixed |
| is_default | BOOLEAN | Default false |
| is_inclusive | BOOLEAN | Whether default calculation is inclusive |
| status | VARCHAR(20) | active, inactive |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: `business_id`

---

### warehouses
Multi-warehouse support.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| name | VARCHAR(255) | |
| code | VARCHAR(50) | |
| address | TEXT | Nullable |
| phone | VARCHAR(50) | Nullable |
| is_default | BOOLEAN | Default false |
| status | VARCHAR(20) | active, inactive |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `code`) unique, `business_id`

---

### stock_movements
Centralized inventory movement records. NEVER directly modify product stock.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| product_id | BIGINT FK | → products.id |
| product_variant_id | BIGINT FK | → product_variants.id, nullable |
| warehouse_id | BIGINT FK | → warehouses.id |
| type | VARCHAR(30) | See movement types below |
| quantity | INT | Positive = in, Negative = out |
| unit_cost | DECIMAL(16,4) | Cost at time of movement |
| reference_type | VARCHAR(50) | Polymorphic: App\Models\Invoice, etc. |
| reference_id | BIGINT | ID of source document |
| reference_number | VARCHAR(50) | Human-readable reference |
| notes | TEXT | Nullable |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |

Movement Types:
- `opening` — Initial stock
- `purchase` — Goods received
- `purchase_return` — Return to supplier
- `sale` — Sale deduction
- `sale_return` — Customer return
- `transfer_in` — Received from another warehouse
- `transfer_out` — Sent to another warehouse
- `adjustment` — Manual adjustment
- `damage` — Damaged/lost stock
- `production_consumption` — Used in production
- `production_output` — Finished goods produced

Indexes: (`business_id`, `product_id`), (`business_id`, `warehouse_id`), (`business_id`, `type`), `reference_type` + `reference_id` (composite), `business_id` + `created_at` (composite)

---

### warehouses
(Documented above)

---

### warehouse_transfers
Transfer records between warehouses.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| transfer_number | VARCHAR(50) | Auto-generated |
| from_warehouse_id | BIGINT FK | → warehouses.id |
| to_warehouse_id | BIGINT FK | → warehouses.id |
| status | VARCHAR(20) | draft, in_transit, received, cancelled |
| notes | TEXT | Nullable |
| created_by | BIGINT FK | → users.id |
| received_by | BIGINT FK | → users.id, nullable |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: `business_id`, `transfer_number` unique within business

---

### warehouse_transfer_items
Items in a warehouse transfer.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| warehouse_transfer_id | BIGINT FK | → warehouse_transfers.id |
| product_id | BIGINT FK | → products.id |
| quantity | INT | |
| received_quantity | INT | Nullable, set on receive |

Indexes: `warehouse_transfer_id`

---

### quotations
Quotation/estimate documents.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| quotation_number | VARCHAR(50) | Auto-generated |
| customer_id | BIGINT FK | → customers.id |
| currency_id | BIGINT FK | → currencies.id |
| exchange_rate | DECIMAL(16,8) | Default 1 |
| date | DATE | |
| expiry_date | DATE | Nullable |
| status | VARCHAR(20) | draft, sent, accepted, rejected, expired, converted |
| subtotal | DECIMAL(16,4) | |
| discount_type | VARCHAR(10) | percentage, fixed |
| discount_amount | DECIMAL(16,4) | Default 0 |
| tax_amount | DECIMAL(16,4) | Default 0 |
| additional_charges | DECIMAL(16,4) | Default 0 |
| total | DECIMAL(16,4) | |
| notes | TEXT | Nullable |
| terms | TEXT | Nullable |
| converted_to_invoice_id | BIGINT FK | → invoices.id, nullable |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |
| deleted_at | TIMESTAMP | Nullable |

Indexes: (`business_id`, `quotation_number`) unique, (`business_id`, `customer_id`), (`business_id`, `status`), `business_id`

---

### quotation_items
Line items for quotations.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| quotation_id | BIGINT FK | → quotations.id |
| product_id | BIGINT FK | → products.id, nullable (services may not have product) |
| description | VARCHAR(500) | |
| quantity | DECIMAL(16,4) | |
| unit_price | DECIMAL(16,4) | |
| discount_type | VARCHAR(10) | percentage, fixed |
| discount_amount | DECIMAL(16,4) | Default 0 |
| tax_id | BIGINT FK | → taxes.id, nullable |
| tax_amount | DECIMAL(16,4) | Default 0 |
| total | DECIMAL(16,4) | Line total after discount + tax |
| sort_order | INT | Default 0 |

Indexes: `quotation_id`

---

### invoices
Invoice documents. Core financial document.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| invoice_number | VARCHAR(50) | Auto-generated |
| customer_id | BIGINT FK | → customers.id |
| currency_id | BIGINT FK | → currencies.id |
| exchange_rate | DECIMAL(16,8) | Default 1 |
| invoice_date | DATE | |
| due_date | DATE | |
| status | VARCHAR(20) | draft, sent, partially_paid, paid, overdue, cancelled |
| subtotal | DECIMAL(16,4) | Sum of line totals before discount/tax |
| discount_type | VARCHAR(10) | percentage, fixed |
| discount_amount | DECIMAL(16,4) | |
| tax_amount | DECIMAL(16,4) | |
| additional_charges | DECIMAL(16,4) | Shipping, handling, etc. |
| total | DECIMAL(16,4) | Final invoice total |
| amount_paid | DECIMAL(16,4) | Denormalized, sum of payments |
| amount_due | DECIMAL(16,4) | Denormalized, total - amount_paid |
| notes | TEXT | Nullable |
| terms | TEXT | Nullable |
| internal_note | TEXT | Nullable, not printed |
| reference | VARCHAR(100) | Nullable, customer PO number |
| cancelled_at | TIMESTAMP | Nullable |
| cancelled_by | BIGINT FK | → users.id, nullable |
| cancelled_reason | TEXT | Nullable |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Constraints: amount_paid + amount_due = total (application-level)
Indexes: (`business_id`, `invoice_number`) unique, (`business_id`, `customer_id`), (`business_id`, `status`), (`business_id`, `invoice_date`), (`business_id`, `due_date`)

---

### invoice_items
Line items for invoices.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| invoice_id | BIGINT FK | → invoices.id |
| product_id | BIGINT FK | → products.id, nullable |
| description | VARCHAR(500) | |
| quantity | DECIMAL(16,4) | |
| unit_price | DECIMAL(16,4) | |
| cost_price | DECIMAL(16,4) | For margin tracking, nullable |
| discount_type | VARCHAR(10) | percentage, fixed |
| discount_amount | DECIMAL(16,4) | Default 0 |
| tax_id | BIGINT FK | → taxes.id, nullable |
| tax_amount | DECIMAL(16,4) | Default 0 |
| total | DECIMAL(16,4) | Line total |
| sort_order | INT | Default 0 |

Indexes: `invoice_id`, `product_id`

---

### payments
Centralized payment records. Polymorphic context.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| payment_number | VARCHAR(50) | Auto-generated |
| paymentable_type | VARCHAR(50) | Polymorphic: Invoice, Purchase, etc. |
| paymentable_id | BIGINT | |
| party_type | VARCHAR(20) | customer, supplier |
| party_id | BIGINT | Customer or Supplier id |
| amount | DECIMAL(16,4) | |
| currency_id | BIGINT FK | → currencies.id |
| exchange_rate | DECIMAL(16,8) | Default 1 |
| base_amount | DECIMAL(16,4) | Amount in base currency |
| payment_method | VARCHAR(30) | cash, bank_transfer, card, cheque, mobile, other |
| payment_date | DATE | |
| reference | VARCHAR(100) | Nullable, transaction reference |
| account_number | VARCHAR(100) | Nullable, bank account |
| notes | TEXT | Nullable |
| attachment | VARCHAR(500) | Nullable |
| is_reconciled | BOOLEAN | Default false |
| reversed_at | TIMESTAMP | Nullable |
| reversed_by | BIGINT FK | → users.id, nullable |
| reversal_reason | TEXT | Nullable |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `payment_number`) unique, (`business_id`, `party_type`, `party_id`), polymorphic index on paymentable, (`business_id`, `payment_date`), `business_id`

---

### payment_allocations
Maps payments to invoices (supports partial payments).

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| payment_id | BIGINT FK | → payments.id |
| invoice_id | BIGINT FK | → invoices.id |
| amount | DECIMAL(16,4) | Amount allocated |
| created_at | TIMESTAMP | |

Indexes: `payment_id`, `invoice_id`

---

### expenses
Business expense records.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| expense_number | VARCHAR(50) | Auto-generated |
| category_id | BIGINT FK | → categories.id, nullable |
| supplier_id | BIGINT FK | → suppliers.id, nullable |
| date | DATE | |
| amount | DECIMAL(16,4) | |
| tax_amount | DECIMAL(16,4) | Default 0 |
| total_amount | DECIMAL(16,4) | amount + tax_amount |
| payment_method | VARCHAR(30) | cash, bank_transfer, card, etc. |
| reference | VARCHAR(100) | Nullable |
| notes | TEXT | Nullable |
| receipt | VARCHAR(500) | Nullable, file path |
| status | VARCHAR(20) | draft, approved, paid |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `expense_number`) unique, (`business_id`, `date`), (`business_id`, `category_id`), `business_id`

---

### accounts (Chart of Accounts)
Accounting accounts. System-level with business scope for Chart of Accounts.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| code | VARCHAR(20) | e.g., '1000', '1100' |
| name | VARCHAR(255) | e.g., 'Cash', 'Accounts Receivable' |
| type | VARCHAR(20) | asset, liability, equity, revenue, expense |
| sub_type | VARCHAR(30) | current_asset, fixed_asset, etc. |
| parent_id | BIGINT FK | → accounts.id, nullable |
| description | VARCHAR(500) | Nullable |
| is_system | BOOLEAN | System accounts can't be deleted |
| is_active | BOOLEAN | Default true |
| opening_balance | DECIMAL(16,4) | Default 0 |
| current_balance | DECIMAL(16,4) | Denormalized |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `code`) unique, (`business_id`, `type`), `parent_id`

---

### journal_entries
Double-entry accounting journal entries.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| entry_number | VARCHAR(50) | Auto-generated |
| date | DATE | |
| description | VARCHAR(500) | |
| reference_type | VARCHAR(50) | Source document type |
| reference_id | BIGINT | Source document ID |
| reference_number | VARCHAR(50) | Human-readable reference |
| is_reversed | BOOLEAN | Default false |
| reversed_by_entry_id | BIGINT FK | → journal_entries.id, nullable |
| posted_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Constraints: Total debits must equal total credits (application-level)
Indexes: (`business_id`, `entry_number`) unique, (`business_id`, `date`), polymorphic reference, `business_id`

---

### journal_lines
Individual debit/credit lines within a journal entry.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| journal_entry_id | BIGINT FK | → journal_entries.id |
| account_id | BIGINT FK | → accounts.id |
| debit | DECIMAL(16,4) | Default 0 |
| credit | DECIMAL(16,4) | Default 0 |
| description | VARCHAR(500) | Nullable |
| party_type | VARCHAR(20) | Nullable: customer, supplier |
| party_id | BIGINT | Nullable |

Constraints: debit XOR credit (one must be non-zero, not both)
Indexes: `journal_entry_id`, `account_id`

---

### leads (CRM)
Potential customers / sales leads.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| name | VARCHAR(255) | |
| company | VARCHAR(255) | Nullable |
| email | VARCHAR(255) | Nullable |
| phone | VARCHAR(50) | Nullable |
| source | VARCHAR(50) | Nullable: referral, website, cold_call, etc. |
| stage | VARCHAR(30) | new, contacted, qualified, proposal, negotiation, won, lost |
| value | DECIMAL(16,4) | Estimated deal value |
| notes | TEXT | Nullable |
| assigned_to | BIGINT FK | → users.id, nullable |
| converted_customer_id | BIGINT FK | → customers.id, nullable |
| converted_at | TIMESTAMP | Nullable |
| lost_reason | VARCHAR(500) | Nullable |
| status | VARCHAR(20) | active, archived |
| deleted_at | TIMESTAMP | Nullable |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `stage`), (`business_id`, `assigned_to`), `business_id`

---

### lead_activities
CRM activities (calls, emails, meetings, tasks).

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| lead_id | BIGINT FK | → leads.id, nullable |
| customer_id | BIGINT FK | → customers.id, nullable |
| type | VARCHAR(20) | call, email, meeting, task, note |
| subject | VARCHAR(255) | |
| description | TEXT | Nullable |
| due_date | DATETIME | Nullable, for tasks |
| completed_at | TIMESTAMP | Nullable |
| completed_by | BIGINT FK | → users.id, nullable |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `lead_id`), (`business_id`, `customer_id`), (`business_id`, `type`)

---

### purchases
Purchase orders / purchase records. Phase 4.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| purchase_number | VARCHAR(50) | Auto-generated |
| supplier_id | BIGINT FK | → suppliers.id |
| warehouse_id | BIGINT FK | → warehouses.id |
| currency_id | BIGINT FK | → currencies.id |
| exchange_rate | DECIMAL(16,8) | Default 1 |
| purchase_date | DATE | |
| status | VARCHAR(20) | draft, ordered, received, partially_received, cancelled |
| subtotal | DECIMAL(16,4) | |
| discount_type | VARCHAR(10) | |
| discount_amount | DECIMAL(16,4) | |
| tax_amount | DECIMAL(16,4) | |
| total | DECIMAL(16,4) | |
| amount_paid | DECIMAL(16,4) | |
| amount_due | DECIMAL(16,4) | |
| notes | TEXT | Nullable |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `purchase_number`) unique, (`business_id`, `supplier_id`), (`business_id`, `status`), `business_id`

---

### purchase_items
Line items for purchases.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| purchase_id | BIGINT FK | → purchases.id |
| product_id | BIGINT FK | → products.id |
| description | VARCHAR(500) | |
| quantity | DECIMAL(16,4) | |
| received_quantity | DECIMAL(16,4) | |
| unit_cost | DECIMAL(16,4) | |
| discount_amount | DECIMAL(16,4) | |
| tax_amount | DECIMAL(16,4) | |
| total | DECIMAL(16,4) | |
| sort_order | INT | |

Indexes: `purchase_id`

---

### returns (Sales Returns / Purchase Returns)
Return records.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| return_number | VARCHAR(50) | Auto-generated |
| type | VARCHAR(20) | sale_return, purchase_return |
| reference_type | VARCHAR(50) | Invoice or Purchase |
| reference_id | BIGINT | |
| party_type | VARCHAR(20) | customer, supplier |
| party_id | BIGINT | |
| warehouse_id | BIGINT FK | → warehouses.id |
| date | DATE | |
| reason | TEXT | Nullable |
| total | DECIMAL(16,4) | |
| status | VARCHAR(20) | pending, completed, cancelled |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `return_number`) unique, polymorphic reference, `business_id`

---

### return_items
Items in a return.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| return_id | BIGINT FK | → returns.id |
| product_id | BIGINT FK | → products.id |
| quantity | DECIMAL(16,4) | |
| unit_cost | DECIMAL(16,4) | |
| total | DECIMAL(16,4) | |

Indexes: `return_id`

---

### bills_of_materials (BOM)
Bill of materials for manufacturing. Phase 7.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| name | VARCHAR(255) | |
| product_id | BIGINT FK | → products.id (finished good) |
| description | TEXT | Nullable |
| version | INT | Default 1 |
| estimated_cost | DECIMAL(16,4) | |
| status | VARCHAR(20) | active, inactive |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `product_id`)

---

### bom_items
Materials in a BOM.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| bom_id | BIGINT FK | → bills_of_materials.id |
| product_id | BIGINT FK | → products.id (raw material) |
| quantity | DECIMAL(16,4) | |
| unit_cost | DECIMAL(16,4) | |
| wastage_percent | DECIMAL(5,2) | Default 0 |
| sort_order | INT | |

Indexes: `bom_id`

---

### production_orders
Manufacturing production orders. Phase 7.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| order_number | VARCHAR(50) | Auto-generated |
| bom_id | BIGINT FK | → bills_of_materials.id |
| product_id | BIGINT FK | → products.id (finished good) |
| quantity | DECIMAL(16,4) | |
| warehouse_id | BIGINT FK | → warehouses.id |
| planned_date | DATE | Nullable |
| started_at | TIMESTAMP | Nullable |
| completed_at | TIMESTAMP | Nullable |
| status | VARCHAR(20) | draft, planned, in_production, completed, cancelled |
| notes | TEXT | Nullable |
| created_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `order_number`) unique, (`business_id`, `status`), `business_id`

---

### activity_logs
Audit trail for important actions.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id, nullable for system events |
| user_id | BIGINT FK | → users.id, nullable |
| action | VARCHAR(50) | created, updated, deleted, cancelled, etc. |
| entity_type | VARCHAR(50) | Model class name |
| entity_id | BIGINT | |
| old_values | JSON | Nullable |
| new_values | JSON | Nullable |
| ip_address | VARCHAR(45) | IPv4/IPv6 |
| user_agent | VARCHAR(500) | |
| created_at | TIMESTAMP | |

Indexes: (`business_id`, `entity_type`, `entity_id`), (`business_id`, `user_id`), `created_at`

---

### notifications
In-app notifications.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| user_id | BIGINT FK | → users.id |
| title | VARCHAR(255) | |
| message | TEXT | |
| type | VARCHAR(50) | info, warning, success, error |
| entity_type | VARCHAR(50) | Nullable, related entity |
| entity_id | BIGINT | Nullable |
| read_at | TIMESTAMP | Nullable |
| created_at | TIMESTAMP | |

Indexes: (`business_id`, `user_id`, `read_at`), `user_id`

---

### attachments
File attachments (polymorphic).

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| attachable_type | VARCHAR(50) | Polymorphic |
| attachable_id | BIGINT | |
| filename | VARCHAR(255) | Original filename |
| stored_name | VARCHAR(255) | Hashed/stored filename |
| path | VARCHAR(500) | Storage path |
| mime_type | VARCHAR(100) | |
| size | BIGINT | File size in bytes |
| uploaded_by | BIGINT FK | → users.id |
| created_at | TIMESTAMP | |

Indexes: polymorphic (`attachable_type`, `attachable_id`), `business_id`

---

### document_numbering
Centralized document numbering sequences.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| document_type | VARCHAR(30) | invoice, quotation, purchase, etc. |
| prefix | VARCHAR(20) | e.g., 'INV' |
| next_sequence | INT | Auto-incrementing |
| format | VARCHAR(50) | e.g., '{prefix}-{year}-{sequence}' |
| year | INT | Current year context |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: composite (`business_id`, `document_type`, `year`) unique

---

### custom_fields
Reusable custom field definitions.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| entity_type | VARCHAR(50) | customer, supplier, product, invoice |
| name | VARCHAR(100) | Display name |
| field_type | VARCHAR(20) | text, number, date, select, checkbox, textarea |
| options | JSON | Nullable, for select fields |
| is_required | BOOLEAN | Default false |
| sort_order | INT | Default 0 |
| status | VARCHAR(20) | active, inactive |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: (`business_id`, `entity_type`)

---

### custom_field_values
Stored custom field values.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| business_id | BIGINT FK | → businesses.id |
| custom_field_id | BIGINT FK | → custom_fields.id |
| entity_type | VARCHAR(50) | |
| entity_id | BIGINT | |
| value | TEXT | Nullable |

Indexes: composite (`entity_type`, `entity_id`, `custom_field_id`), `business_id`

---

### system_settings
System-level settings (not business-scoped). Used for installer, SaaS, system config.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT PK | auto-increment |
| key | VARCHAR(100) | Unique |
| value | TEXT | Nullable |
| type | VARCHAR(20) | |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Indexes: `key` (unique)

---

## Money Column Standards

| Context | Column | Precision |
|---------|--------|-----------|
| Unit price | DECIMAL(16,4) | Up to 999,999,999,999.9999 |
| Quantities | DECIMAL(16,4) | Supports fractional quantities |
| Tax rates | DECIMAL(8,4) | Up to 9999.9999% |
| Exchange rates | DECIMAL(16,8) | High precision for FX |
| Subtotals/Totals | DECIMAL(16,4) | |
| Percentages | DECIMAL(5,2) | Up to 999.99% |

---

## Soft Delete Policy

### Soft Deleted (restore possible)
- businesses
- customers
- suppliers
- products
- product_variants
- categories
- units
- warehouses
- leads
- quotations

### Hard Deleted / Status-Only (no soft delete)
- invoices (use cancelled status)
- payments (use reversal)
- expenses (use status)
- journal_entries (use reversal)
- journal_lines
- stock_movements (immutable)
- payment_allocations
- activity_logs
- notifications
- settings
- roles (use status)
- permissions (system only)
