@extends('layouts.showcase')

@section('content')
    {{-- Page header + breadcrumb --}}
    <x-ui.breadcrumb :items="[['label' => 'BusinessOS', 'url' => '/'], ['label' => 'UI Preview']]" />

    <div class="mt-4">
        <x-ui.page-header title="Design System — Batch 2" description="Reusable Tailwind + Blade + Alpine component library. Toggle dark mode from the header.">
            <x-slot:actions>
                <x-ui.button variant="ghost" x-data="themeSwitcher" x-on:click="toggle" icon="moon">Dark mode demo</x-ui.button>
                <x-ui.button variant="primary" icon="arrow-right">Primary action</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </div>

    {{-- Buttons --}}
    <section class="mt-10 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Buttons</h2>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button variant="primary">Primary</x-ui.button>
            <x-ui.button variant="secondary">Secondary</x-ui.button>
            <x-ui.button variant="outline">Outline</x-ui.button>
            <x-ui.button variant="ghost">Ghost</x-ui.button>
            <x-ui.button variant="danger">Danger</x-ui.button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button size="sm">Small</x-ui.button>
            <x-ui.button size="md">Medium</x-ui.button>
            <x-ui.button size="lg">Large</x-ui.button>
            <x-ui.button disabled>Disabled</x-ui.button>
            <x-ui.button loading>Loading…</x-ui.button>
            <x-ui.button icon="plus">With icon</x-ui.button>
            <x-ui.button href="#" icon="arrow-right">As link</x-ui.button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.icon-button label="Add item" icon="plus" />
            <x-ui.icon-button label="Edit item" icon="pencil-square" variant="ghost" />
            <x-ui.icon-button label="Delete item" icon="trash" variant="danger" />
            <x-ui.icon-button label="Settings" icon="cog" variant="ghost" />
        </div>
    </section>

    {{-- Forms --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Forms</h2>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.input name="email" type="email" label="Email address" placeholder="you@company.com" helper="We'll never share your email." />

            <x-ui.input name="company" label="Company name" required placeholder="Acme Inc." />

            <x-ui.input name="email-error" type="email" label="Email with error" placeholder="you@company.com" helper="Overridden by the error below." />

            <x-ui.input name="disabled" label="Disabled input" value="Cannot edit this" disabled />

            <x-ui.input name="readonly" label="Read-only input" value="Read only value" readonly />

            <x-ui.number-input name="qty" label="Quantity" required helper="Dashed helper text lives under the field." />

            <x-ui.search-input name="search" label="Search" placeholder="Search customers…" clearable />

            <x-ui.select name="status" label="Status" required placeholder="Select a status">
                <option value="active" @selected(old('status') === 'active')>Active</option>
                <option value="draft" @selected(old('status') === 'draft')>Draft</option>
                <option value="cancelled" @selected(old('status') === 'cancelled')>Cancelled</option>
            </x-ui.select>

            <x-ui.textarea name="notes" label="Notes" placeholder="Add internal notes…" helper="Visible only to staff." rows="3" />

            <div class="space-y-4">
                <x-ui.checkbox name="accept" label="I agree to the terms" description="Extra context about this option." checked />
                <x-ui.checkbox name="newsletter" label="Subscribe to product updates" />
                <x-ui.checkbox name="beta" label="Opt into beta features" disabled />
            </div>

            <div class="space-y-4">
                <x-ui.radio name="billing" value="monthly" label="Monthly billing" description="Billed every 30 days." checked />
                <x-ui.radio name="billing" value="annual" label="Annual billing" description="Two months free, billed yearly." />
            </div>

            <div class="space-y-4">
                <x-ui.toggle name="notifications" label="Email notifications" description="Receive weekly summaries." checked />
                <x-ui.toggle name="marketing" label="Marketing emails" description="Occasional product news." />
                <x-ui.toggle name="upgrades" label="Auto-upgrades" description="Disabled demo toggle." disabled />
            </div>
        </div>
    </section>

    {{-- Cards --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Cards & Stats</h2>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card title="Total revenue" value="$48,210.00" trend="+12.4% this month" icon="chart-bar" tone="brand" />
            <x-ui.stat-card title="Open invoices" value="18" hint="3 overdue" trend="-4.1% vs last month" :trend-up="false" icon="document-text" tone="danger" />
            <x-ui.stat-card title="Active customers" value="1,204" trend="+8.2% this quarter" icon="users" tone="success" />
            <x-ui.stat-card title="Pending payments" value="$6,980.50" hint="Awaiting settlement" icon="clock" tone="warning" />
        </div>

        <x-ui.card>
            <x-slot:header>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Card with sections</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Header, body and footer slots.</p>
                </div>
                <x-slot:actions>
                    <x-ui.button variant="secondary" size="sm">Edit</x-ui.button>
                </x-slot:actions>
            </x-slot:header>

            <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                Body content sits on a padded surface so modules never think about spacing. Cards stay consistent across light and dark mode.
            </p>

            <x-slot:footer>
                <p class="text-xs text-gray-500 dark:text-gray-400">Footer supports muted metadata like "Last synced 5 minutes ago".</p>
            </x-slot:footer>
        </x-ui.card>
    </section>

    {{-- Badges & statuses --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Badges & Statuses</h2>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.badge>Neutral</x-ui.badge>
            <x-ui.badge tone="brand">Brand</x-ui.badge>
            <x-ui.badge tone="success">Success</x-ui.badge>
            <x-ui.badge tone="warning">Warning</x-ui.badge>
            <x-ui.badge tone="danger">Danger</x-ui.badge>
            <x-ui.badge tone="info">Info</x-ui.badge>
            <x-ui.badge tone="success" dot>With dot</x-ui.badge>
            <x-ui.badge tone="danger" dot size="sm">Small</x-ui.badge>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.status-badge status="paid" />
            <x-ui.status-badge status="pending" />
            <x-ui.status-badge status="draft" />
            <x-ui.status-badge status="overdue" />
            <x-ui.status-badge status="sent" />
            <x-ui.status-badge status="active" />
            <x-ui.status-badge status="cancelled" />
            <x-ui.status-badge status="unknown_status" />
        </div>
    </section>

    {{-- Alerts --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Alerts</h2>

        <div class="grid grid-cols-1 gap-4">
            <x-ui.alert type="info" title="Heads up">This is information your users should know, gently.</x-ui.alert>
            <x-ui.alert type="success" title="Payment received">Invoice <strong>INV-2026-0001</strong> was marked as paid.</x-ui.alert>
            <x-ui.alert type="warning" title="Deprecated" dismissible>This endpoint changes in the next release.</x-ui.alert>
            <x-ui.alert type="danger" title="Something went wrong" dismissible>There was a problem saving your changes. Try again.</x-ui.alert>
            <x-ui.alert type="neutral">A neutral, tone-less note for helper context.</x-ui.alert>
        </div>
    </section>

    {{-- Toasts --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Toasts</h2>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            The toast component auto-dismisses after its duration. The stack below is an Alpine demonstration wired to the same visuals.
        </p>

        <div class="flex flex-wrap items-center gap-3" x-data="toastStack">
            <x-ui.button size="sm" x-on:click="$dispatch('demo-toast', { type: 'success', title: 'Saved', message: 'Your changes were saved.' })">Success</x-ui.button>
            <x-ui.button size="sm" variant="secondary" x-on:click="$dispatch('demo-toast', { type: 'info', title: 'Reminder', message: 'Your trial ends in 7 days.' })">Info</x-ui.button>
            <x-ui.button size="sm" variant="secondary" x-on:click="$dispatch('demo-toast', { type: 'warning', title: 'Low stock', message: 'SKU-0043 is below minimum.' })">Warning</x-ui.button>
            <x-ui.button size="sm" variant="danger" x-on:click="$dispatch('demo-toast', { type: 'danger', title: 'Failed', message: 'The export could not be generated.' })">Danger</x-ui.button>

            <div
                class="pointer-events-none fixed end-4 top-4 z-[60] flex w-full max-w-sm flex-col gap-2"
                x-on:demo-toast.window="push($event.detail)"
            >
                <template x-for="toast in items" :key="toast.id">
                    <div
                        x-data="{ show: true }"
                        x-init="toast.duration > 0 && setTimeout(() => { show = false }, toast.duration)"
                        x-show="show"
                        x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 translate-x-2"
                        x-transition:enter-end="opacity-100 translate-x-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        :class="{
                            'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300': toast.type === 'success',
                            'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300': toast.type === 'info',
                            'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300': toast.type === 'warning',
                            'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300': toast.type === 'danger',
                        }"
                        class="pointer-events-auto flex w-full items-start gap-3 rounded-xl border p-4 text-sm shadow-overlay"
                        role="status"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold" x-text="toast.title"></span>
                            <span class="mt-0.5 block" x-text="toast.message"></span>
                        </span>
                        <button type="button" class="shrink-0 rounded p-1 transition-colors hover:bg-black/5 dark:hover:bg-white/10" x-on:click="show = false" aria-label="Dismiss">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 18 18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </section>

    {{-- Overlays --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Modals, Drawer & Dropdown</h2>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button x-on:click="$dispatch('bos:open-modal', { id: 'modal-small' })">Small modal</x-ui.button>
            <x-ui.button x-on:click="$dispatch('bos:open-modal', { id: 'modal-default' })">Default modal</x-ui.button>
            <x-ui.button x-on:click="$dispatch('bos:open-modal', { id: 'modal-large' })">Large modal</x-ui.button>
            <x-ui.button x-on:click="$dispatch('bos:open-drawer', { id: 'drawer-demo' })">Open drawer</x-ui.button>

            <x-ui.dropdown label="Dropdown">
                <x-slot:trigger class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <x-ui.icon name="settings" class="size-4" />
                    <span x-show="true">Options</span>
                </x-slot:trigger>
                <x-slot:items>
                    <x-ui.dropdown-item href="#" icon="eye">View</x-ui.dropdown-item>
                    <x-ui.dropdown-item href="#" icon="pencil-square">Edit</x-ui.dropdown-item>
                    <x-ui.dropdown-item href="#" icon="document-text" variant="danger">Export</x-ui.dropdown-item>
                    <x-ui.dropdown-item x-on:click="open = false">Just a menu item</x-ui.dropdown-item>
                </x-slot:items>
            </x-ui.dropdown>
        </div>
    </section>

    {{-- Tabs --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Tabs</h2>

        <x-ui.card>
            <x-ui.tabs :items="['general' => 'General', 'billing' => 'Billing Settings', 'team' => 'Team Members']">
                <div x-show="active === 'general'">
                    <p class="text-sm text-gray-600 dark:text-gray-300">General settings live here.</p>
                </div>
                <div x-show="active === 'billing'">
                    <p class="text-sm text-gray-600 dark:text-gray-300">Billing settings live here.</p>
                </div>
                <div x-show="active === 'team'">
                    <p class="text-sm text-gray-600 dark:text-gray-300">Team members live here.</p>
                </div>
            </x-ui.tabs>
        </x-ui.card>
    </section>

    {{-- Table --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Table & Pagination</h2>

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>Invoice</x-ui.th>
                    <x-ui.th>Customer</x-ui.th>
                    <x-ui.th>Status</x-ui.th>
                    <x-ui.th numeric>Amount</x-ui.th>
                    <x-ui.th>Date</x-ui.th>
                </tr>
            </x-slot:head>
            <tr>
                <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">INV-2026-0001</x-ui.td>
                <x-ui.td>Acme Inc.</x-ui.td>
                <x-ui.td><x-ui.status-badge status="paid" /></x-ui.td>
                <x-ui.td numeric>$1,240.00</x-ui.td>
                <x-ui.td>Jan 2, 2026</x-ui.td>
            </tr>
            <tr>
                <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">INV-2026-0002</x-ui.td>
                <x-ui.td>Globex Corp</x-ui.td>
                <x-ui.td><x-ui.status-badge status="pending" /></x-ui.td>
                <x-ui.td numeric>$860.00</x-ui.td>
                <x-ui.td>Jan 5, 2026</x-ui.td>
            </tr>
            <tr>
                <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">INV-2026-0003</x-ui.td>
                <x-ui.td>Initech</x-ui.td>
                <x-ui.td><x-ui.status-badge status="overdue" /></x-ui.td>
                <x-ui.td numeric>$3,450.00</x-ui.td>
                <x-ui.td>Jan 9, 2026</x-ui.td>
            </tr>
            <tr>
                <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">INV-2026-0004</x-ui.td>
                <x-ui.td>Wayne Enterprises</x-ui.td>
                <x-ui.td><x-ui.status-badge status="draft" /></x-ui.td>
                <x-ui.td numeric>$2,130.00</x-ui.td>
                <x-ui.td>Jan 12, 2026</x-ui.td>
            </tr>
            <tr>
                <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">INV-2026-0005</x-ui.td>
                <x-ui.td>Stark Industries</x-ui.td>
                <x-ui.td><x-ui.status-badge status="sent" /></x-ui.td>
                <x-ui.td numeric>$1,575.00</x-ui.td>
                <x-ui.td>Jan 15, 2026</x-ui.td>
            </tr>
        </x-ui.table>

        <x-ui.pagination :paginator="$paginator" />

        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th colspan="1">Empty state inside a table</x-ui.th>
                </tr>
            </x-slot:head>
            <tr>
                <x-ui.td>
                    <x-ui.empty-state
                        title="No invoices found"
                        description="Try adjusting your filters, or create your first invoice to get started."
                        icon="document-text"
                    >
                        <x-slot:actions>
                            <x-ui.button size="sm" icon="plus">New invoice</x-ui.button>
                            <x-ui.button size="sm" variant="secondary">Clear filters</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                </x-ui.td>
            </tr>
        </x-ui.table>
    </section>

    {{-- Loading & Skeletons --}}
    <section class="mt-12 space-y-6">
        <h2 class="border-b border-gray-200 pb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400">Loading & Skeleton</h2>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card title="">
                <div class="space-y-3">
                    <x-ui.loading label="Loading reports…" />
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-ui.skeleton :lines="4" />
            </x-ui.card>

            <x-ui.card>
                <x-ui.skeleton block />
                <x-ui.skeleton block class="mt-3" />
            </x-ui.card>
        </div>
    </section>

    {{-- Overlay instances --}}
    <x-ui.modal id="modal-small" title="Small modal" description="Max width sm." size="sm">
        <p class="text-sm text-gray-600 dark:text-gray-300">Compact confirmations, short forms, quick actions.</p>
        <x-slot:footer>
            <x-ui.button variant="secondary" size="sm" x-on:click="close">Cancel</x-ui.button>
            <x-ui.button size="sm" x-on:click="close">Confirm</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal id="modal-default" title="Default modal" description="The workhorse modal size.">
        <p class="text-sm text-gray-600 dark:text-gray-300">This panel is keyboard-friendly: Escape closes it, the backdrop closes it, and the panel receives focus on open. Body scroll is locked while open.</p>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="close">Cancel</x-ui.button>
            <x-ui.button x-on:click="close">Save changes</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal id="modal-large" title="Large modal" description="Full-height content such as record forms." size="lg">
        <div class="space-y-4">
            <x-ui.input name="large-name" label="Full name" placeholder="Jane Cooper" />
            <x-ui.textarea name="large-notes" label="Notes" rows="5" placeholder="Optional internal notes…" />
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="close">Cancel</x-ui.button>
            <x-ui.button x-on:click="close">Save record</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.drawer id="drawer-demo" title="Drawer" description="Slides in from the inline-end edge.">
        <div class="space-y-4">
            <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                Drawers suit longer secondary content: audit trails, activity panels, quick-create forms.
            </p>
            <x-ui.input name="drawer-name" label="Customer name" placeholder="Acme Inc." />
            <x-ui.textarea name="drawer-notes" label="Notes" rows="4" placeholder="Anything worth remembering…" />
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="close">Cancel</x-ui.button>
            <x-ui.button x-on:click="close">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>
@endsection