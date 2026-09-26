<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AttendanceBridgeController;
use App\Http\Controllers\AttendanceDeviceController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\BusinessWorkspaceController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\CurrencySettingsController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HrPayrollController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryReturnController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ManufacturingController;
use App\Http\Controllers\ModuleManagementController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SmartAssistantController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\WarehouseTransferController;
use App\Http\Controllers\WorkspaceController;
use App\Support\SafeRedirect;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'app.home' : 'login');
});

/*
 * Business context foundation (Batch 6).
 *
 * Onboarding and switching are authenticated but NOT business-guarded:
 * EnsureBusinessSelected sends users without a business here, and the switch
 * action re-validates ownership server-side. All state-changing endpoints use
 * POST and are covered by the web middleware CSRF protection.
 */
Route::middleware('auth')->group(function () {
    Route::get('/onboarding', [BusinessController::class, 'create'])->name('business.create');
    Route::post('/onboarding', [BusinessController::class, 'store'])->name('business.store');
    Route::post('/business/switch', [BusinessController::class, 'switch'])->name('business.switch');
});

require __DIR__.'/auth.php';

/*
 * Business settings (Batch 9).
 *
 * Settings are scoped to the CURRENT business only — the business is always
 * resolved via BusinessContext, never from a request-supplied business_id.
 * Module availability and authorization are enforced independently:
 *   - module:settings      blocks everything when the module is disabled;
 *   - permission:settings.view   allows the page to be viewed;
 *   - permission:settings.manage is required to persist changes.
 *
 * Hidden navigation is not security: these same guards apply to direct URLs.
 */
Route::get('/workspace/{section}', [WorkspaceController::class, 'placeholder'])
    ->middleware(['auth', 'auth.session', 'business-selected'])
    ->name('workspace.placeholder');

/*
 * Batch 32 — system workspace modules.
 *
 * These routes are business-context and permission guarded but are not tied to
 * an optional BusinessModule row: they are the shell used to manage users,
 * modules, audit history, notifications and cross-module search.
 */
Route::middleware(['auth', 'auth.session', 'business-selected'])->group(function () {
    Route::get('/system/users-roles', [UserRoleController::class, 'index'])
        ->name('system.users-roles.index')
        ->middleware('permission:users.view');
    Route::post('/system/users-roles/members', [UserRoleController::class, 'storeMember'])
        ->name('system.users-roles.members.store')
        ->middleware('permission:users.manage');
    Route::patch('/system/users-roles/members/{membership}', [UserRoleController::class, 'updateMember'])
        ->name('system.users-roles.members.update')
        ->middleware('permission:users.manage');
    Route::post('/system/users-roles/roles', [UserRoleController::class, 'storeRole'])
        ->name('system.users-roles.roles.store')
        ->middleware('permission:users.manage');
    Route::patch('/system/users-roles/roles/{role}', [UserRoleController::class, 'updateRole'])
        ->name('system.users-roles.roles.update')
        ->middleware('permission:users.manage');

    Route::get('/system/modules', [ModuleManagementController::class, 'index'])
        ->name('system.modules.index')
        ->middleware('permission:modules.view');
    Route::post('/system/modules/toggle', [ModuleManagementController::class, 'toggle'])
        ->name('system.modules.toggle')
        ->middleware('permission:modules.manage');

    Route::get('/system/activity', [ActivityLogController::class, 'index'])
        ->name('system.activity.index')
        ->middleware('permission:activity.view');

    Route::get('/system/notifications', [NotificationCenterController::class, 'index'])
        ->name('system.notifications.index')
        ->middleware('permission:notifications.view');
    Route::post('/system/notifications/{notification}/read', [NotificationCenterController::class, 'markRead'])
        ->name('system.notifications.mark-read')
        ->middleware('permission:notifications.view');
    Route::post('/system/notifications/read-all', [NotificationCenterController::class, 'markAllRead'])
        ->name('system.notifications.mark-all-read')
        ->middleware('permission:notifications.manage');

    Route::get('/system/search', [GlobalSearchController::class, 'index'])
        ->name('system.search.index')
        ->middleware('permission:search.use');

    Route::get('/system/smart-assistant', [SmartAssistantController::class, 'index'])
        ->name('system.assistant.index')
        ->middleware('permission:assistant.use');
    Route::post('/system/smart-assistant', [SmartAssistantController::class, 'ask'])
        ->name('system.assistant.ask')
        ->middleware('permission:assistant.use');
    Route::post('/system/smart-assistant/clear', [SmartAssistantController::class, 'clear'])
        ->name('system.assistant.clear')
        ->middleware('permission:assistant.use');

    Route::get('/system/businesses', [BusinessWorkspaceController::class, 'index'])
        ->name('system.businesses.index')
        ->middleware('permission:businesses.view');
});

