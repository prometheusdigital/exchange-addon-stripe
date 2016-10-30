<?php
/**
 * Cancel Subscription Hadler.
 *
 * @since   1.36.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Cancel_Subscription_Request_Handler
 */
class IT_Exchange_Stripe_Cancel_Subscription_Request_Handler implements ITE_Gateway_Request_Handler {

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Cancel_Subscription_Request $request
	 *
	 * @throws \UnexpectedValueException
	 */
	public function handle( $request ) {

		$subscription  = $request->get_subscription();
		$subscriber_id = $subscription->get_subscriber_id();

		if ( ! $subscriber_id ) {
			return false;
		}

		it_exchange_setup_stripe_request( $subscription->get_transaction()->purchase_mode );

		$stripe_subscription = \Stripe\Subscription::retrieve( $subscriber_id );

		if ( ! $stripe_subscription ) {
			throw new UnexpectedValueException( 'Unable to find Stripe Subscription with id ' . $subscription->get_subscriber_id() );
		}

		// Stripe sends webhooks insanely quick. Make sure we update the subscription before the webhook handler does.
		it_exchange_lock( "stripe-cancel-subscription-{$subscription->get_transaction()->ID}", 2 );

		$deleted = $stripe_subscription->cancel( array(
			'at_period_end' => $request->is_at_period_end()
		) );

		if ( ! $deleted->canceled_at ) {
			it_exchange_release_lock( "stripe-cancel-subscription-{$subscription->get_transaction()->ID}" );

			return false;
		}

		if ( $deleted->canceled_at ) {
			$subscription->set_status( IT_Exchange_Subscription::STATUS_CANCELLED );

			if ( $request->get_cancelled_by() ) {
				$subscription->set_cancelled_by( $request->get_cancelled_by() );
			}

			if ( $request->get_reason() ) {
				$subscription->set_cancellation_reason( $request->get_reason() );
			}
		}

		it_exchange_release_lock( "stripe-cancel-subscription-{$subscription->get_transaction()->ID}" );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'cancel-subscription'; }
}