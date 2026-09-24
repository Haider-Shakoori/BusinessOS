# BusinessOS — CodeCanyon Release Plan

## Product Requirements

### Minimum PHP Version
- PHP 8.2+ (prefer 8.3)

### Server Requirements
- MySQL 8.0+ or MariaDB 10.6+
- Apache or Nginx
- mod_rewrite enabled
- PHP Extensions: pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, gd (for PDF), zip
- PHP memory_limit: 256M minimum
- PHP max_execution_time: 60 seconds

### Shared Hosting Compatibility
- Works without CLI access
- No Redis/Memcached required
- No Docker required
- No supervisor required
- Database queue driver (sync fallback)
- File-based caching as alternative

---

## Web Installer

### Installation Steps

#### Step 1: Welcome
- Product name and version
- Brief description
- "Get Started" button
- License agreement link

#### Step 2: Server Requirements Check
- PHP version check (>= 8.2)
- Extension checks:
  - pdo_mysql ✓
  - mbstring ✓
  - openssl ✓
  - tokenizer ✓
  - xml ✓
  - ctype ✓
  - json ✓
  - bcmath ✓
  - gd ✓
  - zip ✓
- All checks pass → Continue
- Failures shown with clear instructions

#### Step 3: Directory Permissions Check
- storage/ — writable
- storage/app/ — writable
- storage/framework/ — writable
- storage/logs/ — writable
- bootstrap/cache/ — writable
- .env — writable or .env.example copyable
- Show clear instructions for fixing permissions

#### Step 4: Database Configuration
- Database host
- Database port (default 3306)
- Database name
- Database username
- Database password
- "Test Connection" button
- Connection success/error feedback

#### Step 5: Application Settings
- Application name
- Application URL
- Admin email
- Admin password

#### Step 6: Business Setup
- Business name
- Business type (Service, Retail, Wholesale, Manufacturing, Custom)
- Country
- Currency (auto-populated from country)
- Timezone
- Fiscal year start month

#### Step 7: Installation
- Run migrations
- Seed database
- Create admin user
- Create first business
- Setup default settings
- Progress indicator

#### Step 8: Completion
- Success message
- Login button
- Link to documentation
- Warning to delete installer directory

### Post-Installation Security
- Create `storage/app/installed` lock file
- Installer routes check for this file
- If installed, all installer routes redirect to home
- `.env` APP_KEY auto-generated during installation

---

## Demo Mode

### Configuration
```php
// .env
APP_DEMO_MODE=true
```

### Restrictions
1. Cannot delete admin users
2. Cannot change admin password
3. Cannot modify SMTP settings
4. Cannot change application key
5. Cannot access backup functionality
6. Cannot modify sensitive system settings
7. Data may reset daily
8. Demo banner displayed

### Implementation
Central `DemoRestriction` service. NOT scattered if-checks.

### Demo Data Seeders
```php
DatabaseSeeder:
  - BusinessSeeder (demo company)
  - UserSeeder (admin + staff)
  - CustomerSeeder (20 realistic customers)
  - SupplierSeeder (10 suppliers) # Phase 4 — added when supplier features ship
  - CategorySeeder (product + expense categories)
  - UnitSeeder (common units)
  - ProductSeeder (50 products)
  - QuotationSeeder (10 quotations)
  - InvoiceSeeder (30 invoices with various statuses)
  - PaymentSeeder (20 payments)
  - ExpenseSeeder (15 expenses)
```

### Demo Data Quality
- Realistic company names
- Realistic addresses
- Varied product types
- Mix of statuses (draft, sent, paid, overdue)
- Recent dates
- Professional appearance

---

## Documentation

### Included Files
```
docs/
├── installation.md      — Step-by-step installation guide
├── user-guide.md        — End-user documentation
├── admin-guide.md       — Administration guide
├── upgrade-guide.md     — Upgrade instructions
├── cron-setup.md        — Cron job setup instructions
├── queue-setup.md       — Queue worker setup (optional)
├── troubleshooting.md   — Common issues and solutions
├── faq.md               — Frequently asked questions
└── changelog.md         — Version history
```

### Installation Guide Content
1. System requirements
2. Upload files to server
3. Create database
4. Run web installer
5. Configure cron job
6. Set file permissions
7. Optional: Queue worker setup
8. First login and setup

### User Guide Content
- Getting started
- Business setup wizard
- Customer management
- Product management
- Creating quotations
- Creating invoices
- Recording payments
- Expense management
- Reports overview
- Settings
- Import/Export

