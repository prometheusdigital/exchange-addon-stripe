<?php
/**
 * The following file contains utility functions specific to our stripe add-on
 * If you're building your own transaction-method addon, it's likely that you will
 * need to do similar things. This includes enqueueing scripts, formatting data for stripe, etc.
*/

/**
 * Converts a transaction ID from the temporary subscription ID to the permanent charge ID
 * 
 * @since 1.1.0
 *
 * @param \Stripe\StripeObject $stripe_object Stripe Event Object
 *
 * @return string
*/
function it_exchange_stripe_addon_convert_get_subscriber_id( $stripe_object ) {

	if ( isset( $stripe_object->id ) && strpos( $stripe_object->id, 'sub' ) === 0 ) {
		return $stripe_object->id;
	}

	$subscriber_id = false;
	foreach( $stripe_object->lines->data as $invoice_line ) {
		if ( 'subscription' === $invoice_line->type ) {
			$subscriber_id = $invoice_line->id;
			continue;
		}
	}
	return $subscriber_id;
}

/**
 * Converts a transaction ID from the temporary subscription ID to the permanent charge ID
 * 
 * @since 1.1.0
 *
 * @param string $stripe_subscription_id
 * @param string $stripe_charge_id
*/
function it_exchange_stripe_addon_convert_subscription_id_to_charge_id( $stripe_subscription_id, $stripe_charge_id ) {
	$transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_subscription_id );
	foreach( $transactions as $transaction ) { //really only one
		it_exchange_update_transaction_method_id( $transaction, $stripe_charge_id );
		do_action( 'it_exchange_update_transaction_subscription_id', $transaction, $stripe_subscription_id );
	}
}

/**
 * Add a new transaction, really only used for subscription payments.
 * If a subscription pays again, we want to create another transaction in Exchange
 * This transaction needs to be linked to the parent transaction.
 *
 * @since 1.3.0
 * @since 1.11.0 Added $invoice parameter.
 *
 * @param integer         $stripe_id id of paypal transaction
 * @param string          $payment_status new status
 * @param string|bool     $subscriber_id Optionally, specify the subscriber ID.
 * @param int             $amount Amount of the child transaction in cents.
 * @param \Stripe\Invoice $invoice
 *
 * @return bool
*/
function it_exchange_stripe_addon_add_child_transaction( $stripe_id, $payment_status, $subscriber_id = false, $amount, $invoice ) {
	$transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );

	if ( ! empty( $transactions ) ) {
		//this transaction DOES exist, don't try to create a new one, just update the status
		it_exchange_stripe_addon_update_transaction_status( $stripe_id, $payment_status );

		return false;
	}

	if ( ( $discount = $invoice->discount ) && $discount->coupon ) {
		if ( $discount->coupon->id === IT_Exchange_Stripe_Pause_Subscription_Request_Handler::COUPON ) {
			return false;
		}
	}

	$parent = null;

	$transactions = it_exchange_stripe_addon_get_transaction_id_by_subscriber_id( $subscriber_id );

	foreach ( $transactions as $transaction ) { //really only one
		$parent = $transaction;
	}

	if ( ! $parent ) {
		return false;
	}

	$args = array();

	$charge = \Stripe\Charge::retrieve( $invoice->charge );

	if ( $charge && $charge->source ) {
		$token = ITE_Payment_Token::query()
			->where( array( 'gateway' => 'stripe', 'token' => $charge->source->id ) )
			->first();

		if ( $token ) {
			$args['payment_token'] = $token->get_pk();
		}
	}

	it_exchange_add_subscription_renewal_payment( $parent, $stripe_id, $payment_status, $amount / 100, $args );

	return true;
}

/**
 * Grab the stripe customer ID for a WP user
 *
 * @since 0.1.0
 *
 * @param int|IT_Exchange_Customer $customer the WP customer ID
 * @param string  $mode
 *
 * @return string
*/
function it_exchange_stripe_addon_get_stripe_customer_id( $customer, $mode = '' ) {

	$customer_id = $customer instanceof IT_Exchange_Customer ? $customer->get_ID() : $customer;
	$gateway     = ITE_Gateways::get( 'stripe' );

	if ( ! $mode ) {
		$mode = $gateway->is_sandbox_mode() ? IT_Exchange_Transaction::P_MODE_SANDBOX : IT_Exchange_Transaction::P_MODE_LIVE;
	}

	$suffix = $mode === IT_Exchange_Transaction::P_MODE_SANDBOX ? '_test_mode' : '_live_mode';

    return get_user_meta( $customer_id, '_it_exchange_stripe_id' . $suffix, true );
}

/**
 * Add the stripe customer ID as user meta on a WP user
 *
 * @since 0.1.0
 *
 * @param integer $customer_id the WP user ID
 * @param integer $stripe_id the stripe customer ID
 * @param string  $mode
 *
 * @return bool
*/
function it_exchange_stripe_addon_set_stripe_customer_id( $customer_id, $stripe_id, $mode = '' ) {

	$gateway = ITE_Gateways::get( 'stripe' );

	if ( ! $mode ) {
		$mode = $gateway->is_sandbox_mode() ? IT_Exchange_Transaction::P_MODE_SANDBOX : IT_Exchange_Transaction::P_MODE_LIVE;
	}

	$suffix = $mode === IT_Exchange_Transaction::P_MODE_SANDBOX ? '_test_mode' : '_live_mode';

    return (bool) update_user_meta( $customer_id, '_it_exchange_stripe_id' . $suffix, $stripe_id );
}