Route::middleware(['auth', 'auth.session', 'business-selected', 'module:settings'])->group(function () {
    Route::get('/settings', [SettingsController::class, 'index'])
        ->name('settings.index')
        ->middleware('permission:settings.view');

    Route::patch('/settings', [SettingsController::class, 'update'])
        ->name('settings.update')
        ->middleware('permission:settings.manage');

    /*
     * Batch 19 — currency management (base currency, enabled currencies,
     * exchange rates). Every action is a state change on the CURRENT business
     * resolved via BusinessContext; currency_code-enabled/rate rules live in
     * the App\Http\Requests\Currency form requests, and the base-currency gate
     * (changeable only before financial history) lives in SettingsController.
     * `{currency}` binds to the shared global registry; `{exchangeRate}`
     * binds via the BelongsToBusiness global scope → cross-business rates 404.
     */
    Route::post('/settings/currencies', [CurrencySettingsController::class, 'storeCurrency'])
        ->name('settings.currencies.store')
        ->middleware('permission:settings.manage');

    Route::delete('/settings/currencies/{currency}', [CurrencySettingsController::class, 'destroyCurrency'])
        ->name('settings.currencies.destroy')
        ->middleware('permission:settings.manage');

    Route::post('/settings/exchange-rates', [CurrencySettingsController::class, 'storeRate'])
        ->name('settings.exchange-rates.store')
        ->middleware('permission:settings.manage');

    Route::delete('/settings/exchange-rates/{exchangeRate}', [CurrencySettingsController::class, 'destroyRate'])
        ->name('settings.exchange-rates.destroy')
        ->middleware('permission:settings.manage');

    /*
     * Batch 27 — attendance device registry. Device credentials are encrypted
     * at rest, every device is tenant-scoped, and only settings managers may
     * add, change, test or remove a device. Employee/device mappings are kept
     * here as the bridge between machine user IDs and payroll employees.
     */
    Route::get('/settings/attendance-devices', [AttendanceDeviceController::class, 'index'])
        ->name('settings.attendance-devices.index')
        ->middleware('permission:settings.view');

    Route::post('/settings/attendance-devices/detect', [AttendanceDeviceController::class, 'detect'])
        ->name('settings.attendance-devices.detect')
        ->middleware('permission:settings.manage');

    Route::get('/settings/attendance-device-discovery/{attendanceBridgeJob}', [AttendanceDeviceController::class, 'detectionJob'])
        ->name('settings.attendance-devices.discovery-job')
        ->middleware('permission:settings.manage');

    Route::post('/settings/attendance-bridges', [AttendanceBridgeController::class, 'store'])
        ->name('settings.attendance-bridges.store')
        ->middleware('permission:settings.manage');

    Route::post('/settings/attendance-bridges/{attendanceBridge}/regenerate-token', [AttendanceBridgeController::class, 'regenerateToken'])
        ->name('settings.attendance-bridges.regenerate-token')
        ->middleware('permission:settings.manage');

    Route::delete('/settings/attendance-bridges/{attendanceBridge}', [AttendanceBridgeController::class, 'destroy'])
        ->name('settings.attendance-bridges.destroy')
        ->middleware('permission:settings.manage');

    Route::post('/settings/attendance-devices', [AttendanceDeviceController::class, 'store'])
        ->name('settings.attendance-devices.store')
        ->middleware('permission:settings.manage');

    Route::patch('/settings/attendance-devices/{attendanceDevice}', [AttendanceDeviceController::class, 'update'])
        ->name('settings.attendance-devices.update')
        ->middleware('permission:settings.manage');

    Route::delete('/settings/attendance-devices/{attendanceDevice}', [AttendanceDeviceController::class, 'destroy'])
        ->name('settings.attendance-devices.destroy')
        ->middleware('permission:settings.manage');

    Route::post('/settings/attendance-devices/{attendanceDevice}/test', [AttendanceDeviceController::class, 'testConnection'])
        ->name('settings.attendance-devices.test')
        ->middleware('permission:settings.manage');

    Route::post('/settings/attendance-employees', [AttendanceDeviceController::class, 'storeEmployee'])
        ->name('settings.attendance-employees.store')
        ->middleware('permission:settings.manage');

    Route::post('/settings/attendance-devices/{attendanceDevice}/map-employee', [AttendanceDeviceController::class, 'mapEmployee'])
        ->name('settings.attendance-devices.map-employee')
        ->middleware('permission:settings.manage');
});

