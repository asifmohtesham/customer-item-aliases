# Customer Item Aliases for WooCommerce

> Map customer-provided item codes to master EAN8 product identifiers — enabling white-label / private-label product search in FluxStore and FiboSearch without exposing internal SKUs.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)
![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a?logo=woocommerce)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)

---

## The Problem

Your WooCommerce catalogue uses EAN8 codes as master product identifiers (also used in ERPNext). But for white-label and private-label customers, each customer calls the **same product by a different code**.

| Customer | Their Code | Your EAN8 |
|---|---|---|
| Customer A | `55012345` | `10000014` |
| Customer B | `AC-9921-X` | `10000014` |
| Customer C | `MY-BRAND-7` | `10000014` |

Without this plugin, a FluxStore or FiboSearch query for `55012345` returns **zero results** because that code doesn't exist in WooCommerce — only `10000014` does.

**Customer Item Aliases** intercepts every product search request, checks whether the search term is a registered alias for the authenticated user, and transparently substitutes the master EAN8 before WooCommerce executes the query.

---

## Features

- 🔍 **Alias-to-EAN8 translation** — transparent to the end customer
- 📱 **FluxStore support** — covers WC REST API v2, v3, and WooCommerce Store API (Blocks)
- 🔎 **FiboSearch Pro support** — alias resolution in autocomplete (gracefully skipped if FiboSearch is not installed)
- 🛡️ **Exact SKU matching** — uses `meta_query` on `_sku` for precise results, not broad keyword search
- 🗄️ **Dedicated custom table** — `{prefix}_customer_item_aliases` with composite index for fast lookups
- 🖥️ **Native WordPress Admin UI** — list, add, edit, and delete aliases via a WP_List_Table interface
- 🔒 **Security-first** — all queries use `$wpdb->prepare()`, all output is escaped, all forms use nonces
- ⚡ **Zero dependencies** — works standalone; FiboSearch is optional

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.0 or higher |
| WordPress | 6.0 or higher |
| WooCommerce | 7.0 or higher |
| FluxStore (MStore API) | Any (v2 / v3 / Store API) |
| FiboSearch Pro | Optional |

---

## Installation

### Manual

1. Download or clone this repository into `wp-content/plugins/customer-item-aliases/`
2. Activate the plugin via **WordPress Admin → Plugins**
3. The database table is created automatically on activation

### WP-CLI

```bash
cd wp-content/plugins
git clone https://github.com/asifmohtesham/customer-item-aliases.git
wp plugin activate customer-item-aliases
```

---

## File Structure

```
customer-item-aliases/
├── customer-item-aliases.php   ← Plugin bootstrap & constants
└── includes/
    ├── class-aliases-db.php        ← Database: table creation & all queries
    ├── class-aliases-table.php     ← WP_List_Table: admin list UI
    ├── class-aliases-admin.php     ← Admin page, form rendering, action handling
    └── class-aliases-hooks.php     ← Search interception hooks
```

---

## Database Schema

The plugin creates a single table on activation:

```sql
CREATE TABLE {prefix}_customer_item_aliases (
    id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT(20) UNSIGNED NOT NULL,   -- Matches wp_users.ID exactly
    alias_code  VARCHAR(100)        NOT NULL,   -- Customer-provided code (variable length)
    ean8_code   CHAR(8)             NOT NULL,   -- Always exactly 8 digits; stored as string to preserve leading zeros
    created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_user_alias (user_id, alias_code), -- Covering index for alias lookup query
    INDEX idx_ean8 (ean8_code)
);
```

---

## How It Works

### Search Interception

```
FluxStore app (Flutter)
    │
    ▼
GET /wp-json/wc/v2/products?search=55012345   ← Customer's alias
    │
    ▼ woocommerce_rest_product_query (CIA hook)
    │
    ├── Authenticated? Yes → look up alias in customer_item_aliases
    │       user_id=42, alias_code='55012345' → ean8_code='10000014'
    │
    ▼ Inject exact SKU meta_query, unset 'search'
    │
    ▼
WooCommerce returns product with SKU=10000014  ← Correct product
```

### Supported Search Pathways

| Pathway | Hook | Endpoint |
|---|---|---|
| FluxStore (WC REST v2) | `woocommerce_rest_product_query` | `/wp-json/wc/v2/products` |
| FluxStore (WC REST v3) | `woocommerce_rest_product_object_query` | `/wp-json/wc/v3/products` |
| FluxStore Pro / Blocks | `woocommerce_blocks_product_query_args` | `/wp-json/wc/store/v1/products` |
| FiboSearch Pro | `dgwt/wcas/search_query` | AJAX autocomplete |

FiboSearch support is registered **only if FiboSearch is detected as active**. The plugin works fully without it.

---

## Admin Interface

Navigate to **WordPress Admin → Item Aliases** to:

- **List** all aliases with sortable columns (ID, Customer, Alias Code, EAN8, Created)
- **Search** aliases by code
- **Add** new aliases — with a validated customer dropdown and 8-digit EAN8 input
- **Edit** existing aliases
- **Delete** single or bulk aliases

> The Customer field lists only WordPress users with the `customer` role. If no customers exist yet, a notification with a link to create one is shown instead of an empty dropdown.

---

## Usage Example

**Scenario:** Customer A (user ID 42) calls your product `10000014` by their code `55012345`.

1. Go to **Admin → Item Aliases → Add New**
2. Select **Customer A** from the dropdown
3. Enter `55012345` as the **Alias Code**
4. Enter `10000014` as the **EAN8 Code**
5. Click **Add Alias**

From this point, when Customer A searches `55012345` in FluxStore, the product `10000014` is returned — without Customer A knowing about the EAN8.

---

## Security

- All SQL queries use `$wpdb->prepare()` — no raw user input in queries
- All form submissions are validated with `check_admin_referer()` / `wp_nonce_field()`
- All output is escaped with `esc_html()`, `esc_attr()`, `esc_url()`
- EAN8 is validated server-side with `/^\d{8}$/` before insert/update
- Capability check (`manage_woocommerce`) on all admin actions
- Guest searches (unauthenticated users) bypass alias resolution entirely

---

## Hooks Reference

### Filters provided by this plugin

| Filter | Description |
|---|---|
| `cia/resolve_alias` | Allows overriding the alias resolution logic. Receives `$ean8` (string\|null), `$user_id` (int), `$alias` (string). |

Example:
```php
add_filter( 'cia/resolve_alias', function( $ean8, $user_id, $alias ) {
    // Override: resolve aliases from an external API instead
    return my_external_lookup( $user_id, $alias ) ?: $ean8;
}, 10, 3 );
```

---

## Changelog

### 1.0.0
- Initial release
- WC REST API v2, v3, and Store API hook coverage
- FiboSearch Pro optional integration
- WordPress Admin list table with add/edit/delete/bulk-delete
- EAN8 server-side validation
- Composite index on `(user_id, alias_code)` for fast lookups

---

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss the proposed change.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -m 'Add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request

Please follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) for PHP.

---

## License

This plugin is licensed under the **GNU General Public License v2.0 or later**.

See [LICENSE](./LICENSE) for the full licence text.

> Because this plugin is a derivative work of WordPress, it is required to be licensed under GPL-2.0-or-later in accordance with the [WordPress licence policy](https://wordpress.org/about/license/).
