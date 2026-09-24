# BusinessOS — UI Design System

## Design Philosophy

BusinessOS uses a completely custom-built admin interface. No admin templates, no third-party UI kits, no cloned dashboards. The visual identity must be original, premium, and professional.

### Design Principles
1. **Minimal** — Reduce visual noise
2. **Spacious** — Generous whitespace
3. **Consistent** — Uniform patterns across all modules
4. **Professional** — Premium SaaS feel
5. **Fast** — No heavy JavaScript frameworks
6. **Responsive** — Works on all screen sizes

---

## Technology

| Layer | Choice |
|-------|--------|
| CSS Framework | Tailwind CSS |
| Interactivity | Alpine.js |
| JavaScript | Vanilla JS (Fetch API) |
| Build Tool | Vite |
| Icons | Heroicons (SVG, inline) |
| Fonts | Inter (Google Fonts) or system font stack |

### Dark Mode
- Alpine.js stores dark mode preference in `localStorage`
- Tailwind `dark:` variant for all components
- Toggle in header, respects system preference on first visit

---

## Color System

### Brand Colors
```
Primary:    indigo-600  (#4f46e5)  — links, active states, primary actions
Primary:    indigo-50   (#eef2ff)  — primary backgrounds
Primary:    indigo-700  (#4338ca)  — primary hover
```

### Neutral Palette
```
Gray-50:    #f9fafb    — page background
Gray-100:   #f3f4f6    — card backgrounds, sidebar
Gray-200:   #e5e7eb    — borders, dividers
Gray-300:   #d1d5db    — disabled borders
Gray-400:   #9ca3af    — placeholder text
Gray-500:   #6b7280    — secondary text
Gray-600:   #4b5563    — body text
Gray-700:   #374151    — headings
Gray-800:   #1f2937    — dark headings
Gray-900:   #111827    — primary text
```

### Dark Mode Colors
```
Background: gray-900 (#111827)
Surface:    gray-800 (#1f2937)
Card:       gray-800 (#1f2937)
Border:     gray-700 (#374151)
Text:       gray-100 (#f3f4f6)
Secondary:  gray-400 (#9ca3af)
```

### Status Colors
```
Success:    emerald-500 / emerald-50   — paid, completed, active
Warning:    amber-500   / amber-50     — pending, partially paid, draft
Danger:     red-500     / red-50       — cancelled, overdue, error
Info:       blue-500    / blue-50      — informational, sent
Gray:       gray-500    / gray-50      — inactive, archived
```

---

## Typography

### Font Stack
```css
font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
```

### Scale
```
text-xs:    12px  — captions, labels
text-sm:    14px  — secondary text, table cells
text-base:  16px  — body text (default)
text-lg:    18px  — subheadings
text-xl:    20px  — page titles
text-2xl:   24px  — section headers
text-3xl:   30px  — dashboard stats
```

### Font Weights
```
font-normal:   400  — body text
font-medium:   500  — labels, emphasis
font-semibold: 600  — headings, buttons
font-bold:     700  — page titles
```

---

## Spacing System

Follow Tailwind spacing scale consistently:

```
p-1 / m-1:   4px   — tight internal padding
p-2 / m-2:   8px   — small gaps
p-3 / m-3:   12px  — card padding
p-4 / m-4:   16px  — standard padding
p-5 / m-5:   20px  — medium sections
p-6 / m-6:   24px  — large padding
p-8 / m-8:   32px  — page padding
gap-2:        8px   — grid gaps (compact)
gap-4:        16px  — grid gaps (standard)
gap-6:        24px  — grid gaps (spacious)
space-y-2:    8px   — vertical rhythm (compact)
space-y-4:    16px  — vertical rhythm (standard)
space-y-6:    24px  — vertical rhythm (spacious)
```

---

## Layout

### Page Shell
```
┌─────────────────────────────────────┐
│  Sidebar (collapsible) │  Header    │
│                       │            │
│  Navigation           │  Content   │
│  - Logo               │  Area      │
│  - Menu items         │            │
│  - Collapse button    │            │
│                       │            │
└─────────────────────────────────────┘
```

### Sidebar
- Width: 256px expanded, 64px collapsed (icons only)
- Background: white (light) / gray-900 (dark)
- Left border-right: gray-200
- Mobile: Full overlay with backdrop, slide from left
- Logo at top
- Navigation grouped by category
- Active item highlighted with primary color background
- Collapse toggle at bottom
- Scrollable when content exceeds viewport

### Header
- Height: 64px
- Background: white (light) / gray-800 (dark)
- Contains: breadcrumbs, search, notifications bell, user dropdown
- Sticky top
- Border bottom: gray-200
- Content container: max-width 1280px or full-width

### Content Area
- Padding: 24px (p-6)
- Max-width: 1280px centered, or full-width for data-heavy pages
- Background: gray-50 (light) / gray-900 (dark)

