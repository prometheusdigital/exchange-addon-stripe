<?php
/**
 * The following file contains utility functions specific to our stripe add-on
 * If you're building your own transaction-method addon, it's likely that you will
 * need to do similar things. This includes enqueueing scripts, formatting data for stripe, etc.
*/

/**
 * Adds actions to the plugins page for the iThemes Exchange Stripe plugin
 *
 * @since 1.0.0
 *
 * @param array $meta Existing meta
 * @param string $plugin_file the wp plugin slug (path)
 * @param array $plugin_data the data WP harvested from the plugin header
 * @param string $context 
 * @return array
*/
function it_exchange_stripe_plugin_row_actions( $actions, $plugin_file, $plugin_data, $context ) {
    
    $actions['setup_addon'] = '<a href="' . get_admin_url( NULL, 'admin.php?page=it-exchange-addons&add-on-settings=stripe' ) . '">' . __( 'Setup Add-on', 'LION' ) . '</a>';
    
    return $actions;
    
}
add_filter( 'plugin_action_links_exchange-addon-stripe/exchange-addon-stripe.php', 'it_exchange_stripe_plugin_row_actions', 10, 4 );

/**
 * Enqueues any scripts we need on the frontend during a stripe checkout
 *
 * @since 0.1.0
 *
 * @return void
*/
function it_exchange_stripe_addon_enqueue_script() {
    wp_enqueue_script( 'stripe', 'https://checkout.stripe.com/v2/checkout.js', array( 'jquery' ) );
    wp_enqueue_script( 'stripe-addon-js', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/js/stripe-addon.js', array( 'jquery' ) );
    wp_localize_script( 'stripe-addon-js', 'stripeAddonL10n', array(
            'processing_payment_text'  => __( 'Processing payment, please wait...', 'LION' ),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'it_exchange_stripe_addon_enqueue_script' );

/**
 * Grab the stripe customer ID for a WP user
 *
 * @since 0.1.0
 *
 * @param integer $customer_id the WP customer ID
 * @return integer
*/
function it_exchange_stripe_addon_get_stripe_customer_id( $customer_id ) {
    $settings = it_exchange_get_option( 'addon_stripe' );
    $mode     = ( $settings['stripe-test-mode'] ) ? '_test_mode' : '_live_mode';

    return get_user_meta( $customer_id, '_it_exchange_stripe_id' . $mode, true );
}

/**
 * Add the stripe customer ID as user meta on a WP user
 *
 * @since 0.1.0
 *
 * @param integer $customer_id the WP user ID
 * @param integer $stripe_id the stripe customer ID
 * @return boolean
*/
function it_exchange_stripe_addon_set_stripe_customer_id( $customer_id, $stripe_id ) {
    $settings = it_exchange_get_option( 'addon_stripe' );
    $mode     = ( $settings['stripe-test-mode'] ) ? '_test_mode' : '_live_mode';

    return update_user_meta( $customer_id, '_it_exchange_stripe_id' . $mode, $stripe_id );
}

/**
 * Grab a transaction from the stripe transaction ID
 *
 * @since 0.1.0
 *
 * @param integer $stripe_id id of stripe transaction
 * @return transaction object
*/
function it_exchange_stripe_addon_get_transaction_id( $stripe_id ) {
    $args = array(
        'meta_key'    => '_it_exchange_transaction_method_id',
        'meta_value'  => $stripe_id,
        'numberposts' => 1, //we should only have one, so limit to 1
    );
    return it_exchange_get_transactions( $args );
}

/**
 * Updates a stripe transaction status based on stripe ID
 *
 * @since 0.1.0
 *
 * @param integer $stripe_id id of stripe transaction
 * @param string $new_status new status
 * @return void
*/
function it_exchange_stripe_addon_update_transaction_status( $stripe_id, $new_status ) {
    $transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );
    foreach( $transactions as $transaction ) { //really only one
        $current_status = it_exchange_get_transaction_status( $transaction );
        if ( $new_status !== $current_status )
            it_exchange_update_transaction_status( $transaction, $new_status );
    }
}

/**
 * Adds a refund to post_meta for a stripe transaction
 *
 * @since 0.1.0
*/
function it_exchange_stripe_addon_add_refund_to_transaction( $stripe_id, $refund ) {

    // Stripe money format comes in as cents. Divide by 100.
    $refund = ( $refund / 100 );

    // Grab transaction
    $transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );
    foreach( $transactions as $transaction ) { //really only one

        $refunds = it_exchange_get_transaction_refunds( $transaction );

        $refunded_amount = 0;
        foreach( ( array) $refunds as $refund_meta ) {
            $refunded_amount += $refund_meta['amount'];
        }

        // In Stripe the Refund is the total amount that has been refunded, not just this transaction
        $this_refund = $refund - $refunded_amount;

        // This refund is already formated on the way in. Don't reformat.
        it_exchange_add_refund_to_transaction( $transaction, $this_refund );
    }
}

/**
 * Removes a stripe Customer ID from a WP user
 *
 * @since 0.1.0
 *
 * @param integer $stripe_id the id of the stripe transaction
*/
function it_exchange_stripe_addon_delete_stripe_id_from_customer( $stripe_id ) {
    $settings = it_exchange_get_option( 'addon_stripe' );
    $mode     = ( $settings['stripe-test-mode'] ) ? '_test_mode' : '_live_mode';

    $transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );
    foreach( $transactions as $transaction ) { //really only one
        $customer_id = get_post_meta( $transaction->ID, '_it_exchange_customer_id', true );
        if ( false !== $current_stripe_id = it_exchange_stripe_addon_get_stripe_customer_id( $customer_id ) ) {

            if ( $current_stripe_id === $stripe_id )
                delete_user_meta( $customer_id, '_it_exchange_stripe_id' . $mode );

        }
    }
}
