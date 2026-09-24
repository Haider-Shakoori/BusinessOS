# BusinessOS — Module Definitions

## Module Registry

Modules are defined in `config/modules.php`. Each module has:

| Property | Description |
|----------|-------------|
| key | Unique identifier (string) |
| name | Display name |
| description | Short description |
| icon | Icon reference (Heroicon or custom) |
| category | Grouping for navigation |
| dependencies | Required modules (array of keys) |
| permissions | Associated permission strings |
| enabled_by_default | Whether enabled for new businesses |
| is_mvp | Whether included in MVP scope |

---

## Module Definitions

### dashboard
- **Name:** Dashboard
- **Category:** Core
- **Dependencies:** None
- **Permissions:** `dashboard.view`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** None directly (reads from other modules)
- **Enable behavior:** Adds dashboard widget configuration
- **Disable behavior:** Hides dashboard; redirects to first available module
- **Integration:** Reads aggregated data from all enabled modules

---

### customers
- **Name:** Customers
- **Category:** Contacts
- **Dependencies:** None
- **Permissions:** `customers.view`, `customers.create`, `customers.update`, `customers.delete`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** customers
- **Enable behavior:** Adds customer management navigation
- **Disable behavior:** Hides customer navigation and routes; historical records preserved
- **Integration:** Invoices, quotations, CRM, reports, accounting

---

### suppliers
- **Name:** Suppliers
- **Category:** Contacts
- **Dependencies:** None
- **Permissions:** `suppliers.view`, `suppliers.create`, `suppliers.update`, `suppliers.delete`
- **Enabled by default:** No
- **MVP:** No (Phase 4)
- **Tables:** suppliers
- **Enable behavior:** Adds supplier management navigation
- **Disable behavior:** Hides supplier navigation; preserves records
- **Integration:** Purchases, expenses, accounting

---

### products
- **Name:** Products & Services
- **Category:** Catalog
- **Dependencies:** None
- **Permissions:** `products.view`, `products.create`, `products.update`, `products.delete`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** products (product_variants / product_variant_values introduced in Phase 4 with Inventory — not part of Marketplace V1)
- **Enable behavior:** Adds product catalog navigation
- **Disable behavior:** Hides product navigation; preserves records
- **Integration:** Invoices, quotations, inventory, POS, manufacturing

---

### categories
- **Name:** Categories
- **Category:** Catalog
- **Dependencies:** None
- **Permissions:** `categories.view`, `categories.create`, `categories.update`, `categories.delete`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** categories
- **Enable behavior:** Adds category management
- **Disable behavior:** Hides category management; products retain category references
- **Integration:** Products, expenses

---

### units
- **Name:** Units
- **Category:** Catalog
- **Dependencies:** None
- **Permissions:** `units.view`, `units.create`, `units.update`, `units.delete`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** units
- **Enable behavior:** Adds unit management
- **Disable behavior:** Hides unit management; products retain unit references
- **Integration:** Products, inventory

---

### taxes
- **Name:** Taxes
- **Category:** Finance
- **Dependencies:** None
- **Permissions:** `taxes.view`, `taxes.manage`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** taxes
- **Enable behavior:** Enables tax configuration and tax fields on documents
- **Disable behavior:** Hides tax settings; tax fields on documents are hidden/zero
- **Integration:** Invoices, quotations, purchases, POS, expenses

---

### quotations
- **Name:** Quotations
- **Category:** Sales
- **Dependencies:** customers, products
- **Permissions:** `quotations.view`, `quotations.create`, `quotations.update`, `quotations.delete`, `quotations.print`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** quotations, quotation_items
- **Enable behavior:** Adds quotation management navigation
- **Disable behavior:** Hides quotation navigation; existing quotations preserved
- **Integration:** Customers, products, invoicing (conversion)

---

### invoices
- **Name:** Invoices
- **Category:** Sales
- **Dependencies:** customers, products
- **Permissions:** `invoices.view`, `invoices.create`, `invoices.update`, `invoices.cancel`, `invoices.print`, `invoices.email`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** invoices, invoice_items
- **Enable behavior:** Adds invoice management navigation
- **Disable behavior:** Hides invoice navigation; existing invoices preserved
- **Integration:** Customers, products, payments, accounting, reports, POS