/*
 * Batch 34 — HR & Payroll.
 *
 * HR employee profiles, approved leave and payroll adjustments share the same
 * business context as attendance. Payroll generation reads normalized
 * attendance records and finalization posts a balanced journal entry.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:hr'])->group(function () {
    Route::get('/hr', [HrPayrollController::class, 'index'])
        ->name('hr.index')
        ->middleware('permission:hr.view');

    Route::post('/hr/employees', [HrPayrollController::class, 'storeEmployee'])
        ->name('hr.employees.store')
        ->middleware('permission:hr.manage');

    Route::patch('/hr/employees/{employee}', [HrPayrollController::class, 'updateEmployee'])
        ->name('hr.employees.update')
        ->middleware('permission:hr.manage');

    Route::post('/hr/leaves', [HrPayrollController::class, 'storeLeave'])
        ->name('hr.leaves.store')
        ->middleware('permission:hr.manage');

    Route::post('/hr/payroll-adjustments', [HrPayrollController::class, 'storeAdjustment'])
        ->name('hr.adjustments.store')
        ->middleware('permission:payroll.manage');

    Route::post('/hr/payroll-runs', [HrPayrollController::class, 'generate'])
        ->name('hr.payroll.generate')
        ->middleware('permission:payroll.manage');

    Route::get('/hr/payroll-runs/{payrollRun}', [HrPayrollController::class, 'show'])
        ->name('hr.payroll.show')
        ->middleware('permission:payroll.view');

    Route::post('/hr/payroll-runs/{payrollRun}/finalize', [HrPayrollController::class, 'finalize'])
        ->name('hr.payroll.finalize')
        ->middleware('permission:payroll.finalize');

    Route::get('/hr/payroll-runs/{payrollRun}/payslip/{employee}', [HrPayrollController::class, 'payslip'])
        ->name('hr.payroll.payslip')
        ->middleware('permission:payroll.view');
});

/*
 * Batch 31 — Point of Sale.
 *
 * The full-screen terminal uses the same products, warehouse stock and
 * accounting ledger as the rest of BusinessOS. No separate POS inventory is
 * maintained.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:pos'])->group(function () {
    Route::get('/pos', [PosController::class, 'index'])
        ->name('pos.index')
        ->middleware('permission:pos.view');
    Route::post('/pos/registers', [PosController::class, 'storeRegister'])
        ->name('pos.registers.store')
        ->middleware('permission:pos.manage');
    Route::post('/pos/registers/{posRegister}/open-shift', [PosController::class, 'openShift'])
        ->name('pos.shifts.open')
        ->middleware('permission:pos.sell');
    Route::post('/pos/shifts/{posShift}/close', [PosController::class, 'closeShift'])
        ->name('pos.shifts.close')
        ->middleware('permission:pos.sell');
    Route::post('/pos/shifts/{posShift}/checkout', [PosController::class, 'checkout'])
        ->name('pos.checkout')
        ->middleware('permission:pos.sell');
    Route::get('/pos/sales/{posSale}/receipt', [PosController::class, 'receipt'])
        ->name('pos.receipt')
        ->middleware('permission:pos.view');
    Route::post('/pos/sales/{posSale}/void', [PosController::class, 'void'])
        ->name('pos.sales.void')
        ->middleware('permission:pos.manage');
});

/*
 * Batch 30 — core operational modules.
 *
 * These are real business-owned data modules rather than workspace
 * placeholders. Module availability and granular permissions are enforced on
 * every route, while model global scopes keep reads tenant-local.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:inventory'])->group(function () {
    Route::get('/inventory', [InventoryController::class, 'index'])
        ->name('inventory.index')
        ->middleware('permission:inventory.view');
    Route::post('/inventory/warehouses', [InventoryController::class, 'storeWarehouse'])
        ->name('inventory.warehouses.store')
        ->middleware('permission:inventory.manage');
    Route::post('/inventory/movements', [InventoryController::class, 'storeMovement'])
        ->name('inventory.movements.store')
        ->middleware('permission:inventory.manage');
    Route::get('/inventory/transfers', [WarehouseTransferController::class, 'index'])
        ->name('inventory.transfers.index')
        ->middleware('permission:inventory.view');
    Route::post('/inventory/transfers', [WarehouseTransferController::class, 'store'])
        ->name('inventory.transfers.store')
        ->middleware('permission:inventory.manage');
    Route::post('/inventory/transfers/{warehouseTransfer}/dispatch', [WarehouseTransferController::class, 'dispatch'])
        ->name('inventory.transfers.dispatch')
        ->middleware('permission:inventory.manage');
    Route::post('/inventory/transfers/{warehouseTransfer}/receive', [WarehouseTransferController::class, 'receive'])
        ->name('inventory.transfers.receive')
        ->middleware('permission:inventory.manage');
    Route::get('/inventory/returns', [InventoryReturnController::class, 'index'])
        ->name('inventory.returns.index')
        ->middleware('permission:inventory.view');
    Route::post('/inventory/returns/sales', [InventoryReturnController::class, 'storeSales'])
        ->name('inventory.returns.sales.store')
        ->middleware(['permission:inventory.manage', 'module:pos', 'permission:pos.manage']);
    Route::post('/inventory/returns/purchases', [InventoryReturnController::class, 'storePurchase'])
        ->name('inventory.returns.purchases.store')
        ->middleware(['permission:inventory.manage', 'module:purchasing', 'permission:purchasing.manage']);
});

Route::middleware(['auth', 'auth.session', 'business-selected', 'module:purchasing'])->group(function () {
    Route::get('/suppliers', [SupplierController::class, 'index'])
        ->name('suppliers.index')
        ->middleware('permission:purchasing.view');

    Route::get('/suppliers/import', [ImportController::class, 'show'])
        ->name('suppliers.import')
        ->defaults('type', 'suppliers')
        ->middleware('permission:purchasing.manage');

    Route::get('/suppliers/import/template', [ImportController::class, 'template'])
        ->name('suppliers.import.template')
        ->defaults('type', 'suppliers')
        ->middleware('permission:purchasing.manage');

    Route::post('/suppliers/import', [ImportController::class, 'preview'])
        ->name('suppliers.import.preview')
        ->defaults('type', 'suppliers')
        ->middleware('permission:purchasing.manage');

    Route::get('/suppliers/import/preview/{token}', [ImportController::class, 'confirm'])
        ->name('suppliers.import.confirm')
        ->defaults('type', 'suppliers')
        ->middleware('permission:purchasing.manage');

    Route::post('/suppliers/import/preview/{token}', [ImportController::class, 'execute'])
        ->name('suppliers.import.execute')
        ->defaults('type', 'suppliers')
        ->middleware('permission:purchasing.manage');

    Route::delete('/suppliers/import/preview/{token}', [ImportController::class, 'cancel'])
        ->name('suppliers.import.cancel')
        ->defaults('type', 'suppliers')
        ->middleware('permission:purchasing.manage');

    Route::post('/suppliers', [SupplierController::class, 'store'])
        ->name('suppliers.store')
        ->middleware('permission:purchasing.manage');

    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])
        ->name('suppliers.show')
        ->middleware('permission:purchasing.view');

    Route::get('/suppliers/{supplier}/ledger', [SupplierController::class, 'ledger'])
        ->name('suppliers.ledger')
        ->middleware('permission:purchasing.view');

    Route::get('/suppliers/{supplier}/statement', [SupplierController::class, 'statement'])
        ->name('suppliers.statement')
        ->middleware('permission:purchasing.view');

    Route::get('/suppliers/{supplier}/statement/pdf', [SupplierController::class, 'statementPdf'])
        ->name('suppliers.statement.pdf')
        ->middleware('permission:purchasing.view');

    Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])
        ->name('suppliers.edit')
        ->middleware('permission:purchasing.manage');

    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])
        ->name('suppliers.update')
        ->middleware('permission:purchasing.manage');

    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])
        ->name('suppliers.destroy')
        ->middleware('permission:purchasing.manage');

    Route::post('/suppliers/{supplier}/payments', [SupplierController::class, 'recordPayment'])
        ->name('suppliers.payments.store')
        ->middleware('permission:purchasing.manage');

    Route::post('/suppliers/{supplier}/payments/{payment}/reverse', [SupplierController::class, 'reversePayment'])
        ->name('suppliers.payments.reverse')
        ->middleware('permission:purchasing.manage');

    Route::get('/purchasing', [PurchasingController::class, 'index'])
        ->name('purchasing.index')
        ->middleware('permission:purchasing.view');
    Route::post('/purchasing/suppliers', [PurchasingController::class, 'storeSupplier'])
        ->name('purchasing.suppliers.store')
        ->middleware('permission:purchasing.manage');
    Route::post('/purchasing/orders', [PurchasingController::class, 'storeOrder'])
        ->name('purchasing.orders.store')
        ->middleware('permission:purchasing.manage');
    Route::post('/purchasing/orders/{purchaseOrder}/receive', [PurchasingController::class, 'receive'])
        ->name('purchasing.orders.receive')
        ->middleware('permission:purchasing.manage');
});

Route::middleware(['auth', 'auth.session', 'business-selected', 'module:accounting'])->group(function () {
    Route::get('/accounting', [AccountingController::class, 'index'])
        ->name('accounting.index')
        ->middleware('permission:accounting.view');
    Route::post('/accounting/accounts', [AccountingController::class, 'storeAccount'])
        ->name('accounting.accounts.store')
        ->middleware('permission:accounting.manage');
    Route::post('/accounting/journals', [AccountingController::class, 'storeJournal'])
        ->name('accounting.journals.store')
        ->middleware('permission:accounting.manage');
});

Route::middleware(['auth', 'auth.session', 'business-selected', 'module:crm'])->group(function () {
    Route::get('/crm', [CrmController::class, 'index'])
        ->name('crm.index')
        ->middleware('permission:crm.view');
    Route::post('/crm/leads', [CrmController::class, 'storeLead'])
        ->name('crm.leads.store')
        ->middleware('permission:crm.manage');
    Route::post('/crm/leads/{crmLead}/activities', [CrmController::class, 'storeActivity'])
        ->name('crm.leads.activities.store')
        ->middleware('permission:crm.manage');
});

Route::middleware(['auth', 'auth.session', 'business-selected', 'module:manufacturing'])->group(function () {
    Route::get('/manufacturing', [ManufacturingController::class, 'index'])
        ->name('manufacturing.index')
        ->middleware('permission:manufacturing.view');
    Route::post('/manufacturing/boms', [ManufacturingController::class, 'storeBom'])
        ->name('manufacturing.boms.store')
        ->middleware('permission:manufacturing.manage');
    Route::post('/manufacturing/orders', [ManufacturingController::class, 'storeOrder'])
        ->name('manufacturing.orders.store')
        ->middleware('permission:manufacturing.manage');
    Route::post('/manufacturing/orders/{productionOrder}/complete', [ManufacturingController::class, 'complete'])
        ->name('manufacturing.orders.complete')
        ->middleware('permission:manufacturing.manage');
});

/*
 * Customer registry (Batch 10).
 *
 * Follows the same guest-host pattern as settings, applied per-route because
 * reads and writes need different permissions:
 *   - module:customers      blocks the whole module when it is disabled;
 *   - permission:customers.view    allows listing and viewing a customer;
 *   - permission:customers.manage is required to create/edit/delete.
 *
 * Batch 18 adds the read-only ledger and print-ready statement routes
 * (GET /customers/{customer}/ledger and .../statement) behind the same
 * customers.view permission — no extra permission is created just for
 * reading or printing.
 *
 * `{customer}` uses implicit route-model binding; the BelongsToBusiness global
 * scope makes cross-business lookups resolve to 404 automatically. Reads go
 * only to the current business's customers — search, pagination, and views are
 * all tenant-scoped at the query level.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:customers'])->group(function () {
    Route::get('/customers', [CustomerController::class, 'index'])
        ->name('customers.index')
        ->middleware('permission:customers.view');

    Route::get('/customers/export', [ExportController::class, 'customers'])
        ->name('customers.export')
        ->middleware('permission:customers.view');

    // Batch 20 — create-only CSV import. Registered BEFORE the implicit
    // {customer} binding routes below, so the literal /customers/import paths
    // always win. The `type` route parameter is fixed by ->defaults() and can
    // never be supplied by the client. Template/download + the whole
    // upload→preview→execute→cancel flow are gated on customers.manage (an
    // import writes customers, so .view alone is never enough).
    Route::get('/customers/import', [ImportController::class, 'show'])
        ->name('customers.import')
        ->defaults('type', 'customers')
        ->middleware('permission:customers.manage');

    Route::get('/customers/import/template', [ImportController::class, 'template'])
        ->name('customers.import.template')
        ->defaults('type', 'customers')
        ->middleware('permission:customers.manage');

    Route::post('/customers/import', [ImportController::class, 'preview'])
        ->name('customers.import.preview')
        ->defaults('type', 'customers')
        ->middleware('permission:customers.manage');

    Route::get('/customers/import/preview/{token}', [ImportController::class, 'confirm'])
        ->name('customers.import.confirm')
        ->defaults('type', 'customers')
        ->middleware('permission:customers.manage');

    Route::post('/customers/import/preview/{token}', [ImportController::class, 'execute'])
        ->name('customers.import.execute')
        ->defaults('type', 'customers')
        ->middleware('permission:customers.manage');

    Route::delete('/customers/import/preview/{token}', [ImportController::class, 'cancel'])
        ->name('customers.import.cancel')
        ->defaults('type', 'customers')
        ->middleware('permission:customers.manage');

    Route::get('/customers/create', [CustomerController::class, 'create'])
        ->name('customers.create')
        ->middleware('permission:customers.manage');

    Route::post('/customers', [CustomerController::class, 'store'])
        ->name('customers.store')
        ->middleware('permission:customers.manage');

    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->name('customers.show')
        ->middleware('permission:customers.view');

    Route::get('/customers/{customer}/ledger', [CustomerController::class, 'ledger'])
        ->name('customers.ledger')
        ->middleware('permission:customers.view');

    Route::get('/customers/{customer}/statement', [CustomerController::class, 'statement'])
        ->name('customers.statement')
        ->middleware('permission:customers.view');

    Route::get('/customers/{customer}/statement/pdf', [CustomerController::class, 'statementPdf'])
        ->name('customers.statement.pdf')
        ->middleware('permission:customers.view');

    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])
        ->name('customers.edit')
        ->middleware('permission:customers.manage');

    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
        ->name('customers.update')
        ->middleware('permission:customers.manage');

    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
        ->name('customers.destroy')
        ->middleware('permission:customers.manage');
});

/*
 * Catalog reference data (Batch 11) — categories, units, and taxes.
 *
 * Categories and units belong to the `products` module for availability and
 * are individually permission-guarded (module availability and authorization
 * stay independent, per Batch 8):
 *   - module:products           blocks the whole catalogue when disabled;
 *   - permission:{entity}.view    allows listing;
 *   - permission:{entity}.manage  is required to create/edit/delete.
 *
 * Taxes additionally require the optional-tax feature gate (`tax-enabled`):
 * while general.tax_enabled is off, every tax route is refused outright even
 * for users holding taxes.manage — regardless of how many tax rows exist.
 * Disabling never touches tax rows; re-enabling restores access to them.
 *
 * `{category}` / `{unit}` / `{tax}` use implicit route-model binding; the
 * BelongsToBusiness global scope makes cross-business lookups resolve to 404
 * automatically.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:products'])->group(function () {
    Route::get('/categories', [CategoryController::class, 'index'])
        ->name('categories.index')
        ->middleware('permission:categories.view');

    Route::get('/categories/create', [CategoryController::class, 'create'])
        ->name('categories.create')
        ->middleware('permission:categories.manage');

    Route::post('/categories', [CategoryController::class, 'store'])
        ->name('categories.store')
        ->middleware('permission:categories.manage');

    Route::get('/categories/{category}/edit', [CategoryController::class, 'edit'])
        ->name('categories.edit')
        ->middleware('permission:categories.manage');

    Route::patch('/categories/{category}', [CategoryController::class, 'update'])
        ->name('categories.update')
        ->middleware('permission:categories.manage');

    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->name('categories.destroy')
        ->middleware('permission:categories.manage');

    Route::get('/units', [UnitController::class, 'index'])
        ->name('units.index')
        ->middleware('permission:units.view');

    Route::get('/units/create', [UnitController::class, 'create'])
        ->name('units.create')
        ->middleware('permission:units.manage');

    Route::post('/units', [UnitController::class, 'store'])
        ->name('units.store')
        ->middleware('permission:units.manage');

    Route::get('/units/{unit}/edit', [UnitController::class, 'edit'])
        ->name('units.edit')
        ->middleware('permission:units.manage');

    Route::patch('/units/{unit}', [UnitController::class, 'update'])
        ->name('units.update')
        ->middleware('permission:units.manage');

    Route::delete('/units/{unit}', [UnitController::class, 'destroy'])
        ->name('units.destroy')
        ->middleware('permission:units.manage');
});

Route::middleware(['auth', 'auth.session', 'business-selected', 'module:products', 'tax-enabled'])->group(function () {
    Route::get('/taxes', [TaxController::class, 'index'])
        ->name('taxes.index')
        ->middleware('permission:taxes.view');

    Route::get('/taxes/create', [TaxController::class, 'create'])
        ->name('taxes.create')
        ->middleware('permission:taxes.manage');

    Route::post('/taxes', [TaxController::class, 'store'])
        ->name('taxes.store')
        ->middleware('permission:taxes.manage');

    Route::get('/taxes/{tax}/edit', [TaxController::class, 'edit'])
        ->name('taxes.edit')
        ->middleware('permission:taxes.manage');

    Route::patch('/taxes/{tax}', [TaxController::class, 'update'])
        ->name('taxes.update')
        ->middleware('permission:taxes.manage');

    Route::delete('/taxes/{tax}', [TaxController::class, 'destroy'])
        ->name('taxes.destroy')
        ->middleware('permission:taxes.manage');
});

/*
 * Product / service registry (Batch 12).
 *
 * Products and services share one table and one set of routes (no show page —
 * the list is the read view). The `products` module gates availability, and
 * per-route permissions separate reads from writes:
 *   - module:products           blocks the whole registry when disabled;
 *   - permission:products.view    allows listing;
 *   - permission:products.manage  is required to create/edit/delete.
 *
 * `{product}` uses implicit route-model binding; the BelongsToBusiness global
 * scope makes cross-business lookups resolve to 404 automatically. Category /
 * unit / tax references are tenant-safe at the request layer (scoped exists
 * rules) and never at the DB level via foreign keys.
 *
 * There is NO inventory/stock behaviour on this entity in this batch.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:products'])->group(function () {
    Route::get('/products', [ProductController::class, 'index'])
        ->name('products.index')
        ->middleware('permission:products.view');

    Route::get('/products/export', [ExportController::class, 'products'])
        ->name('products.export')
        ->middleware('permission:products.view');

    // Batch 20 — create-only CSV import (see customers.import above for the
    // identical security layout; this group enforces products.manage).
    Route::get('/products/import', [ImportController::class, 'show'])
        ->name('products.import')
        ->defaults('type', 'products')
        ->middleware('permission:products.manage');

    Route::get('/products/import/template', [ImportController::class, 'template'])
        ->name('products.import.template')
        ->defaults('type', 'products')
        ->middleware('permission:products.manage');

    Route::post('/products/import', [ImportController::class, 'preview'])
        ->name('products.import.preview')
        ->defaults('type', 'products')
        ->middleware('permission:products.manage');

    Route::get('/products/import/preview/{token}', [ImportController::class, 'confirm'])
        ->name('products.import.confirm')
        ->defaults('type', 'products')
        ->middleware('permission:products.manage');

    Route::post('/products/import/preview/{token}', [ImportController::class, 'execute'])
        ->name('products.import.execute')
        ->defaults('type', 'products')
        ->middleware('permission:products.manage');

    Route::delete('/products/import/preview/{token}', [ImportController::class, 'cancel'])
        ->name('products.import.cancel')
        ->defaults('type', 'products')
        ->middleware('permission:products.manage');

    Route::get('/products/create', [ProductController::class, 'create'])
        ->name('products.create')
        ->middleware('permission:products.manage');

    Route::post('/products', [ProductController::class, 'store'])
        ->name('products.store')
        ->middleware('permission:products.manage');

    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])
        ->name('products.edit')
        ->middleware('permission:products.manage');

    Route::patch('/products/{product}', [ProductController::class, 'update'])
        ->name('products.update')
        ->middleware('permission:products.manage');

    Route::delete('/products/{product}', [ProductController::class, 'destroy'])
        ->name('products.destroy')
        ->middleware('permission:products.manage');
});

/*
 * Quotations (Batch 14).
 *
 * The sales module gates availability (module:sales) while separate
 * permissions separate reads from writes:
 *   - module:sales             blocks quotations entirely when sales is off;
 *   - permission:quotations.view   allows listing and viewing a quotation;
 *   - permission:quotations.manage is required to create/edit/delete.
 *
 * `{quotation}` uses implicit route-model binding; the BelongsToBusiness global
 * scope makes cross-business lookups resolve to 404 automatically. Lines are
 * validated tenant-safely at the request layer (scoped exists rules) and never
 * assign a business at the DB level via foreign keys.
 *
 * Lifecycle: draft quotations are editable/deletable; later statuses are
 * immutable in this batch — the 403 is enforced inside QuotationService.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:sales'])->group(function () {
    Route::get('/quotations', [QuotationController::class, 'index'])
        ->name('quotations.index')
        ->middleware('permission:quotations.view');

    Route::get('/quotations/create', [QuotationController::class, 'create'])
        ->name('quotations.create')
        ->middleware('permission:quotations.manage');

    Route::post('/quotations', [QuotationController::class, 'store'])
        ->name('quotations.store')
        ->middleware('permission:quotations.manage');

    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])
        ->name('quotations.show')
        ->middleware('permission:quotations.view');

    Route::get('/quotations/{quotation}/print', [QuotationController::class, 'print'])
        ->name('quotations.print')
        ->middleware('permission:quotations.view');

    Route::get('/quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])
        ->name('quotations.pdf')
        ->middleware('permission:quotations.view');

    Route::get('/quotations/{quotation}/edit', [QuotationController::class, 'edit'])
        ->name('quotations.edit')
        ->middleware('permission:quotations.manage');

    Route::patch('/quotations/{quotation}', [QuotationController::class, 'update'])
        ->name('quotations.update')
        ->middleware('permission:quotations.manage');

    Route::delete('/quotations/{quotation}', [QuotationController::class, 'destroy'])
        ->name('quotations.destroy')
        ->middleware('permission:quotations.manage');
});

/*
 * Invoices (Batch 15).
 *
 * Like quotations, invoices live under the sales module (module:sales) and use
 * separate read/write permissions:
 *   - module:sales              blocks the whole module when sales is off;
 *   - permission:invoices.view   allows listing and viewing an invoice;
 *   - permission:invoices.manage is required to create/edit/delete.
 *
 * `{invoice}` uses implicit route-model binding; the BelongsToBusiness global
 * scope makes cross-business lookups resolve to 404 automatically. Lines are
 * validated tenant-safely at the request layer (scoped exists rules). Articles
 * products AND services are allowed; there is NO inventory/stock behaviour.
 *
 * Lifecycle: draft invoices are editable/deletable; sent (including converted)
 * invoices are immutable in this batch — the 403 is enforced inside
 * InvoiceService. No payment/balance behaviour exists in this batch.
 *
 * The quotation -> invoice conversion route is explicitly POST + CSRF:
 *   - module:sales             blocks conversion entirely when sales is off;
 *   - permission:quotations.view   the actor must be able to access the source
 *                                 quotation it is converting;
 *   - permission:invoices.manage  conversion is an invoice WRITE, so invoice
 *                                 management authorization is required.
 *
 * Convertibility (non-converted, current business) is re-verified under a row
 * lock inside InvoiceService::convert(); the DB-unique constraint on
 * invoices.quotation_id backstops the one-to-one rule under concurrency.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:sales'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index')
        ->middleware('permission:invoices.view');

    Route::get('/invoices/export', [ExportController::class, 'invoices'])
        ->name('invoices.export')
        ->middleware('permission:invoices.view');

    Route::get('/invoices/create', [InvoiceController::class, 'create'])
        ->name('invoices.create')
        ->middleware('permission:invoices.manage');

    Route::post('/invoices', [InvoiceController::class, 'store'])
        ->name('invoices.store')
        ->middleware('permission:invoices.manage');

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show')
        ->middleware('permission:invoices.view');

    Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])
        ->name('invoices.print')
        ->middleware('permission:invoices.view');

    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
        ->name('invoices.pdf')
        ->middleware('permission:invoices.view');

    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])
        ->name('invoices.edit')
        ->middleware('permission:invoices.manage');

    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])
        ->name('invoices.update')
        ->middleware('permission:invoices.manage');

    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->name('invoices.destroy')
        ->middleware('permission:invoices.manage');

    Route::post('/quotations/{quotation}/convert', [InvoiceController::class, 'convert'])
        ->name('quotations.convert')
        ->middleware(['permission:quotations.view', 'permission:invoices.manage']);
});

/*
 * Payments (Batch 16).
 *
 * Payments sit on top of invoices, so they live under the same sales module
 * availability gate (module:sales) and use three separate permissions:
 *   - module:sales             blocks recording/reversing when sales is off;
 *   - permission:payments.view    allows listing and viewing a payment;
 *   - permission:payments.create  is required to record a payment;
 *   - permission:payments.reverse is required to reverse a payment.
 *
 * Like invoices, `{payment}` uses implicit route-model binding; the
 * BelongsToBusiness global scope makes cross-business lookups resolve to 404
 * automatically. Payment business rules (payable state, overpayment rejection,
 * reconciliation, reversal semantics, locking) are enforced WITHOUT exception
 * inside PaymentService — the controller and forms never decide them.
 *
 * Recording is only ever reachable from the targeted invoice's show page, so
 * the payment is always bound to a live, current-business invoice. Every
 * state change is an explicit POST covered by the web middleware CSRF
 * protection; there is deliberately no DELETE route (a reversal marks the
 * payment, it is never deleted).
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:sales'])->group(function () {
    Route::get('/payments', [PaymentController::class, 'index'])
        ->name('payments.index')
        ->middleware('permission:payments.view');

    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->name('payments.show')
        ->middleware('permission:payments.view');

    Route::post('/payments', [PaymentController::class, 'store'])
        ->name('payments.store')
        ->middleware('permission:payments.create');

    Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])
        ->name('payments.reverse')
        ->middleware('permission:payments.reverse');
});

/*
 * Expenses (Batch 17).
 *
 * Expense tracking is its own module (module:expenses) with two permissions
 * separating reads from writes:
 *   - module:expenses            blocks the whole module when expenses is off;
 *   - permission:expenses.view    allows listing, viewing and downloading
 *                                 receipts (and the operational report);
 *   - permission:expenses.manage  is required to create/update/delete.
 *
 * `{expense}` uses implicit route-model binding; the BelongsToBusiness global
 * scope makes cross-business lookups resolve to 404 automatically, which also
 * protects the receipt route — a receipt can never be fetched for an expense
 * of another business.
 *
 * The receipt endpoint is a state-free GET but is permission + tenant gated
 * exactly like the record it belongs to; the underlying file lives on the
 * private disk and is streamed only through this route.
 *
 * The report is the lightweight Batch 17 operational report (date range +
 * category + list + total), not the Batch 23 reporting engine.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:expenses'])->group(function () {
    Route::get('/expenses', [ExpenseController::class, 'index'])
        ->name('expenses.index')
        ->middleware('permission:expenses.view');

    Route::get('/expenses/export', [ExportController::class, 'expenses'])
        ->name('expenses.export')
        ->middleware('permission:expenses.view');

    Route::get('/expenses/create', [ExpenseController::class, 'create'])
        ->name('expenses.create')
        ->middleware('permission:expenses.manage');

    Route::post('/expenses', [ExpenseController::class, 'store'])
        ->name('expenses.store')
        ->middleware('permission:expenses.manage');

    Route::get('/expenses/report', [ExpenseController::class, 'report'])
        ->name('expenses.report')
        ->middleware('permission:expenses.view');

    Route::get('/expenses/report/export', [ExportController::class, 'expenseReport'])
        ->name('expenses.report.export')
        ->middleware('permission:expenses.view');

    Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])
        ->name('expenses.show')
        ->middleware('permission:expenses.view');

    Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])
        ->name('expenses.receipt')
        ->middleware('permission:expenses.view');

    Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])
        ->name('expenses.edit')
        ->middleware('permission:expenses.manage');

    Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])
        ->name('expenses.update')
        ->middleware('permission:expenses.manage');

    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])
        ->name('expenses.destroy')
        ->middleware('permission:expenses.manage');
});

/*
 * Core reports (Batch 23).
 *
 * The reports module is independently switchable and uses its own read
 * permission. Every underlying query is still tenant-scoped by the source
 * model (or, for invoice_items, through the tenant-scoped parent invoice).
 * Reports are read-only; CSV exports reuse the exact same ReportService read
 * models as the HTML views so filtering and currency math cannot diverge.
 */
