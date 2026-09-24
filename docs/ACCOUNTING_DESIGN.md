# BusinessOS — Accounting Design

## Overview

BusinessOS has exactly ONE accounting implementation — the double-entry Accounting module delivered in Phase 6.

Marketplace V1 does NOT attempt full accounting. It ships a **Finance / Financial Tracking** layer (invoices, payments, expenses, customer balances, customer ledger, receivables, financial summaries). This is deliberately not described as "accounting" and never performs debit/credit posting. Early financial domains are designed to be **accounting-compatible** (see the compatibility section below) so that Phase 6 integration requires no schema redesign — but no accounting engine exists before Phase 6.

---

## Account Structure

### Account Types

| Type | Sub-Types | Normal Balance |
|------|-----------|---------------|
| Asset | Current Asset, Fixed Asset, Bank, Cash | Debit |
| Liability | Current Liability, Long-term Liability | Credit |
| Equity | Owner's Equity, Retained Earnings | Credit |
| Revenue | Sales Revenue, Other Revenue | Credit |
| Expense | Cost of Goods Sold, Operating Expense, Other Expense | Debit |

### Default Chart of Accounts

#### Assets (1xxx)
```
1000  Cash
1010  Petty Cash
1100  Accounts Receivable
1200  Inventory
1300  Prepaid Expenses
1500  Fixed Assets
1510  Accumulated Depreciation
```

#### Liabilities (2xxx)
```
2000  Accounts Payable
2100  Sales Tax Payable
2200  Accrued Expenses
2300  Bank Loan
```

#### Equity (3xxx)
```
3000  Owner's Equity
3100  Retained Earnings
3200  Current Year Earnings
```

#### Revenue (4xxx)
```
4000  Sales Revenue
4100  Service Revenue
4200  Other Income
4300  Discount Received
```

#### Expenses (5xxx)
```
5000  Cost of Goods Sold
5100  Purchase Returns
5200  Freight In
6000  Rent Expense
6100  Salaries Expense
6200  Utilities Expense
6300  Office Supplies
6400  Depreciation Expense
6500  Bank Charges
7000  Discount Allowed
7100  Bad Debts
```

---

## Journal Architecture

### Journal Entry Structure
Every journal entry:
1. Has a unique entry number (auto-generated)
2. Has a date
3. Has a description
4. Contains at least 2 journal lines
5. Total debits MUST equal total credits
6. References a source document (polymorphic)
7. Is immutable once created (use reversal for corrections)

### Journal Line Structure
Each line:
1. References an account
2. Has either a debit OR credit amount (never both, never zero)
3. May reference a party (customer/supplier) for sub-ledger tracking

### Immutability Rules
- Journal entries are never edited after creation
- Corrections are made via reversal entries
- A new correct entry is created
- Original entry is marked as reversed with reference to new entry

---

## Posting Rules

### Invoice Created (Draft → Sent or immediately on creation if business preference)

**Invoice with Tax (Exclusive Tax Example):**
```
Invoice Amount: $1,000 + $150 tax = $1,150 total

Journal Entry:
  DR  Accounts Receivable (1100)    $1,150
    CR  Sales Revenue (4000)              $1,000
    CR  Sales Tax Payable (2100)            $150
```

**Invoice with Tax (Inclusive Tax Example):**
```
Invoice Amount: $1,150 inclusive of 15% tax
Tax portion: $150, Net: $1,000

Journal Entry:
  DR  Accounts Receivable (1100)    $1,150
    CR  Sales Revenue (4000)              $1,000
    CR  Sales Tax Payable (2100)            $150
```

### Payment Received

**Full Payment of Invoice:**
```
Payment Amount: $1,150

Journal Entry:
  DR  Cash/Bank (1000/1010)         $1,150
    CR  Accounts Receivable (1100)       $1,150
```

**Partial Payment:**
```
Payment Amount: $500

Journal Entry:
  DR  Cash/Bank (1000)              $500
    CR  Accounts Receivable (1100)       $500
```

### Invoice Cancelled
```
Original AR: $1,150

Reversal Entry:
  DR  Sales Revenue (4000)          $1,000
  DR  Sales Tax Payable (2100)      $150
    CR  Accounts Receivable (1100)       $1,150
```

### Payment Reversed
```
Original Payment: $500

Reversal Entry:
  DR  Accounts Receivable (1100)    $500
    CR  Cash/Bank (1000)                 $500
```

