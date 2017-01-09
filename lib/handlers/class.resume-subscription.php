<?php
/**
 * Resume Subscription Handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Resume_Subscription_Request_Handler
 */
class IT_Exchange_Stripe_Resume_Subscription_Request_Handler implements ITE_Gateway_Request_Handler {

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Resume_Subscription_Request $request
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

		$stripe_subscription->discount = null;
		$stripe_subscription->save();

		$r = empty( $stripe_subscription->discount );

		if ( $r ) {
			$subscription->set_resumed_by( $request->get_resumed_by() );
		}

		return $r;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'resume-subscription'; }
}