<?php
/**
 * Exchange Transaction Add-ons require several hooks in order to work properly. 
 * Most of these hooks are called in api/transactions.php and are named dynamically
 * so that individual add-ons can target them. eg: it_exchange_refund_url_for_stripe
 * We've placed them all in one file to help add-on devs identify them more easily
*/

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
 * This proccesses a stripe transaction.
 *
 * The it_exchange_do_transaction_[addon-slug] action is called when 
 * the site visitor clicks a specific add-ons 'purchase' button. It is
 * passed the default status of false along with the transaction object
 * The transaction object is a package of data describing what was in the user's cart
 *
 * Exchange expects your add-on to either return false if the transaction failed or to 
 * call it_exchange_add_transaction() and return the transaction ID
 *
 * @since 0.1.0
 *
 * @param string $status passed by WP filter.
 * @param object $transaction_object The transaction object
*/
function it_exchange_stripe_addon_process_transaction( $status, $transaction_object ) {

	// If this has been modified as true already, return.
	if ( $status )
		return $status;

	// Verify nonce
	if ( ! empty( $_REQUEST['_stripe_nonce'] ) && ! wp_verify_nonce( $_REQUEST['_stripe_nonce'], 'stripe-checkout' ) ) {
		it_exchange_add_message( 'error', __( 'Transaction Failed, unable to verify security token.', 'LION' ) );
		return false;
	}

	// Make sure we have the correct $_POST argument
	if ( ! empty( $_POST['stripeToken'] ) ) {

		try {

			$general_settings = it_exchange_get_option( 'settings_general' );
			$settings         = it_exchange_get_option( 'addon_stripe' );

			$secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
			Stripe::setApiKey( $secret_key );

			// Set stripe token
			$token = $_POST['stripeToken'];

			// Set stripe customer from WP customer ID
			$it_exchange_customer = it_exchange_get_current_customer();
			if ( $stripe_id = it_exchange_stripe_addon_get_stripe_customer_id( $it_exchange_customer->id ) )
				$stripe_customer = Stripe_Customer::retrieve( $stripe_id );

			// If the user has been deleted from Stripe, we need to create a new Stripe ID.
			if ( ! empty( $stripe_customer ) ) {
				if ( isset( $stripe_customer->deleted ) && true === $stripe_customer->deleted )
					$stripe_customer = array();
			}

			// If this user isn't an existing Stripe User, create a new Stripe ID for them...
			if ( ! empty( $stripe_customer ) ) {
				$stripe_customer->card = $token;
				$stripe_customer->email = $it_exchange_customer->data->user_email;
				$stripe_customer->save();
			} else {
				$customer_array = array(
					'email' => $it_exchange_customer->data->user_email,
					'card'  => $token,
				);

				// Creates a new Stripe ID for this customer
				$stripe_customer = Stripe_Customer::create( $customer_array );

				it_exchange_stripe_addon_set_stripe_customer_id( $it_exchange_customer->id, $stripe_customer->id );
			}

			// Now that we have a valid Customer ID, charge them!
			$charge = Stripe_Charge::create(array(
				'customer'    => $stripe_customer->id,
				'amount'      => number_format( $transaction_object->total, 2, '', '' ),
				'currency'    => $general_settings['default-currency'],
				'description' => $transaction_object->description,
			));
		}
		catch ( Exception $e ) {
			it_exchange_add_message( 'error', $e->getMessage() );
			return false;
		}
		return it_exchange_add_transaction( 'stripe', $charge->id, 'succeeded', $it_exchange_customer->id, $transaction_object );
	} else {
		it_exchange_add_message( 'error', __( 'Unknown error. Please try again later.', 'LION' ) );
	}
	return false;

}
add_action( 'it_exchange_do_transaction_stripe', 'it_exchange_stripe_addon_process_transaction', 10, 2 );

/**
 * Returns the button for making the payment
 *
 * Exchange will loop through activated Payment Methods on the checkout page
 * and ask each transaction method to return a button using the following filter:
 * - it_exchange_get_[addon-slug]_make_payment_button
 * Transaction Method add-ons must return a button hooked to this filter if they
 * want people to be able to make purchases.
 *
 * @since 0.1.0
 *
 * @param array $options
 * @return string HTML button
*/
function it_exchange_stripe_addon_make_payment_button( $options ) {

    if ( 0 >= it_exchange_get_cart_total( false ) )
        return;

    $general_settings = it_exchange_get_option( 'settings_general' );
    $stripe_settings = it_exchange_get_option( 'addon_stripe' );

    $publishable_key = ( $stripe_settings['stripe-test-mode'] ) ? $stripe_settings['stripe-test-publishable-key'] : $stripe_settings['stripe-live-publishable-key'];

    $products = it_exchange_get_cart_data( 'products' );

    $payment_form = '<form class="stripe_form" action="' . esc_attr( it_exchange_get_page_url( 'transaction' ) ) . '" method="post">';
    $payment_form .= '<input type="hidden" name="it-exchange-transaction-method" value="stripe" />';
    $payment_form .= wp_nonce_field( 'stripe-checkout', '_stripe_nonce', true, false );

    $payment_form .= '<div class="hide-if-no-js">';
    $payment_form .= '<input type="submit" class="it-exchange-stripe-payment-button" name="stripe_purchase" value="' . esc_attr( $stripe_settings['stripe-purchase-button-label'] ) .'" />';

    $payment_form .= '<script>' . "\n";
    $payment_form .= '  jQuery(".it-exchange-stripe-payment-button").click(function(){' . "\n";
    $payment_form .= '    var token = function(res){' . "\n";
    $payment_form .= '      var $stripeToken = jQuery("<input type=hidden name=stripeToken />").val(res.id);' . "\n";
    $payment_form .= '      jQuery("form.stripe_form").append($stripeToken).submit();' . "\n";
    $payment_form .= '      it_exchange_stripe_processing_payment_popup();' . "\n";
    $payment_form .= '    };' . "\n";
    $payment_form .= '    StripeCheckout.open({' . "\n";
    $payment_form .= '      key:         "' . esc_js( $publishable_key ) . '",' . "\n";
    $payment_form .= '      amount:      "' . esc_js( number_format( it_exchange_get_cart_total( false ), 2, '', '' ) ) . '",' . "\n";
    $payment_form .= '      currency:    "' . esc_js( $general_settings['default-currency'] ) . '",' . "\n";
    $payment_form .= '      name:        "' . empty( $general_settings['company-name'] ) ? '' : esc_js( $general_settings['company-name'] ) . '",' . "\n";
    $payment_form .= '      description: "' . esc_js( it_exchange_get_cart_description() ) . '",' . "\n";
    $payment_form .= '      panelLabel:  "Checkout",' . "\n";
    $payment_form .= '      token:       token' . "\n";
    $payment_form .= '    });' . "\n";
    $payment_form .= '    return false;' . "\n";
    $payment_form .= '  });' . "\n";
    $payment_form .= '</script>' . "\n";

    $payment_form .= '</form>';
    $payment_form .= '</div>';

    return $payment_form;
}
add_filter( 'it_exchange_get_stripe_make_payment_button', 'it_exchange_stripe_addon_make_payment_button', 10, 2 );

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
            return __( 'Disputed: Stripe needs a response', 'LION' );
        case 'under_review':
            return __( 'Disputed: Under review', 'LION' );
        case 'won':
            return __( 'Disputed: Won, Paid', 'LION' );
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
