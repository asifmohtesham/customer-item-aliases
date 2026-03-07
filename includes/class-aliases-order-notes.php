<?php
defined( 'ABSPATH' ) || exit;

/**
 * CIA_Order_Notes
 *
 * Automatically appends alias information to WooCommerce order notes when a
 * customer with aliases purchases products.
 *
 * Runs on `woocommerce_new_order` and `woocommerce_checkout_order_created` hooks.
 * For each line item, if the customer has an active alias mapping to the product's
 * EAN code, adds a private order note listing the alias.
 *
 * Example order note:
 *   "Customer aliases used:
 *    - CUST-001 → Milanole Leather Sofa 3 Seater (EAN: 30000070)"
 */
class CIA_Order_Notes {

    public static function init(): void {
        add_action( 'woocommerce_new_order',             [ __CLASS__, 'maybe_add_alias_note' ], 20, 1 );
        add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'maybe_add_alias_note' ], 20, 1 );
    }

    /**
     * Check if the customer used any aliases for products in the order,
     * and if so, add a private order note.
     *
     * @param int|WC_Order $order_id_or_object
     */
    public static function maybe_add_alias_note( $order_id_or_object ): void {
        $order = $order_id_or_object instanceof WC_Order
            ? $order_id_or_object
            : wc_get_order( $order_id_or_object );

        if ( ! $order ) return;

        $customer_id = $order->get_customer_id();
        if ( ! $customer_id ) return;

        $ean_meta_key = (string) apply_filters( 'cia_ean_meta_key', CIA_EAN_META_KEY );
        $aliases_used = [];

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;

            $ean = $product->get_meta( $ean_meta_key, true );
            if ( empty( $ean ) ) continue;

            $alias = self::find_customer_alias_for_ean( $customer_id, $ean );
            if ( $alias ) {
                $aliases_used[] = sprintf(
                    '%s → %s (EAN: %s)',
                    $alias,
                    $product->get_name(),
                    $ean
                );
            }
        }

        if ( ! empty( $aliases_used ) ) {
            $note = __( 'Customer aliases used:', 'customer-item-aliases' ) . "\n"
                  . implode( "\n", array_map( static fn($s) => '- ' . $s, $aliases_used ) );

            $order->add_order_note( $note, false, true );
        }
    }

    /**
     * Find the active alias that maps to a given EAN for a customer.
     * Returns the first match (if multiple exist, picks the most recent).
     *
     * @param  int    $customer_id
     * @param  string $ean
     * @return string|null  The alias code or null.
     */
    private static function find_customer_alias_for_ean( int $customer_id, string $ean ): ?string {
        global $wpdb;
        $table = $wpdb->prefix . CIA_TABLE_ALIAS;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT alias_code
                 FROM {$table}
                 WHERE user_id   = %d
                   AND ean8_code = %s
                   AND is_active = 1
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY id DESC
                 LIMIT 1",
                $customer_id,
                $ean
            )
        );
    }
}
