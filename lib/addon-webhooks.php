<?php
/**
 * Most Payment Gateway APIs use some concept of webhooks or notifications to communicate with
 * clients. While add-ons are not required to use the Exchange API, we have created a couple of functions
 * to register and listen for these webooks. The stripe add-on uses this API and we have placed the 
 * registering and processing functions in this file.
*/

/*
 * Adds the stripe webhook key to the global array of keys to listen for
 *
 * If your add-on wants to use our API for listening and initing webhooks,
 * You'll need to register it by using the following API method
 * - it_exchange_register_webhook( $key, $param );
 * The first param is your addon-slug. The second param is the REQUEST key
 * Exchange will listen for (we've just passed it through a filter for stripe).
 *
 * @since 0.1.0
 *
 * @param array $webhooks existing
 * @return array
*/
function it_exchange_stripe_addon_register_webhook_key() {
    $key   = 'stripe';
    $param = apply_filters( 'it_exchange_stripe_addon_webhook', 'it_exchange_stripe' );
    it_exchange_register_webhook( $key, $param );
}
add_filter( 'init', 'it_exchange_stripe_addon_register_webhook_key' );

/**
 * Processes webhooks for Stripe
 *
 * This function gets called when Exchange detects an incoming request
 * from the payment gateway. It recognizes the request because we registerd it above.
 * This function gets called because we hooked it to the following filter:
 * - it_exchange_webhook_it_exchange_[addon-slug]
 *
 * @since 0.1.0
 * @todo actually handle the exceptions
 *
 * @param array $request really just passing  $_REQUEST
 */
function it_exchange_stripe_addon_process_webhook( $request ) {

    $body = @file_get_contents('php://input');
    $stripe_payload = json_decode( $body );
    	
    if ( !empty( $stripe_payload->id ) ) {
		try {
		    $general_settings = it_exchange_get_option( 'settings_general' );
		    $settings = it_exchange_get_option( 'addon_stripe' );
		
		    $secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
		    \Stripe\Stripe::setApiKey( $secret_key );
		    \Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );
			$stripe_event = \Stripe\Event::retrieve( $stripe_payload->id );
			$stripe_object = $stripe_event->data->object;
	
			//https://stripe.com/docs/api#event_types
			switch( $stripe_event->type ) {
				case 'charge.succeeded' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'succeeded' );
					break;
				case 'charge.failed' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'failed' );
					break;
				case 'charge.refunded' :
					if ( $stripe_object->refunded )
						it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'refunded' );
					else
						it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'partial-refund' );
	
					it_exchange_stripe_addon_add_refund_to_transaction( $stripe_object->id, $stripe_object->amount_refunded );
	
					break;
				case 'charge.dispute.created' :
				case 'charge.dispute.updated' :
				case 'charge.dispute.closed' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->charge, $stripe_object->status );
					break;
				case 'customer.deleted' :
					it_exchange_stripe_addon_delete_stripe_id_from_customer( $stripe_object->id );
					break;
					
				case 'invoice.payment_succeeded' :
					$subscriber_id = it_exchange_stripe_addon_convert_get_subscriber_id( $stripe_object );

					$convert = true;

					if ( $stripe_object->charge ) {

						$transactions = it_exchange_stripe_addon_get_transaction_id( $subscriber_id );

						if ( is_array( $transactions ) && count( $transactions ) ) {
							/** @var IT_Exchange_Transaction $transaction */
							$transaction = reset( $transactions );

							// this was a free trial
							if ( (float) $transaction->get_total( false ) === 0.00 ) {
								$convert = false;
							}
						}

						if ( $convert ) {
							it_exchange_stripe_addon_convert_subscription_id_to_charge_id( $subscriber_id, $stripe_object->charge );
						}

						$find_by = $stripe_object->charge;
					} else {
						$find_by = $subscriber_id;
					}

					if ( ! it_exchange_stripe_addon_update_transaction_status( $find_by, 'succeeded' ) ) {
						//If the transaction isn't found, we've got a new payment
						$GLOBALS['it_exchange']['child_transaction'] = true;
						it_exchange_stripe_addon_add_child_transaction( $find_by, 'succeeded', $subscriber_id, $stripe_object->total );
					}

					it_exchange_stripe_addon_update_subscriber_status( $subscriber_id, 'active' );
					break;
					
				case 'invoice.payment_failed' :
					$subscriber_id = it_exchange_stripe_addon_convert_get_subscriber_id( $stripe_object );
					it_exchange_stripe_addon_update_subscriber_status( $subscriber_id, 'deactivated' );
					break;
					
				case 'customer.subscription.created' :
					it_exchange_stripe_addon_update_subscriber_status( $stripe_object->id, 'active' );
					break;
					
				case 'customer.subscription.deleted' :
					it_exchange_stripe_addon_update_subscriber_status( $stripe_object->id, 'cancelled' );
					break;
			}
		}
		catch ( Exception $e ) {
			error_log( sprintf( __( 'Invalid webhook ID sent from Stripe: %s', 'it-l10n-ithemes-exchange' ), $e->getMessage() ) );
		}
    }

}
add_action( 'it_exchange_webhook_it_exchange_stripe', 'it_exchange_stripe_addon_process_webhook' );