Route::middleware(['auth', 'auth.session', 'business-selected', 'module:reports', 'permission:reports.view'])->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])
        ->name('reports.index');

    Route::get('/reports/export/{report}', [ExportController::class, 'report'])
        ->where('report', 'summary|products|customers|expenses|receivables')
        ->name('reports.export');
});

/*
 * Locale switching route.
 *
 * Validates the requested locale against the supported list, stores
 * it in the session, and redirects back. No database, no auth.
 */
Route::get('/locale/{locale}', function (string $locale) {
    if (! in_array($locale, config('app.supported_locales', []))) {
        abort(400, 'Unsupported locale.');
    }

    session()->put(config('localization.session_key', 'locale'), $locale);

    return redirect(SafeRedirect::path(request()->headers->get('referer'), '/'))
        ->with('locale_changed', true);
})->name('locale.switch');

/*
 * Development-only component showcase for the Batch 2 design system.
 * Registered solely when the application is running in the local environment
 * so it can never leak into production builds.
 */
if (app()->environment('local')) {
    Route::get('/ui-preview', function () {
        $paginator = new LengthAwarePaginator(
            items: collect(range(1, 5)),
            total: 48,
            perPage: 5,
            currentPage: request()->integer('page', 1),
            options: ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'page'],
        );

        return view('ui-preview', ['paginator' => $paginator]);
    })->name('ui.preview');

    Route::get('/app-preview', fn () => view('app-preview'))->name('app.preview');
}
