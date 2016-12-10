<?php
/**
 * Pause Subscription Handler.
 *
 * @since   1.11.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Pause_Subscription_Request_Handler
 */
class IT_Exchange_Stripe_Pause_Subscription_Request_Handler implements ITE_Gateway_Request_Handler {

	const COUPON = 'PAUSE';

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Pause_Subscription_Request $request
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

		$stripe_subscription->coupon = $this->get_or_create_pause_coupon();
		$stripe_subscription->save();

		$r = ! empty( $stripe_subscription->discount );

		if ( $r ) {
			$subscription->set_paused_by( $request->get_paused_by() );
		}

		return $r;
	}

	/**
	 * Get or create the Pause coupon.
	 *
	 * @since 1.11.0
	 *
	 * @return \Stripe\Coupon
	 */
	protected function get_or_create_pause_coupon() {

		try {
			$coupon = \Stripe\Coupon::retrieve( self::COUPON );

			if ( $coupon ) {
				return $coupon;
			}
		} catch ( Exception $e ) {

		}

		return \Stripe\Coupon::create( array(
			'id'          => self::COUPON,
			'duration'    => 'forever',
			'percent_off' => '100',
		) );
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'pause-subscription'; }
}