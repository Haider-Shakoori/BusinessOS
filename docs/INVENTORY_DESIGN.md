# BusinessOS — Inventory Design

## Overview

BusinessOS uses a centralized stock movement system. Product quantities are never directly modified by controllers or business logic. All stock changes flow through the `stock_movements` table, creating an auditable trail.

---

## Core Architecture

### Stock Movement as Single Source of Truth
```
stock_movements table = single source of truth for all quantity changes
product.stock_quantity = denormalized (computed on demand or cached)
product_variant.stock_quantity = denormalized
```

### Product Stock Modes
Each product has a `track_stock` boolean:
- `track_stock = true` → Inventory is tracked, movements are recorded, stock is enforced
- `track_stock = false` → No stock tracking (services, digital products, etc.)

### Stock Quantity Derivation
Current stock for a product in a warehouse:
```sql
SELECT SUM(quantity) FROM stock_movements
WHERE product_id = ? AND warehouse_id = ? AND business_id = ?
```

Total stock across all warehouses:
```sql
SELECT SUM(quantity) FROM stock_movements
WHERE product_id = ? AND business_id = ?
```

---

## Movement Types

| Type | Quantity Effect | Description |
|------|----------------|-------------|
| `opening` | Positive | Initial stock on setup |
| `purchase` | Positive | Goods received from supplier |
| `purchase_return` | Negative | Return to supplier |
| `sale` | Negative | Sold to customer |
| `sale_return` | Positive | Customer return |
| `transfer_in` | Positive | Received from another warehouse |
| `transfer_out` | Negative | Sent to another warehouse |
| `adjustment` | +/- | Manual stock correction |
| `damage` | Negative | Damaged/lost stock |
| `production_consumption` | Negative | Raw materials consumed in manufacturing |
| `production_output` | Positive | Finished goods produced |

---

## Movement Recording

### StockService API
```php
class StockService
{
    public function recordMovement(
        int $productId,
        int $warehouseId,
        string $type,
        int $quantity,         // positive = in, negative = out
        ?float $unitCost,     // cost at time of movement
        string $referenceType, // source model class
        int $referenceId,      // source model id
        string $referenceNumber,
        ?string $notes,
        int $createdBy
    ): StockMovement;

    public function getStock(int $productId, ?int $warehouseId = null): int;

    public function getStockValue(int $productId, ?int $warehouseId = null): float;

    public function getMovementHistory(int $productId, ?int $warehouseId = null): Collection;

    public function adjustStock(
        int $productId,
        int $warehouseId,
        int $newQuantity,
        string $reason
    ): StockMovement;

    public function transferStock(
        int $productId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $quantity
    ): array; // Returns two StockMovement records
}
```

---

## Movement Context Requirements

Each movement type requires specific context:

### Opening Stock
```php
StockService::recordMovement(
    productId: 1,
    warehouseId: 1,
    type: 'opening',
    quantity: 100,
    unitCost: 10.00,
    referenceType: 'Business',      // Initial setup
    referenceId: $business->id,
    referenceNumber: 'OPENING',
    notes: 'Initial stock count',
    createdBy: $userId
);
```

### Purchase Received
```php
StockService::recordMovement(
    productId: 1,
    warehouseId: 1,
    type: 'purchase',
    quantity: 50,              // Positive: stock increases
    unitCost: 9.50,            // Cost from purchase order
    referenceType: 'App\Models\Purchase',
    referenceId: $purchase->id,
    referenceNumber: $purchase->purchase_number,
    notes: null,
    createdBy: $userId
);
```

### Sale
```php
StockService::recordMovement(
    productId: 1,
    warehouseId: 1,
    type: 'sale',
    quantity: -5,              // Negative: stock decreases
    unitCost: 9.50,            // Cost at time of sale
    referenceType: 'App\Models\Invoice',
    referenceId: $invoice->id,
    referenceNumber: $invoice->invoice_number,
    notes: null,
    createdBy: $userId
);
```

### Sale Return
```php
StockService::recordMovement(
    productId: 1,
    warehouseId: 1,
    type: 'sale_return',
    quantity: 2,               // Positive: stock increases back
    unitCost: 9.50,
    referenceType: 'App\Models\Return',
    referenceId: $return->id,
    referenceNumber: $return->return_number,
    notes: 'Customer return - defective',
    createdBy: $userId
);
```