### Mobile Sidebar
- Overlay backdrop (black/50)
- Slide-in from left
- Close on backdrop click or X button
- Same navigation as desktop sidebar

---

## Component Standards

### Buttons

#### Primary Button
```html
class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
```

#### Secondary Button
```html
class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
```

#### Danger Button
```html
class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors"
```

#### Ghost Button
```html
class="inline-flex items-center px-4 py-2 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-100 transition-colors"
```

#### Button Sizes
- Small: `px-3 py-1.5 text-xs`
- Default: `px-4 py-2 text-sm`
- Large: `px-6 py-3 text-base`

---

### Cards

#### Standard Card
```html
class="bg-white rounded-xl border border-gray-200 shadow-sm"
```
Inner padding: `p-6`
Header: `px-6 py-4 border-b border-gray-200`
Body: `p-6`
Footer: `px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-xl`

#### Stat Card
```html
class="bg-white rounded-xl border border-gray-200 shadow-sm p-6"
```
Contains: icon, label (text-sm text-gray-500), value (text-2xl font-bold), trend indicator

---

### Forms

#### Input
```html
class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500"
```

#### Select
```html
class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
```

#### Textarea
```html
class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-y"
```

#### Checkbox
```html
class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
```

#### Toggle (Alpine.js)
Custom toggle component using Alpine.js for on/off switches.

#### Labels
```html
class="block text-sm font-medium text-gray-700 mb-1"
```

#### Required Indicator
Red asterisk: `<span class="text-red-500">*</span>`

#### Validation Errors
```html
class="mt-1 text-sm text-red-600"
```

#### Form Grid
- 2-column: `grid grid-cols-1 gap-6 sm:grid-cols-2`
- 3-column: `grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3`
- Full width: `col-span-full`

---

### Tables

#### Table Container
```html
class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden"
```

#### Table Header
```html
class="bg-gray-50 border-b border-gray-200"
class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
```

#### Table Body Row
```html
class="border-b border-gray-200 hover:bg-gray-50 transition-colors"
class="px-4 py-3 text-sm text-gray-900"
```

#### Table Responsive
Wrapper with `overflow-x-auto` for horizontal scrolling on mobile.

#### Table Actions
Action dropdown on the right side of each row using Alpine.js.

---

### Badges

#### Status Badges
```html
Paid:    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800"
Pending: class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800"
Draft:   class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800"
Overdue: class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"
Sent:    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"
```

---

### Modals

#### Modal Container
Fixed overlay: `fixed inset-0 z-50 overflow-y-auto bg-black/50 flex items-center justify-center p-4`
Modal panel: `bg-white rounded-2xl shadow-xl w-full max-w-lg`
Dark: `bg-gray-800 text-gray-100`

#### Modal Sizes
- Small: `max-w-md`
- Default: `max-w-lg`
- Large: `max-w-2xl`
- Extra Large: `max-w-4xl`

#### Modal Structure
- Header: `px-6 py-4 border-b border-gray-200` with title + close button
- Body: `px-6 py-4` scrollable
- Footer: `px-6 py-4 border-t border-gray-200` with action buttons

---

### Drawers

#### Drawer Container
Fixed panel: `fixed inset-y-0 right-0 z-50 w-full max-w-md bg-white shadow-xl transform transition`
Slide from right for mobile.
Background: `fixed inset-0 bg-black/30 z-40`

---

### Alerts

#### Success Alert
```html
class="rounded-lg bg-emerald-50 p-4 border border-emerald-200"
class="text-sm text-emerald-800"
```

#### Warning Alert
```html
class="rounded-lg bg-amber-50 p-4 border border-amber-200"
class="text-sm text-amber-800"
```

#### Danger Alert
```html
class="rounded-lg bg-red-50 p-4 border border-red-200"
class="text-sm text-red-800"
```

#### Info Alert
```html
class="rounded-lg bg-blue-50 p-4 border border-blue-200"
class="text-sm text-blue-800"
```

---

### Toasts (Notifications)
Position: Top-right corner
Alpine.js managed auto-dismiss
Variants: success, error, warning, info
Animation: slide in from right, fade out
Duration: 5 seconds auto-dismiss (error: manual dismiss)

---

### Tabs
```html
Tab container: flex border-b border-gray-200
Tab item: px-4 py-2.5 text-sm font-medium border-b-2 -mb-px
Active: border-indigo-500 text-indigo-600
Inactive: border-transparent text-gray-500 hover:text-gray-700
```

---

### Empty States
Centered content:
- Icon (large, gray-300)
- Title: `text-lg font-medium text-gray-900`
- Description: `text-sm text-gray-500 mt-1`
- Action button below

---

### Loading States

