<?php
/**
 * Exchange Transaction Add-ons require several hooks in order to work properly.
 * Most of these hooks are called in api/transactions.php and are named dynamically
 * so that individual add-ons can target them. eg: it_exchange_refund_url_for_stripe
 * We've placed them all in one file to help add-on devs identify them more easily
*/

add_action( 'it_exchange_register_gateways', function( ITE_Gateways $gateways ) {
	require_once dirname( __FILE__ ) . '/handlers/class.purchase-helper.php';
	require_once dirname( __FILE__ ) . '/handlers/class.purchase.php';
	require_once dirname( __FILE__ ) . '/handlers/class.purchase-dialog.php';
	require_once dirname( __FILE__ ) . '/handlers/class.tokenize.php';
	require_once dirname( __FILE__ ) . '/handlers/class.webhook.php';
	require_once dirname( __FILE__ ) . '/handlers/class.refund.php';
	require_once dirname( __FILE__ ) . '/handlers/class.update-subscription-payment-method.php';
	require_once dirname( __FILE__ ) . '/handlers/class.pause-subscription.php';
	require_once dirname( __FILE__ ) . '/handlers/class.resume-subscription.php';
	require_once dirname( __FILE__ ) . '/handlers/class.cancel-subscription.php';
	$gateways::register( new IT_Exchange_Stripe_Gateway() );
} );

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
 * Enqueues admin scripts on Settings page
 *
 * @since 1.1.24
 *
 * @return void
*/
function it_exchange_stripe_addon_admin_enqueue_script( $hook ) {
	if ( 'exchange_page_it-exchange-addons' === $hook )
	    wp_enqueue_style( 'stripe-addon-settings-css', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/css/settings.css' );
}
add_action( 'admin_enqueue_scripts', 'it_exchange_stripe_addon_admin_enqueue_script' );

/**
 * Enqueues any scripts we need on the frontend during a stripe checkout
 *
 * @since 0.1.0
 *
 * @return void
*/
function it_exchange_stripe_addon_enqueue_script() {

	if (
		! it_exchange_in_superwidget() &&
		! it_exchange_is_page( 'checkout' ) &&
		! IT_Exchange_SW_Shortcode::has_shortcode() &&
	    ! it_exchange_is_page( 'product' )
	) {
		return;
	}

    wp_enqueue_script( 'stripe', 'https://checkout.stripe.com/v2/checkout.js', array( 'jquery', 'it-exchange-event-manager' ) );
    wp_enqueue_script( 'stripe-addon-js', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/js/stripe-addon.js', array( 'jquery', 'it-exchange-event-manager' ) );
    wp_localize_script( 'stripe-addon-js', 'stripeAddonL10n', array(
            'processing_payment_text'  => __( 'Processing payment, please wait...', 'LION' ),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'it_exchange_stripe_addon_enqueue_script' );

/**
 * Stripe URL to perform refunds
 *
 * The it_exchange_refund_url_for_[addon-slug] filter is
 * used to generate the link for the 'Refund Transaction' button
 * found in the admin under Customer Payments
 *
 * @since 0.1.0
 *
 * @param string $url passed by WP filter.
 * @param string $url transaction URL
*/
function it_exchange_refund_url_for_stripe( $url ) {
	return 'https://manage.stripe.com/';
}
add_filter( 'it_exchange_refund_url_for_stripe', 'it_exchange_refund_url_for_stripe' );

/**
 * Gets the interpretted transaction status from valid stripe transaction statuses
 *
 * Most gateway transaction stati are going to be lowercase, one word strings.
 * Hooking a function to the it_exchange_transaction_status_label_[addon-slug] filter
 * will allow add-ons to return the human readable label for a given transaction status.
 *
 * @since 0.1.0
 *
 * @param string $status the string of the stripe transaction
 * @return string translaction transaction status
*/
function it_exchange_stripe_addon_transaction_status_label( $status ) {
    switch ( $status ) {
        case 'succeeded':
            return __( 'Paid', 'LION' );
        case 'refunded':
            return __( 'Refunded', 'LION' );
        case 'partial-refund':
            return __( 'Partially Refunded', 'LION' );
        case 'needs_response':
            return __( 'Disputed: Response Needed', 'LION' );
        case 'under_review':
            return __( 'Disputed: Under Review', 'LION' );
        case 'won':
            return __( 'Disputed: Won, Paid', 'LION' );
        case 'cancelled':
            return __( 'Cancelled', 'LION' );
        default:
            return __( 'Unknown', 'LION' );
    }
}
add_filter( 'it_exchange_transaction_status_label_stripe', 'it_exchange_stripe_addon_transaction_status_label' );

/**
 * Returns a boolean. Is this transaction a status that warrants delivery of any products attached to it?
 *
 * Just because a transaction gets added to the DB doesn't mean that the admin is ready to give over
 * the goods yet. Each payment gateway will have different transaction stati. Exchange uses the following
 * filter to ask transaction-methods if a current status is cleared for delivery. Return true if the status
 * means its okay to give the download link out, ship the product, etc. Return false if we need to wait.
 * - it_exchange_[addon-slug]_transaction_is_cleared_for_delivery
 *
 * @since 0.4.2
 *
 * @param boolean $cleared passed in through WP filter. Ignored here.
 * @param object $transaction
 * @return boolean
*/
function it_exchange_stripe_transaction_is_cleared_for_delivery( $cleared, $transaction ) {
    $valid_stati = array( 'succeeded', 'partial-refund', 'won' );
    return in_array( it_exchange_get_transaction_status( $transaction ), $valid_stati );
}
add_filter( 'it_exchange_stripe_transaction_is_cleared_for_delivery', 'it_exchange_stripe_transaction_is_cleared_for_delivery', 10, 2 );

/**
 * Mark this transaction method as okay to manually change transactions
 *
 * @since 1.1.36 
*/
add_filter( 'it_exchange_stripe_transaction_status_can_be_manually_changed', '__return_true' );

/**
 * Returns status options
 *
 * @since 1.1.36 
 * @return array
*/
function it_exchange_stripe_get_default_status_options() {
	$options = array(
		'succeeded'       => _x( 'Paid', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'refunded'        => _x( 'Refunded', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'partial-refund'  => _x( 'Partially Refunded', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'cancelled'       => _x( 'Cancelled', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'needs_response'  => _x( 'Disputed: Stripe needs a response', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'under_review'    => _x( 'Disputed: Under review', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
		'won'             => _x( 'Disputed: Won, Paid', 'Transaction Status', 'it-l10n-ithemes-exchange' ),
	);
	return $options;
}
add_filter( 'it_exchange_get_status_options_for_stripe_transaction', 'it_exchange_stripe_get_default_status_options' );