### Expense Recorded
```
Expense Amount: $200 + $30 tax

Journal Entry:
  DR  Expense Category (6xxx)       $230
    CR  Cash/Bank (1000)                 $230
```

### Purchase Recorded (Phase 4)
```
Purchase Amount: $500 + $75 tax = $575

Journal Entry:
  DR  Inventory/COGS (1200/5000)   $575
    CR  Accounts Payable (2000)          $575
```

### Purchase Payment
```
Payment Amount: $575

Journal Entry:
  DR  Accounts Payable (2000)       $575
    CR  Cash/Bank (1000)                 $575
```

---

## Accounting Compatibility for Early Financial Domains

Although the Accounting module is implemented in Phase 6, the financial domains built in Phase 2 must be designed so they can integrate with the future posting engine WITHOUT major schema redesign. These are accommodation rules for early batches — not an early accounting implementation.

### Required Compatibility Traits

| Trait | Requirement for Invoice / Payment / Expense / Purchase |
|-------|--------------------------------------------------------|
| Immutable identifiers | Documents get a stable, never-reused number at finalization (Invoice, Payment, etc.) |
| Status rules | Finalized documents move forward only (draft → sent → paid/overdue/cancelled); corrections use cancellation/reversal, never in-place edits or deletes |
| Business scope | Every financial row carries `business_id` and is tenant-scoped |
| Currency fields | Every financial row carries `currency_id`, `exchange_rate`, and base-currency totals (`base_amount` where applicable) so posting can be derived in base currency |
| Timestamps | `created_at` / `updated_at` plus explicit document dates and (where relevant) cancelled/reversed timestamps |
| Cancellation/reversal approach | Cancellation records (`cancelled_at`, `cancelled_by`, `cancelled_reason`) and payment reversal records — never hard deletion |
| Source references | Every financial document is addressable by type + id + number so journal entries can link back bidirectionally |
| Domain events | Financial events (`InvoiceCreated`, `InvoicePaid`, `PaymentReceived`, `PaymentReversed`, `InvoiceCancelled`, `ExpenseRecorded`, `PurchaseReceived`) are dispatched on finalization — these are the Phase 6 posting hooks |

### How Phase 6 Consumes These
- A listener subscribes to the already-dispatched financial events and creates journal entries via the remote posting service
- No changes to invoice/payment/expense/purchase tables are required
- Historical V1 transactions are postable retroactively because they already carry all required fields
- Single implementation: the Accounting module. No parallel "light accounting" system exists.

---

## Source Document Linking

### Bidirectional Traceability
Every journal entry references its source:
```php
$journalEntry->reference_type  // 'App\Models\Invoice'
$journalEntry->reference_id    // 42
$journalEntry->reference_number // 'INV-2026-00001'
```

Every source document can find its journal entries:
```php
$invoice->journalEntries       // Collection of JournalEntry
$invoice->hasJournalEntries()  // boolean
```

### Source Types
| Source | Event |
|--------|-------|
| Invoice | Invoice created (DR entry) |
| Payment | Payment received/recorded |
| Expense | Expense recorded |
| Purchase | Purchase recorded (Phase 4) |
| Sale | POS sale (uses Invoice) |
| Refund | Credit note / refund issued |
| Manual | Manual journal entry |

---

## Marketplace V1 Scope — Finance / Financial Tracking

### What's Included (Marketplace V1)
- Customer balance tracking (opening_balance + current_balance)
- Customer ledger (list of invoices and payments)
- Customer statement (print-ready HTML → PDF via central document service)
- Receivables report
- Expense tracking with categories
- Expense reports
- Financial summaries (dashboard widgets, invoices, payments, expenses)
- Multi-currency transaction fields (currency, exchange rate, base amount)

### What's NOT Included (reserved exclusively for the Phase 6 Accounting module)
- Chart of Accounts
- Journal entries / journal lines
- Posting engine
- Reversal engine
- General Ledger
- Trial Balance
- Profit & Loss statement
- Balance Sheet
- Account-level posting
- Debit/credit journal posting of any kind

