<?php
/**
 * Update Subscription Payment Method.
 *
 * @since   1.9.0
 * @license GPLv2
 */

/**
 * Class ITE_Stripe_Update_Subscription_Payment_Method_Handler
 */
class ITE_Stripe_Update_Subscription_Payment_Method_Handler implements ITE_Gateway_Request_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * ITE_Stripe_Update_Subscription_Payment_Method_Handler constructor.
	 *
	 * @param ITE_Gateway $gateway
	 */
	public function __construct( ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * @inheritDoc
	 *
	 * @param $request ITE_Update_Subscription_Payment_Method_Request
	 */
	public function handle( $request ) {

		if ( $request->get_card() ) {
			throw new InvalidArgumentException( 'Stripe can only handle tokens or tokenize requests.' );
		}

		if ( $request->get_tokenize() ) {
			$token = $this->gateway->get_handler_for( $request->get_tokenize() )->handle( $request->get_tokenize() );
		} else {
			$token = $request->get_payment_token();
		}

		return $request->get_subscription()->set_payment_token( $token );
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) {
		return $request_name === ITE_Update_Subscription_Payment_Method_Request::get_name();
	}
}