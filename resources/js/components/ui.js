/**
 * BusinessOS UI — shared Alpine.js component behaviors.
 *
 * Registered via `alpine:init` before `Alpine.start()` in app.js so the
 * `x-data="..."` expressions used by the Blade component library resolve.
 */

document.addEventListener('alpine:init', () => {
    /**
     * Button/dropdown menu trigger. Click toggles, click-away and Escape close.
     */
    Alpine.data('dropdownMenu', () => ({
        open: false,
        toggle() {
            this.open = !this.open;
            this.$nextTick(() => {
                if (this.open) this.$refs.menu?.focus();
            });
        },
        close() {
            this.open = false;
        },
        onEscape() {
            if (this.open) this.close();
        },
    }));

    /**
     * Modal dialog. Locks body scroll while open, focuses the panel,
     * closes on backdrop click or Escape.
     */
    Alpine.data('modalDialog', (open = false, id = null) => ({
        open,
        id,
        init() {
            this.$watch('open', (value) => (value ? this.lock() : this.unlock()));
            window.addEventListener('bos:open-modal', (event) => {
                if (this.id == null || event.detail?.id === this.id) {
                    this.open = true;
                }
            });
            if (this.open) this.$nextTick(() => this.lock());
        },
        lock() {
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.panel?.focus());
        },
        unlock() {
            document.documentElement.classList.remove('overflow-hidden');
        },
        close() {
            if (this.open) {
                this.open = false;
                this.unlock();
            }
        },
    }));

    /**
     * Slide-in drawer anchored to the inline-end edge (RTL-aware positioning
     * handled by Tailwind `end-*` logical utilities + `inset-inline-end` transition).
     */
    Alpine.data('drawerPanel', (open = false, id = null) => ({
        open,
        id,
        init() {
            this.$watch('open', (value) => (value ? this.lock() : this.unlock()));
            window.addEventListener('bos:open-drawer', (event) => {
                if (this.id == null || event.detail?.id === this.id) {
                    this.open = true;
                }
            });
            if (this.open) this.$nextTick(() => this.lock());
        },
        lock() {
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => this.$refs.panel?.focus());
        },
        unlock() {
            document.documentElement.classList.remove('overflow-hidden');
        },
        close() {
            if (this.open) {
                this.open = false;
                this.unlock();
            }
        },
    }));

    /**
     * Tab navigation state. Panels opt in via `x-show="active === 'key'"`.
     */
    Alpine.data('tabSet', (initial = null) => ({
        active: initial,
        select(key) {
            this.active = key;
        },
    }));

    /**
     * Customer delete dialog (Batch 10). Lives inside the modal slot and
     * receives the row to delete via the `bos:delete-customer` custom event.
     * `customerId` drives both the template (x-if) and the form action, so a
     * single modal serves every row on the index page without per-row markup.
     *
     * `close` is intentionally NOT defined here: it resolves to the parent
     * modalDialog scope through Alpine's scope inheritance.
     */
    Alpine.data('deleteCustomerDialog', () => ({
        customerId: null,
        init() {
            window.addEventListener('bos:delete-customer', (event) => {
                this.customerId = event.detail?.id ?? null;
            });
        },
    }));

    /**
     * Category delete dialog (Batch 11). Mirrors the Batch 10 customer dialog:
     * one shared modal listens for `bos:delete-category`, and `categoryId`
     * drives both the x-if template and the dynamic form action.
     */
    Alpine.data('deleteCategoryDialog', () => ({
        categoryId: null,
        init() {
            window.addEventListener('bos:delete-category', (event) => {
                this.categoryId = event.detail?.id ?? null;
            });
        },
    }));

    /**
     * Unit delete dialog (Batch 11). Mirrors the customer dialog; `unitId`
     * drives the x-if template and the dynamic form action.
     */
    Alpine.data('deleteUnitDialog', () => ({
        unitId: null,
        init() {
            window.addEventListener('bos:delete-unit', (event) => {
                this.unitId = event.detail?.id ?? null;
            });
        },
    }));

    /**
     * Tax delete dialog (Batch 11). Mirrors the customer dialog; `taxId`
     * drives the x-if template and the dynamic form action.
     */
    Alpine.data('deleteTaxDialog', () => ({
        taxId: null,
        init() {
            window.addEventListener('bos:delete-tax', (event) => {
                this.taxId = event.detail?.id ?? null;
            });
        },
    }));

    /**
     * Product/service delete dialog (Batch 12). Mirrors the catalog dialogs;
     * `productId` drives the x-if template and the dynamic form action.
     */
    Alpine.data('deleteProductDialog', () => ({
        productId: null,
        init() {
            window.addEventListener('bos:delete-product', (event) => {
                this.productId = event.detail?.id ?? null;
            });
        },
    }));

    /**
     * Quotation delete dialog (Batch 14). Mirrors the Batch 12 product dialog;
     * `quotationId` drives the x-if template and the dynamic form action.
     */
    Alpine.data('deleteQuotationDialog', () => ({
        quotationId: null,
        init() {
            window.addEventListener('bos:delete-quotation', (event) => {
                this.quotationId = event.detail?.id ?? null;
            });
        },
    }));

    /**
     * Invoice delete dialog (Batch 15). Mirrors the quotation dialog: receives
     * the invoice id via a window event and renders the delete confirmation
     * form against /invoices/{id}. Draft invoices only — the service guard
     * rejects any later status with 403.
     */
    Alpine.data('deleteInvoiceDialog', () => ({
        invoiceId: null,
        init() {
            window.addEventListener('bos:delete-invoice', (event) => {
                this.invoiceId = event.detail?.id ?? null;
            });
        },
    }));

    /**
     * Payment reversal dialog (Batch 16). Mirrors the delete dialogs: a single
     * shared modal listens for `bos:reverse-payment` and `paymentId` + the
     * payment number drive both the confirmation text and the POST form action
     * against /payments/{id}/reverse. Reversing marks the payment (never
     * deletes it) and reconciles the target invoice server-side.
     */
    Alpine.data('reversePaymentDialog', () => ({
        paymentId: null,
        paymentNumber: null,
        init() {
            window.addEventListener('bos:reverse-payment', (event) => {
                this.paymentId = event.detail?.id ?? null;
                this.paymentNumber = event.detail?.number ?? null;
            });
        },
    }));

    /**
     * Expense delete dialog (Batch 17). Mirrors the invoice dialog; `expenseId`
     * drives the x-if template and the dynamic DELETE form action. Expenses
     * are soft-deleted server-side — the number and receipt remain as the
     * financial audit artifact.
     */
    Alpine.data('deleteExpenseDialog', () => ({
        expenseId: null,
        init() {
            window.addEventListener('bos:delete-expense', (event) => {
                this.expenseId = event.detail?.id ?? null;
            });
        },
    }));

    /**
     * Quotation line editor (Batch 14). Maintains the dynamic item rows of the
     * create/edit forms: each row holds product_id (optional), description,
     * quantity, unit_price and tax_id (optional). The Blob Product catalogue
     * is provided as `catalog` and `productsById` for prefilling the product
     * name/price/tax into a new row.
     *
     * Totals recomputed here are PREVIEW ONLY (non-authoritative): the server
     * recomputes everything through the QuotationCalculator on submit.
     */
    Alpine.data('quotationEditor', (catalog = [], taxesEnabled = false, initial = []) => ({
        rows: initial.length > 0
            ? initial.map((row) => ({ ...row }))
            : [{ product_id: '', description: '', quantity: '1', unit_price: '', tax_id: '' }],
        taxesEnabled,
        availableProducts: catalog,
        productsById: catalog.reduce((map, product) => {
            map[product.id] = product;
            return map;
        }, {}),
        addRow() {
            this.rows.push({ product_id: '', description: '', quantity: '1', unit_price: '', tax_id: '' });
        },
        removeRow(index) {
            if (this.rows.length > 1) {
                this.rows.splice(index, 1);
            }
        },
        pickProduct(row) {
            const product = this.productsById[row.product_id];
            if (!product) {
                return;
            }
            row.description = product.name;
            row.unit_price = product.sale_price;
            if (this.taxesEnabled && product.tax_id && !row.tax_id) {
                row.tax_id = product.tax_id;
            }
        },
        get subtotal() {
            return this.rows.reduce((sum, row) => {
                const qty = parseFloat(row.quantity) || 0;
                const price = parseFloat(row.unit_price) || 0;
                return sum + qty * price;
            }, 0);
        },
        get tax() {
            if (!this.taxesEnabled) {
                return 0;
            }
            return this.rows.reduce((sum, row) => {
                const rate = parseFloat(row.tax_rate) || 0;
                const qty = parseFloat(row.quantity) || 0;
                const price = parseFloat(row.unit_price) || 0;
                return sum + (qty * price * rate) / 100;
            }, 0);
        },
        get discount() {
            return 0;
        },
        format(value) {
            return Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 4,
            });
        },
    }));

    /**
     * Application theme toggle. The workspace preference provides the initial
     * theme; a device-local override is stored after the user toggles it.
     */
    Alpine.data('themeSwitcher', () => ({
        get dark() {
            return document.documentElement.classList.contains('dark');
        },
        toggle() {
            const dark = !this.dark;
            document.documentElement.classList.toggle('dark', dark);

            try {
                localStorage.setItem('bos-theme', dark ? 'dark' : 'light');
            } catch {
                // Keep the in-memory theme even when storage is unavailable.
            }
        },
    }));

    /**
     * Toast stack. `push()` adds a toast that auto-dismisses after `duration`.
     */
    Alpine.data('toastStack', () => ({
        items: [],
        push({
            type = 'success',
            title = null,
            message = '',
            duration = 5000,
        } = {}) {
            const id = Date.now() + Math.random();
            this.items.push({ id, type, title, message, duration });
            if (duration > 0) {
                setTimeout(() => this.remove(id), duration);
            }
        },
        remove(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    }));
});