### Financial Tracking Ledger (Marketplace V1)
For Marketplace V1, the customer ledger is transaction-based (not journal-based). Supplier ledger ships with the Supplier/Purchase phase; it is also transaction-based until Accounting arrives.
```
Customer Ledger:
  Opening Balance:    $500.00
  INV-2026-00001:   $1,000.00  (debit)
  PAY-2026-00001:   -$500.00   (credit)
  INV-2026-00002:     $750.00  (debit)
  ─────────────────────────────
  Current Balance:   $1,750.00
```

---

## Full Accounting Scope (Phase 6)

### Journal Engine Service
```php
class AccountingService
{
    public function postJournalEntry(
        string $description,
        Carbon $date,
        array $lines, // [['account_id' => 1, 'debit' => 100], ['account_id' => 2, 'credit' => 100]]
        string $referenceType,
        int $referenceId,
        string $referenceNumber
    ): JournalEntry;

    public function reverseJournalEntry(JournalEntry $entry, string $reason): JournalEntry;

    public function getAccountBalance(int $accountId, ?Carbon $from, ?Carbon $to): decimal;

    public function getTrialBalance(?Carbon $date): Collection;

    public function getProfitAndLoss(?Carbon $from, ?Carbon $to): array;

    public function getBalanceSheet(?Carbon $date): array;
}
```

### Automatic Posting Events
When accounting module is enabled, these events trigger journal posting:
1. Invoice created → DR AR, CR Revenue (+ Tax)
2. Payment received → DR Cash/Bank, CR AR
3. Expense recorded → DR Expense, CR Cash/Bank
4. Invoice cancelled → Reverse original entry
5. Payment reversed → Reverse original entry
6. Purchase recorded → DR Inventory, CR AP (Phase 4)
7. Purchase payment → DR AP, CR Cash/Bank (Phase 4)

### Manual Journal Entries
Users with `accounting.entries.create` permission can create manual journal entries for:
- Adjustments
- Opening balances
- Non-standard transactions
- Year-end closing entries

---

## Fiscal Periods

### Year-End Process
1. Verify all transactions posted for the year
2. Close fiscal year
3. Close revenue and expense accounts to retained earnings
4. Set opening balances for new year
5. Mark period as locked (no new entries allowed)

### Fiscal Period Table (Future)
```
fiscal_periods:
  id, business_id, year, start_date, end_date, status (open, closed), closed_at
```

---

## Opening Balances

During business onboarding, users can enter opening balances for:
- Bank accounts
- Cash accounts
- Accounts Receivable (customer balances)
- Accounts Payable (supplier balances)
- Inventory (stock value)

These are posted as opening journal entries with date = fiscal year start.

---

## Multi-Currency Accounting (Phase 6)

When a transaction occurs in a foreign currency:
1. Record amount in transaction currency
2. Record exchange rate at time of transaction
3. Calculate base currency equivalent
4. Post journal entry in base currency
5. Store transaction currency and rate for reference

**Never recalculate old transactions using current rates.**

---

## Reversal Strategy

### Reversal Entry
When a journal entry needs correction:
1. Create a new journal entry that is the exact opposite
2. Reference the original entry as reversed
3. Mark original entry as `is_reversed = true`
4. Store `reversed_by_entry_id` on original
5. Original entry remains visible for audit

### Full Reversal Example
Original:
```
Entry #1001:
  DR  AR (1100)    $1,150
    CR  Revenue (4000)    $1,000
    CR  Tax (2100)          $150
```

Reversal:
```
Entry #1002 (reversal of #1001):
  DR  Revenue (4000)  $1,000
  DR  Tax (2100)        $150
    CR  AR (1100)      $1,150
```

---

## Audit Integrity Rules

1. **Never delete** a journal entry, journal line, or posted transaction
2. **Never modify** a journal entry after creation
3. Use reversal for all corrections
4. Every entry must reference its source
5. Every source must be linkable to its entries
6. Balance check: sum(debits) == sum(credits) for every entry
7. Running account balance must be computable from entries
8. Period closing must verify no unposted entries exist

---

## Financial Reports

### General Ledger
Filtered by account, date range. Shows all journal lines for the account.

### Trial Balance
Lists all accounts with their debit/credit balances at a point in time.
Total debits must equal total credits.

### Profit & Loss Statement
Revenue accounts minus expense accounts for a period.

### Balance Sheet
Assets = Liabilities + Equity at a point in time.

### Account Statement
All transactions for a specific account within a date range.

### Customer Receivable Report
All customers with outstanding balances.

### Supplier Payable Report
All suppliers with outstanding balances (Phase 4).