### Admin Guide Content
- User management
- Role and permission setup
- Module activation
- System settings
- Numbering configuration
- Tax setup
- Invoice customization
- Backup procedures

---

## Packaging

### Directory Structure for Distribution
```
BusinessOS/
├── app/
├── bootstrap/
├── config/
├── database/
├── docs/
│   ├── installation.md
│   ├── user-guide.md
│   ├── admin-guide.md
│   ├── upgrade-guide.md
│   ├── cron-setup.md
│   ├── troubleshooting.md
│   ├── faq.md
│   └── changelog.md
├── public/
├── resources/
├── routes/
├── storage/
├── .env.example
├── composer.json
├── package.json
├── README.md
├── LICENSE.md
└── CHANGELOG.md
```

### Excluded from Distribution
- .git/
- node_modules/
- vendor/ (buyer runs composer install)
- .env (buyer creates via installer)
- storage/logs/*.log
- storage/framework/cache/*
- tests/ (optional)

---

## Versioning

### Format
```
MAJOR.MINOR.PATCH
```

- MAJOR: Breaking changes, major new features
- MINOR: New features, non-breaking
- PATCH: Bug fixes, security patches

### Initial Version
```
1.0.0 — First release
```

### Changelog Format
```markdown
## [1.0.0] - 2026-XX-XX

### Added
- Feature description

### Changed
- Change description

### Fixed
- Bug fix description

### Removed
- Removed feature
```

---

## Upgrade Process

### Strategy
1. Buyer downloads new version
2. Backs up current installation
3. Uploads new files (except .env and uploads)
4. Runs migration if database changes exist
5. Tests functionality

### Migration Safety
- All migrations are forward-only
- No destructive migrations
- Data preservation guaranteed
- Rollback instructions provided

### Upgrade Commands
```php
php artisan migrate --force
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

---

## Release Checklist

### Pre-Release
- [ ] All MVP features complete and tested
- [ ] Installer tested on fresh installation
- [ ] Demo mode verified
- [ ] Demo data seeds correctly
- [ ] Documentation complete
- [ ] Shared hosting installation tested
- [ ] Dark mode verified
- [ ] RTL support verified
- [ ] Mobile responsive verified
- [ ] PDF generation working
- [ ] Print layouts verified
- [ ] No hardcoded strings (localization ready)
- [ ] No debug code in production
- [ ] .env.example complete
- [ ] composer.json dependencies correct
- [ ] License file included
- [ ] README.md with screenshots

### Screenshots Needed
1. Dashboard (light mode)
2. Dashboard (dark mode)
3. Invoice list
4. Invoice editor/create
5. Invoice PDF output
6. Customer list
7. Customer detail
8. Product catalog
9. Customer statement / ledger
10. Reports
11. Settings
12. Module manager
13. Onboarding wizard
14. Mobile layout
15. Installer

### CodeCanyon Submission
- Product description with feature list
- Minimum 5 screenshots
- Feature tags
- Search keywords
- Live preview URL (demo site)
- Changelog
- Documentation links

---

## Buyer Customization

### Without Code Changes
Buyers can customize through UI:
- Business name and logo
- Theme accent color
- Invoice theme selection
- Currency and formatting
- Tax settings
- Date/time format
- Language
- Numbering prefixes
- Module activation
- Email settings
- Terms and notes templates

### With Code Changes (Documented)
- Custom invoice themes (Blade templates)
- Custom report templates
- Custom fields
- Custom payment methods
- Module extensions (hooks/events)

---

## Support Reduction Strategy

### Self-Service
1. Comprehensive installation guide
2. Video tutorial for installation
3. FAQ with common questions
4. Troubleshooting guide
5. Clear error messages
6. In-app help tooltips

### Code Quality
1. Clean, commented code
2. Consistent naming conventions
3. Follows Laravel conventions
4. Easy to understand for average Laravel developer
5. No unnecessary abstractions
6. Well-organized folder structure

### Common Support Issues Prevention
1. **Installation fails** → Detailed error messages, requirements checker
2. **Permission errors** → Clear permission requirements, installer checks
3. **Cron not working** → Setup guide with examples
4. **PDF not generating** → Requirements check (gd extension)
5. **Email not sending** → Email configuration guide
6. **Slow performance** → Optimization guide
7. **Data loss** → Backup guide, soft deletes on master data
