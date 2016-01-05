<?php
/**
 * The following file contains utility functions specific to our stripe add-on
 * If you're building your own transaction-method addon, it's likely that you will
 * need to do similar things. This includes enqueueing scripts, formatting data for stripe, etc.
*/

/**
 * Converts a transaction ID from the temporary subscription ID to the permanent charge ID
 * @since 1.1.0
 *
 * @param object $stripe_object Stripe Event Object
 * @return string $subscriber_id
*/
function it_exchange_stripe_addon_convert_get_subscriber_id( $stripe_object ) {
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
 * @since 1.1.0
 *
 * @param string $stripe_subscription_id
 * @param string $stripe_charge_id
 * @return void
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
 *
 * @param integer $stripe_id id of paypal transaction
 * @param string $payment_status new status
 * @param string $subscriber_id from PayPal (optional)
 * @return bool
*/
function it_exchange_stripe_addon_add_child_transaction( $stripe_id, $payment_status, $subscriber_id=false, $amount ) {
	$transactions = it_exchange_stripe_addon_get_transaction_id( $stripe_id );
	if ( !empty( $transactions ) ) {
		//this transaction DOES exist, don't try to create a new one, just update the status
		it_exchange_stripe_addon_update_transaction_status( $stripe_id, $payment_status );		
	} else { 
	
		if ( !empty( $subscriber_id ) ) {
			
			$transactions = it_exchange_stripe_addon_get_transaction_id_by_subscriber_id( $subscriber_id );
			foreach( $transactions as $transaction ) { //really only one
				$parent_tx_id = $transaction->ID;
				$customer_id = get_post_meta( $transaction->ID, '_it_exchange_customer_id', true );
			}
			
		} else {
			$parent_tx_id = false;
			$customer_id = false;
		}
		
		if ( $parent_tx_id && $customer_id ) {
			$transaction_object = new stdClass;
			$transaction_object->total = $amount / 100;
			it_exchange_add_child_transaction( 'stripe', $stripe_id, $payment_status, $customer_id, $parent_tx_id, $transaction_object );
			return true;
		}
	}
	return false;
}

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
 * Add the stripe customer's subscription ID as user meta on a WP user
 *
 * @since 1.1.0
 *
 * @param integer $customer_id the WP user ID
 * @param integer $subscription_id the stripe customer's subscription ID
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
 * Grab a transaction from the stripe subscriber ID
 *
 * @since 1.1.0
 *
 * @param integer $subscriber_id id of stripe transaction
 * @return transaction object
*/
function it_exchange_stripe_addon_get_transaction_id_by_subscriber_id( $subscriber_id) {
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
 * @return void
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
 * @param integer $subscription_id id of stripe subscription
 * @param string $status new status
 * @return void
*/
function it_exchange_stripe_addon_update_subscriber_status( $subscriber_id, $status ) {
	$transactions = it_exchange_stripe_addon_get_transaction_id_by_subscriber_id( $subscriber_id );
	foreach( $transactions as $transaction ) { //really only one
		do_action( 'it_exchange_update_transaction_subscription_status', $transaction, $subscriber_id, $status );
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
