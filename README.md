# Beltoft Smart Discounts for WooCommerce - Pro

Premium add-on for Beltoft Smart Discounts for WooCommerce with BOGO, bundles, role pricing, and analytics.

**Version:** 2.0.0
**Tested up to:** WordPress 6.9 / WooCommerce 9.6
**Requires:** Beltoft Smart Discounts for WooCommerce 1.0.0+, WooCommerce 8.0+, PHP 7.4+
**License:** GPL-2.0-or-later

## Pro Features

1. **Buy One Get One (BOGO)** -- Buy X items, get the cheapest free or discounted
2. **Bundle Discounts** -- Buy specific products together for a percentage off
3. **User Role Discounts** -- Different pricing for wholesale, VIP, or custom roles
4. **First-Order Discounts** -- Automatic percentage discount for new customers
5. **Cart Item Count Discounts** -- Discount when cart has X or more items
6. **Brand Targeting** -- Target discounts to specific WooCommerce Brands via the "Applies To" selector
7. **Advanced Conditions** -- Rules based on purchase history, location, day/time, payment method
8. **URL Discounts** -- Shareable discount links (`?bsdisc_discount=TOKEN`)
9. **Spending Goal Progress Bar** -- "Add $X more to get Y off!" in cart and checkout
10. **Countdown Timers** -- Urgency timers on product pages and cart for expiring deals
11. **Analytics Dashboard** -- Per-rule performance metrics with period filtering (7/30/90 days)
12. **CSV Import/Export** -- Bulk manage discount rules via CSV files
13. **Weekly Email Reports** -- Automated discount performance summary with CSV attachment
14. **Auto-Updates** -- Receive plugin updates directly from beltoft.net with a valid license

## Installation

1. Install and activate the free **Beltoft Smart Discounts for WooCommerce** plugin
2. Upload the `beltoft-smart-discounts-pro` folder to `/wp-content/plugins/`
3. Activate the Pro plugin through the Plugins menu
4. Go to **WooCommerce > Discounts > License** tab and enter your license key
5. Configure Pro settings in the **Pro Settings** tab

## FAQ

### Do I need the free plugin?

Yes. This Pro plugin is an add-on that extends the free Beltoft Smart Discounts for WooCommerce plugin.

### What happens when my license expires?

Pro features continue to work, but you will no longer receive plugin updates until you renew.

### Can I import rules from another site?

Yes. Export rules as CSV from one site and import them on another.

## Changelog

### 2.0.0
- Rebranded to Beltoft Smart Discounts for WooCommerce - Pro
- Switched to remote license validation via beltoft.net
- Added auto-update support for licensed installations
- Extracted inline JavaScript to external files (license.js, import.js)
- Updated all prefixes, namespaces, and text domain

### 1.0.2
- Fixed license activation/deactivation reliability

### 1.0.1
- Added brand targeting

### 1.0.0
- Initial release
- BOGO, bundle, role, first-order, and cart-count discount engines
- Advanced condition evaluator
- URL discount links
- Spending goal progress bar
- Countdown timer
- Analytics dashboard
- CSV import/export
- Weekly email reports
- HPOS compatibility
