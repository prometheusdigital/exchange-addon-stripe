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

    $general_settings = it_exchange_get_option( 'settings_general' );
    $settings = it_exchange_get_option( 'addon_stripe' );

    $secret_key = ( $settings['stripe-test-mode'] ) ? $settings['stripe-test-secret-key'] : $settings['stripe-live-secret-key'];
    Stripe::setApiKey( $secret_key );

    $body = @file_get_contents('php://input');
    $stripe_event = json_decode( $body );

    if ( isset( $stripe_event->id ) ) {

        try {

            $stripe_object = $stripe_event->data->object;

            //https://stripe.com/docs/api#event_types
            switch( $stripe_event->type ) :

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

            endswitch;

        } catch ( Exception $e ) {

            // What are we going to do here?

        }
    }

}
add_action( 'it_exchange_webhook_it_exchange_stripe', 'it_exchange_stripe_addon_process_webhook' );