---

### payments
- **Name:** Payments
- **Category:** Finance
- **Dependencies:** invoices
- **Permissions:** `payments.view`, `payments.create`, `payments.reverse`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** payments, payment_allocations
- **Enable behavior:** Enables payment recording against invoices
- **Disable behavior:** Hides payment navigation; existing payments preserved
- **Integration:** Invoices, customers, accounting, reports

---

### expenses
- **Name:** Expenses
- **Category:** Finance
- **Dependencies:** categories
- **Permissions:** `expenses.view`, `expenses.create`, `expenses.update`, `expenses.delete`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** expenses
- **Enable behavior:** Adds expense management navigation
- **Disable behavior:** Hides expense navigation; existing expenses preserved
- **Integration:** Categories, accounting, reports

---

### sales
- **Name:** Sales
- **Category:** Sales
- **Dependencies:** customers, products
- **Permissions:** `sales.view`, `sales.create`, `sales.update`, `sales.cancel`
- **Enabled by default:** No
- **MVP:** No (Phase 4, for wholesale)
- **Tables:** None directly (invoices serve as sales records in MVP)
- **Enable behavior:** Adds sales order management (separate from invoices)
- **Disable behavior:** Hides sales order navigation
- **Integration:** Customers, products, inventory, invoicing

---

### purchases
- **Name:** Purchases
- **Category:** Purchasing
- **Dependencies:** suppliers, products
- **Permissions:** `purchases.view`, `purchases.create`, `purchases.update`, `purchases.cancel`, `purchases.receive`
- **Enabled by default:** No
- **MVP:** No (Phase 4)
- **Tables:** purchases, purchase_items
- **Enable behavior:** Adds purchase management navigation
- **Disable behavior:** Hides purchase navigation; preserves records
- **Integration:** Suppliers, products, inventory, payments, accounting

---

### inventory
- **Name:** Inventory
- **Category:** Inventory
- **Dependencies:** products
- **Permissions:** `inventory.view`, `inventory.adjust`, `inventory.transfer`, `inventory.count`
- **Enabled by default:** No
- **MVP:** No (Phase 4)
- **Tables:** stock_movements, warehouse_transfers, warehouse_transfer_items
- **Enable behavior:** Enables stock tracking on products, adds inventory navigation
- **Disable behavior:** Hides inventory navigation; stock records preserved
- **Integration:** Products, purchases, sales, manufacturing, POS

---

### warehouses
- **Name:** Warehouses
- **Category:** Inventory
- **Dependencies:** None
- **Permissions:** `warehouses.view`, `warehouses.create`, `warehouses.update`, `warehouses.delete`
- **Enabled by default:** No
- **MVP:** No (Phase 4)
- **Tables:** warehouses
- **Enable behavior:** Adds warehouse management and warehouse selection on documents
- **Disable behavior:** Hides warehouse management
- **Integration:** Inventory, purchases, manufacturing

---

### pos
- **Name:** Point of Sale
- **Category:** Sales
- **Dependencies:** products, invoices, payments, inventory
- **Permissions:** `pos.access`, `pos.process_sale`
- **Enabled by default:** No
- **MVP:** No (Phase 5)
- **Tables:** None (uses invoice/payment tables)
- **Enable behavior:** Adds POS terminal navigation
- **Disable behavior:** Hides POS navigation
- **Integration:** Products, invoices, payments, inventory, customers

---

### crm
- **Name:** CRM
- **Category:** CRM
- **Dependencies:** None
- **Permissions:** `crm.view`, `crm.leads.manage`, `crm.activities.manage`
- **Enabled by default:** No
- **MVP:** No (Phase 8, post-V1)
- **Tables:** leads, lead_activities
- **Enable behavior:** Adds CRM navigation (leads, activities)
- **Disable behavior:** Hides CRM navigation; preserves records
- **Integration:** Customers (lead conversion)

---

