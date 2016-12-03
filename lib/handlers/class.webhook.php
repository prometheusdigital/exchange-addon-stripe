<?php
/**
 * Webhook Request Handler.
 *
 * @since   1.36.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Webhook_Request_Handler
 */
class IT_Exchange_Stripe_Webhook_Request_Handler implements ITE_Gateway_Request_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * IT_Exchange_Stripe_Webhook_Request_Handler constructor.
	 *
	 * @param \ITE_Gateway $gateway
	 */
	public function __construct( \ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Webhook_Gateway_Request $request
	 */
	public function handle( $request ) {

		if ( ! static::can_handle( $request::get_name() ) ) {
			throw new InvalidArgumentException( "Unable to handle {$request::get_name()} requests." );
		}

		$stripe_payload = json_decode( $request->get_raw_post_data() );

		if ( empty( $stripe_payload->id ) ) {
			return new WP_REST_Response( '', 200 );
		}

		try {

			it_exchange_setup_stripe_request();

			$stripe_event  = \Stripe\Event::retrieve( $stripe_payload->id );
			$stripe_object = $stripe_event->data->object;

			//https://stripe.com/docs/api#event_types
			switch ( $stripe_event->type ) {
				case 'charge.succeeded' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'succeeded' );
					break;
				case 'charge.failed' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->id, 'failed' );
					break;
				case 'charge.refunded' :

					$transaction = it_exchange_get_transaction_by_method_id( 'stripe', $stripe_object->id );

					if ( $stripe_object->refunded ) {
						$transaction->update_status( 'refunded' );
					} else {
						$transaction->update_status( 'partial-refund' );
					}

					it_exchange_lock( "stripe-refund-created-{$transaction->ID}", 2 );

					$refund = reset( $stripe_object->reefunds->data );

					it_exchange_stripe_addon_add_refund_to_transaction( $stripe_object->id, $stripe_object->amount_refunded, $refund );

					it_exchange_release_lock( "stripe-refund-created-{$transaction->ID}" );

					break;
				case 'charge.dispute.created' :
				case 'charge.dispute.updated' :
				case 'charge.dispute.closed' :
					it_exchange_stripe_addon_update_transaction_status( $stripe_object->charge, $stripe_object->status );
					break;
				case 'customer.deleted' :
					it_exchange_stripe_addon_delete_stripe_id_from_customer( $stripe_object->id );
					break;

				case 'customer.source.deleted':

					$token = ITE_Payment_Token::query()
					                          ->where( 'token', '=', $stripe_object->id )
					                          ->and_where( 'gateway', '=', 'stripe' )->first();

					if ( $token ) {
						$token->delete();
					}

					break;
				case 'customer.source.updated':

					/** @var ITE_Payment_Token $token */
					$token = ITE_Payment_Token::query()
					                          ->where( 'token', '=', $stripe_object->id )
					                          ->and_where( 'gateway', '=', 'stripe' )->first();

					if ( ! $token ) {
						break;
					}

					if ( $stripe_object instanceof \Stripe\Card && $token instanceof ITE_Payment_Token_Card ) {
						$token->set_expiration_month( $stripe_object->exp_month );
						$token->set_expiration_year( $stripe_object->exp_year );
					}

					break;
				case 'invoice.created':

					/** @var \Stripe\Invoice $stripe_object */
					if ( ! empty( $stripe_object->closed ) ) {
						break;
					}

					$subscriber_id = it_exchange_stripe_addon_convert_get_subscriber_id( $stripe_object );
					$transactions  = it_exchange_stripe_addon_get_transaction_id( $subscriber_id );

					if ( ! $transactions ) {
						$transactions = it_exchange_stripe_addon_get_transaction_id_by_subscriber_id( $subscriber_id );
					}

					if ( ! is_array( $transactions ) || ! $transactions ) {
						break;
					}

					$transaction  = reset( $transactions );
					$subscription = it_exchange_get_subscription_by_transaction( $transaction );

					if ( ! $subscription || ! method_exists( $subscription, 'get_payment_token' ) || ! $subscription->get_payment_token() ) {
						break;
					}

					$customer = \Stripe\Customer::retrieve( $stripe_object->customer );

					if ( ! $customer ) {
						break;
					}

					$payment_token           = $subscription->get_payment_token();
					$previous_default_source = '';

					if ( $customer->default_source !== $payment_token->token ) {
						$previous_default_source  = $customer->default_source;
						$customer->default_source = $payment_token->token;
						$customer->save();
					}

					$stripe_object->pay();

					if ( $previous_default_source ) {
						$customer->default_source = $previous_default_source;
						$customer->save();
					}

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
					/** @var \Stripe\Invoice $stripe_object */
					$subscriber_id = it_exchange_stripe_addon_convert_get_subscriber_id( $stripe_object );
					$subscription  = it_exchange_get_subscription_by_subscriber_id( 'stripe', $subscriber_id );

					if ( $subscription ) {
						$subscription->set_status( IT_Exchange_Subscription::STATUS_SUSPENDED );
						$subscription->update_meta( 'stripe_failed_invoice', $stripe_object->id );
					}
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

			return new WP_REST_Response( '', 400 );
		}

		return new WP_REST_Response( '', 200 );
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'webhook'; }
}