/**
 * Add the stripe customer's subscription ID as user meta on a WP user
 *
 * @since 1.1.0
 *
 * @param integer $customer_id the WP user ID
 * @param integer $subscription_id the stripe customer's subscription ID
 *
 * @return boolean
*/
function it_exchange_stripe_addon_set_stripe_customer_subscription_id( $customer_id, $subscription_id ) {
    $settings = it_exchange_get_option( 'addon_stripe' );
    $mode     = ( $settings['stripe-test-mode'] ) ? '_test_mode' : '_live_mode';

    return update_user_meta( $customer_id, '_it_exchange_stripe_subscription_id' . $mode, $subscription_id );
}

/**
 * Grab a transaction from the stripe transaction ID
 *
 * @since 0.1.0
 *
 * @param string $stripe_id id of stripe transaction
 *
 * @return IT_Exchange_Transaction[]
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
 * Grab a transaction from the stripe subscriber ID
 *
 * @since 1.1.0
 *
 * @param string $subscriber_id id of stripe transaction
 *
 * @return IT_Exchange_Transaction[]
*/
function it_exchange_stripe_addon_get_transaction_id_by_subscriber_id( $subscriber_id ) {
    $args = array(
        'meta_key'    => '_it_exchange_transaction_subscriber_id',
        'meta_value'  => $subscriber_id,
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
 *                           
 * @return bool
*/
function it_exchange_stripe_addon_update_transaction_status( $stripe_id, $new_status ) {
    $transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );
    foreach( $transactions as $transaction ) { //really only one
        $current_status = it_exchange_get_transaction_status( $transaction );
        if ( $new_status !== $current_status )
            it_exchange_update_transaction_status( $transaction, $new_status );
		return true;
    }
	return false;
}

/**
 * Updates a stripe customer's subscription status based on subscription ID
 *
 * @since 1.1.0
 *
 * @param string $subscriber_id id of stripe subscription
 * @param string $status new status
*/
function it_exchange_stripe_addon_update_subscriber_status( $subscriber_id, $status ) {
	$transactions = it_exchange_stripe_addon_get_transaction_id_by_subscriber_id( $subscriber_id );

	foreach ( $transactions as $transaction ) { //really only one

		$subscription = it_exchange_get_subscription_by_transaction( it_exchange_get_transaction( $transaction ) );

		if ( $subscription->get_status() === IT_Exchange_Subscription::STATUS_CANCELLED && $status === 'cancelled' ) {
			continue;
		}

		do_action( 'it_exchange_update_transaction_subscription_status', $transaction, $subscriber_id, $status );
	}
}

/**
 * Adds a refund to post_meta for a stripe transaction
 *
 * @since 0.1.0
 *
 * @param string         $charge_id
 * @param int            $refund_amount
 * @param \Stripe\Refund $stripe_refund
*/
function it_exchange_stripe_addon_add_refund_to_transaction( $charge_id, $refund_amount, $stripe_refund ) {

	if ( ITE_Refund::query()->and_where( 'gateway_id', '=', $stripe_refund->id )->first() ) {
		return;
	}

    // Stripe money format comes in as cents. Divide by 100.
    $refund_amount /= 100;

    // Grab transaction
    $transactions = it_exchange_stripe_addon_get_transaction_id( $charge_id );

	//really only one
    foreach ( $transactions as $transaction ) {

        $refunded_amount = 0;

        foreach ( $transaction->refunds as $refund_meta ) {
            $refunded_amount += $refund_meta->amount;
        }

        // In Stripe the Refund is the total amount that has been refunded, not just this transaction
        $this_refund = $refund_amount - $refunded_amount;

	    ITE_Refund::create( array(
		    'transaction' => $transaction,
		    'amount'      => $this_refund,
		    'created_at'  => new \DateTime( "@{$stripe_refund->created}" ),
		    'gateway_id'  => $stripe_refund->id,
	    ) );
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

/**
 * Setup a Stripe object.
 *
 * @since 1.36.0
 *
 * @param string $mode
 */
function it_exchange_setup_stripe_request( $mode = '' ) {

	$gateway = ITE_Gateways::get( 'stripe' );

	if ( ! $mode ) {
		$mode = $gateway->is_sandbox_mode() ? IT_Exchange_Transaction::P_MODE_SANDBOX : IT_Exchange_Transaction::P_MODE_LIVE;
	}

	if ( $mode === IT_Exchange_Transaction::P_MODE_SANDBOX ) {
		$secret_key = $gateway->settings()->get( 'stripe-test-secret-key' );
	} else {
		$secret_key = $gateway->settings()->get( 'stripe-live-secret-key' );
	}

	\Stripe\Stripe::setApiKey( $secret_key );
	\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );
}