### Warehouse Transfer
```php
// Transfer out from source warehouse
StockService::recordMovement(
    productId: 1,
    warehouseId: 1,            // Source
    type: 'transfer_out',
    quantity: -20,
    unitCost: 9.50,
    referenceType: 'App\Models\WarehouseTransfer',
    referenceId: $transfer->id,
    referenceNumber: $transfer->transfer_number,
    createdBy: $userId
);

// Transfer in to destination warehouse
StockService::recordMovement(
    productId: 1,
    warehouseId: 2,            // Destination
    type: 'transfer_in',
    quantity: 20,
    unitCost: 9.50,
    referenceType: 'App\Models\WarehouseTransfer',
    referenceId: $transfer->id,
    referenceNumber: $transfer->transfer_number,
    createdBy: $userId
);
```

### Manual Adjustment
```php
StockService::recordMovement(
    productId: 1,
    warehouseId: 1,
    type: 'adjustment',
    quantity: -3,              // Negative: reduce stock
    unitCost: null,            // Adjustment may not have cost
    referenceType: 'App\Models\StockAdjustment',
    referenceId: $adjustment->id,
    referenceNumber: 'ADJ-001',
    notes: 'Found 3 items damaged during count',
    createdBy: $userId
);
```

---

## Negative Stock Policy

### Configuration
Setting: `inventory.allow_negative_stock` (boolean, per business)

### Behavior
- **Negative stock allowed:** Stock can go below zero. Useful for businesses that sell before receiving.
- **Negative stock disallowed:** System prevents transactions that would result in negative stock. Shows error.

### Implementation
```php
if (!$business->settings->get('inventory.allow_negative_stock')) {
    $currentStock = $this->stockService->getStock($productId, $warehouseId);
    if ($currentStock + $quantity < 0) {
        throw new InsufficientStockException(...);
    }
}
```

### Default
- Default: disallow negative stock
- Configurable per business in inventory settings

---

## Overselling Policy

For POS and online sales scenarios:
- If negative stock is disallowed, transaction fails immediately
- If negative stock is allowed, transaction proceeds
- Low stock alerts can be configured regardless

---

## Stock Valuation

### V1: Weighted Average Costing (WAC)

Chosen for V1 due to:
- Simplicity of implementation
- Simplicity of understanding for CodeCanyon buyers
- Lower database overhead (no batch tracking)
- Adequate accuracy for SMB use cases
- Lower support burden

#### Weighted Average Calculation
```
New WAC = (Existing Stock Value + New Purchase Value) / (Existing Quantity + New Quantity)

Example:
  Current: 100 units @ $10.00 = $1,000
  Purchase: 50 units @ $12.00 = $600
  New WAC = ($1,000 + $600) / (100 + 50) = $10.67
```

#### Implementation
When recording a purchase movement:
1. Get current stock quantity and value for the product
2. Calculate new weighted average cost
3. Update product's `purchase_cost` with new WAC
4. Record movement with unit_cost = new WAC

When recording a sale movement:
1. Use current WAC as the unit_cost for the movement
2. This ensures cost of goods sold matches current valuation

### FIFO (Post-V1 / Future — aligned with Accounting)
FIFO tracking requires batch-level cost tracking:
- Each purchase creates a cost batch with quantity and cost
- Sales consume from oldest batches first
- More complex database design
- More accurate for businesses with significant price variation
- Can be added later without disrupting existing data (new columns/tables)

### Latest Purchase Price (Not Recommended)
- Simple but inaccurate for cost reporting
- Not recommended for production use
- Mentioned only as an alternative considered

---

## Stock Audit Trail

### What's Tracked
Every stock movement records:
1. Product affected
2. Warehouse affected
3. Movement type
4. Quantity change (signed)
5. Unit cost at time of movement
6. Source document (polymorphic reference)
7. Reference number (human-readable)
8. Notes
9. Created by (user)
10. Timestamp