#### Spinner
```html
class="animate-spin h-5 w-5 text-indigo-600"
```

#### Skeleton
```html
class="animate-pulse bg-gray-200 rounded h-4"
```

#### Full Page Loading
Centered spinner in content area.

---

### Pagination
- Previous/Next buttons
- Page numbers with active indicator
- "Showing X to Y of Z results" text
- Server-side pagination for large datasets

---

### Dropdowns (Alpine.js)
- Trigger: click-based
- Close on outside click
- Position: below trigger, aligned right
- Max-height with scroll
- Dividers between groups
- Keyboard accessible

---

### Tooltips
- Position: above element, centered
- Delay: 300ms
- Background: gray-900
- Text: white, text-xs
- Arrow pointing to element

---

### Breadcrumbs
Separator: `/` (chevron-right icon preferred)
Current page: text-gray-900 font-medium
Links: text-gray-500 hover:text-gray-700

---

### Page Header
```
┌─────────────────────────────────────────┐
│ Page Title (text-2xl font-bold)         │
│ Page Description (text-sm text-gray-500)│
│                    [Action Buttons]      │
└─────────────────────────────────────────┘
```
- Title + description on left
- Action buttons on right
- `mb-6` bottom margin

---

### Filter Bar
- Horizontal row of filter controls
- Search input (left)
- Dropdown filters
- Date range picker
- Clear filters button
- Results count

---

## Responsive Breakpoints

```
sm:  640px   — Large phones, small tablets
md:  768px   — Tablets
lg:  1024px  — Small laptops
xl:  1280px  — Desktops
2xl: 1536px  — Large screens
```

### Mobile Adaptations
- Sidebar becomes overlay
- Tables become card/list view
- Forms stack to single column
- Modals become full-screen
- Action buttons move to bottom sheet or menu

---

## Blade Components

### Form Components
- `<x-input>` — Text input with label, error, hint
- `<x-number-input>` — Numeric input
- `<x-currency-input>` — Currency formatted input
- `<x-select>` — Dropdown select
- `<x-multi-select>` — Multi-select
- `<x-textarea>` — Multi-line text
- `<x-checkbox>` — Single checkbox
- `<x-radio>` — Radio button
- `<x-toggle>` — On/off switch
- `<x-date-input>` — Date picker wrapper
- `<x-file-upload>` — File upload with preview
- `<x-input-error>` — Validation error display
- `<x-input-group>` — Label + input + hint wrapper

### Layout Components
- `<x-card>` — Card container with header/body/footer
- `<x-stat-card>` — Dashboard stat card
- `<x-modal>` — Modal dialog
- `<x-drawer>` — Slide-in panel
- `<x-dropdown>` — Dropdown menu
- `<x-tabs>` — Tab navigation
- `<x-breadcrumb>` — Breadcrumb navigation
- `<x-page-header>` — Page title + actions

### Feedback Components
- `<x-alert>` — Alert message
- `<x-toast>` — Toast notification
- `<x-badge>` — Status badge
- `<x-confirm-dialog>` — Confirmation dialog
- `<x-empty-state>` — Empty state placeholder
- `<x-loading>` — Loading spinner
- `<x-skeleton>` — Skeleton placeholder

### Data Components
- `<x-table>` — Data table wrapper
- `<x-table-header>` — Table header
- `<x-table-row>` — Table row
- `<x-pagination>` — Pagination
- `<x-search-input>` — Search input with icon
- `<x-filter-bar>` — Filter controls bar
- `<x-action-menu>` — Row action dropdown
- `<x-avatar>` — User/entity avatar

### Utility Components
- `<x-button>` — Button with variants
- `<x-link>` — Styled link
- `<x-badge>` — Label/badge
- `<x-tooltip>` — Tooltip wrapper
- `<x-separator>` — Horizontal divider

---

## Icon System

Use Heroicons (https://heroicons.com) exclusively.

### Icon Sizes
- `h-4 w-4` — Inline with text
- `h-5 w-5` — Default button/menu icon
- `h-6 w-6` — Section headers
- `h-8 w-8` — Empty states
- `h-12 w-12` — Large decorative

### Icon Style
- Outline (stroke) for most UI icons
- Solid for active/selected states
- Consistent weight and sizing across app

---

## Animation Standards

- **Transitions:** `transition-colors duration-150` for hover states
- **Fade:** `transition-opacity duration-200` for overlays
- **Slide:** `transform transition duration-300` for drawers
- **Scale:** `transition transform duration-150` for modals
- **No heavy animations** — Performance-first

---

## Accessibility

- All form inputs have associated labels
- Focus rings on all interactive elements (`focus:ring-2 focus:ring-indigo-500`)
- `aria-label` on icon-only buttons
- Keyboard navigation support
- Color contrast meets WCAG AA
- Screen reader friendly table headers
- Skip navigation link
