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

		$subscription = $request->get_subscription();
		$failed       = $subscription->get_meta( 'stripe_failed_invoice', true );

		if ( ! $failed ) {
			return $subscription->set_payment_token( $token );
		}

		$invoice  = \Stripe\Invoice::retrieve( $failed, array( 'expand' => array( 'customer' ) ) );
		$customer = $invoice->customer;

		if ( ! $customer ) {
			return false;
		}

		$payment_token           = $subscription->get_payment_token();
		$previous_default_source = '';

		if ( $customer->default_source !== $payment_token->token ) {
			$previous_default_source  = $customer->default_source;
			$customer->default_source = $payment_token->token;
			$customer->save();
		}

		try {
			$invoice->pay();
			$success = ! empty( $invoice->paid );
		} catch ( \Stripe\Error\Card $e ) {
			$success = false;
		}

		if ( $previous_default_source ) {
			$customer->default_source = $previous_default_source;
			$customer->save();
		}

		if ( $success ) {
			$subscription->delete_meta( 'stripe_failed_invoice' );

			return $subscription->set_payment_token( $token );
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) {
		return $request_name === ITE_Update_Subscription_Payment_Method_Request::get_name();
	}
}