### Stock History View
Available on product detail page:
```
Date         | Type         | Qty    | Unit Cost | Total      | Reference        | By
2026-01-15   | Opening      | +100   | $10.00    | $1,000.00  | Opening          | Admin
2026-01-20   | Purchase     | +50    | $11.00    | $550.00    | PO-2026-00001    | Admin
2026-01-22   | Sale         | -10    | $10.33    | $103.33    | INV-2026-00001   | Admin
2026-01-25   | Sale         | -5     | $10.33    | $51.67     | INV-2026-00002   | Cashier
2026-01-28   | Adjustment   | -2     | $10.33    | $20.67     | ADJ-001          | Manager
```

---

## Warehouse Management

### Multi-Warehouse
- Each warehouse has its own stock levels per product
- Transfers move stock between warehouses
- Stock reports can filter by warehouse
- Default warehouse can be set for transactions

### Single Warehouse (Default)
Most small businesses have one warehouse. In this case:
- A default "Main Warehouse" is created during setup
- All stock movements use this warehouse
- Warehouse selection is hidden in forms if only one exists

### Transfer Workflow
```
1. Create transfer (draft)
   - Select source warehouse
   - Select destination warehouse
   - Add products and quantities
2. Confirm transfer
   - Create transfer_out movement (source warehouse)
   - Mark as in_transit
3. Receive at destination
   - Create transfer_in movement (destination warehouse)
   - Mark as received
```

---

## Stock Count (Audit)

### Physical Count Process
1. Start a stock count session
2. Enter counted quantities for each product
3. System compares counted vs system quantity
4. Generate adjustment movements for differences
5. Record reason for each adjustment

### Stock Count Table (Future)
```
stock_counts:
  id, business_id, warehouse_id, count_number, status, counted_by, created_at

stock_count_items:
  id, stock_count_id, product_id, system_quantity, counted_quantity, difference, notes
```

---

## Low Stock Alerts

### Configuration
- Per-product minimum stock level (`minimum_stock` column)
- System-wide or per-product alert settings

### Alert Generation
Can be checked:
1. On demand (report)
2. Scheduled (daily check via scheduler)
3. After each sale that reduces stock below minimum

### Alert Delivery
- In-app notification to users with inventory permissions
- Email notification (optional, configurable)

---

## Stock Reports

### Current Stock
List of all products with current quantity, warehouse, value.

### Stock Movements
Filterable by: product, warehouse, date range, type.
Shows all movements with full details.

### Stock Valuation
Total inventory value using weighted average cost.
Can break down by warehouse, category, or product.

### Low Stock
Products where current stock <= minimum_stock.

### Stock Aging (Future)
How long items have been in inventory.

---

## Concurrency Handling

### Problem
Two users try to sell the last unit simultaneously.

### Solution (V1)
Use database-level locking on stock movement creation:
```php
DB::beginTransaction();
// Lock the stock row for update
$stock = DB::select('SELECT SUM(quantity) as qty FROM stock_movements WHERE product_id = ? AND warehouse_id = ? FOR UPDATE', [...]);

if ($stock->qty + $quantity < 0) {
    throw new InsufficientStockException();
}

// Record movement
StockMovement::create([...]);
DB::commit();
```

### Alternative (Simpler V1)
If negative stock is disallowed, check and record in a single transaction. MySQL's transaction isolation prevents double-spend in most cases.

---

## Product Types and Stock Behavior

| Product Type | Track Stock | Stock Movements | Affects Inventory Value |
|-------------|-------------|-----------------|------------------------|
| product | Configurable | Yes | Yes |
| service | No | No | No |
| raw_material | Yes | Yes | Yes |
| finished_good | Yes | Yes | Yes |
| non_stock | No | No | No |

---

## Integration Points

### With Invoicing
- When invoice is created with status "sent" or later → record sale movement
- When invoice is cancelled → record sale reversal (positive movement)
- When payment is partial → stock still deducted (on invoice creation, not payment)

### With Purchases (Phase 4)
- When purchase is received → record purchase movement
- When purchase is returned → record purchase_return movement
- Stock increases only on actual receipt, not on order creation

### With Manufacturing (Phase 7)
- Production order completed → record production_consumption (raw materials)
- Production order completed → record production_output (finished goods)

### With POS (Phase 5)
- POS sale completed → same as invoice sale movement
- Hold/resume does not affect stock until final sale