### accounting
- **Name:** Accounting
- **Category:** Finance
- **Dependencies:** None
- **Permissions:** `accounting.view`, `accounting.entries.create`, `accounting.reports.view`
- **Enabled by default:** No
- **MVP:** No (Phase 6)
- **Tables:** accounts, journal_entries, journal_lines
- **Enable behavior:** Adds chart of accounts, journal, financial reports
- **Disable behavior:** Hides accounting navigation; journal entries preserved
- **Integration:** Invoices, payments, purchases, expenses (automatic posting)

---

### manufacturing
- **Name:** Manufacturing
- **Category:** Production
- **Dependencies:** products, inventory, warehouses
- **Permissions:** `manufacturing.view`, `manufacturing.bom.manage`, `manufacturing.production.manage`
- **Enabled by default:** No
- **MVP:** No (Phase 7)
- **Tables:** bills_of_materials, bom_items, production_orders
- **Enable behavior:** Adds BOM and production order navigation
- **Disable behavior:** Hides manufacturing navigation; preserves records
- **Integration:** Products (raw materials + finished goods), inventory (stock movements)

---

### reports
- **Name:** Reports
- **Category:** Analytics
- **Dependencies:** None (but shows reports only for enabled modules)
- **Permissions:** `reports.sales`, `reports.purchases`, `reports.inventory`, `reports.financial`
- **Enabled by default:** Yes
- **MVP:** Yes
- **Tables:** None directly (reads from all modules)
- **Enable behavior:** Adds reports navigation with reports for enabled modules
- **Disable behavior:** Hides reports navigation
- **Integration:** All modules (reads aggregated data)

---

### settings
- **Name:** Settings
- **Category:** System
- **Dependencies:** None
- **Permissions:** `settings.view`, `settings.manage`
- **Enabled by default:** Yes (always active)
- **MVP:** Yes
- **Tables:** settings
- **Enable behavior:** Always active; not toggleable
- **Disable behavior:** Cannot be disabled
- **Integration:** All modules (provides configuration)

---

### users
- **Name:** Users & Roles
- **Category:** System
- **Dependencies:** None
- **Permissions:** `users.view`, `users.create`, `users.update`, `users.delete`, `roles.manage`
- **Enabled by default:** Yes (always active)
- **MVP:** Yes
- **Tables:** users, business_memberships, roles, role_permissions, permissions
- **Enable behavior:** Always active; not toggleable
- **Disable behavior:** Cannot be disabled
- **Integration:** All modules (authorization)

---

### branches
- **Name:** Branches
- **Category:** System
- **Dependencies:** None
- **Permissions:** `branches.view`, `branches.manage`
- **Enabled by default:** No
- **MVP:** No (Phase 4+)
- **Tables:** None (businesses table may have branch support, or separate table)
- **Enable behavior:** Adds branch selection and branch-scoped reporting
- **Disable behavior:** Single-branch mode
- **Integration:** Sales, purchases, expenses, reports

---

## Module Categories

Navigation groups modules by category:

| Category | Modules |
|----------|---------|
| Core | dashboard, reports, settings, users |
| Contacts | customers, suppliers |
| Catalog | products, categories, units, taxes |
| Sales | quotations, invoices, sales, pos |
| Purchasing | purchases |
| Inventory | inventory, warehouses |
| Finance | payments, expenses, accounting |
| CRM | crm |
| Production | manufacturing |
| System | settings, users, branches |

---

## Dependency Matrix

```
quotations     → customers, products
invoices       → customers, products
payments       → invoices
purchases      → suppliers, products
inventory      → products
pos            → products, invoices, payments, inventory
manufacturing  → products, inventory, warehouses
crm            → (none, standalone)
accounting     → (none, integrates via events)
sales          → customers, products
expenses       → categories
reports        → (reads from enabled modules)
```

---

## Enable/Disable Rules

1. A module cannot be enabled if its dependencies are not active
2. When enabling a module, its dependencies are offered for automatic activation
3. A module cannot be disabled if dependent modules are currently enabled
4. The system settings and users modules are always active and cannot be toggled
5. Dashboard and reports are always active but show/hide content based on enabled modules
6. Disabling a module hides navigation, blocks routes, and prevents new record creation
7. Historical data is never deleted when a module is disabled
8. Existing records remain queryable through other modules (e.g., invoice still shows customer even if customers module is disabled)