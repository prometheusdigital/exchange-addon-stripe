<?php
/**
 * Stripe Update Token handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Update_Token_Handler
 */
class IT_Exchange_Stripe_Update_Token_Handler implements ITE_Update_Payment_Token_Handler {

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Update_Payment_Token_Request $request
	 */
	public function handle( $request ) {

		$token = $request->get_token();

		if ( ! $token instanceof ITE_Payment_Token_Card ) {
			return null;
		}

		it_exchange_setup_stripe_request( $token->mode );

		$customer = \Stripe\Customer::retrieve( it_exchange_stripe_addon_get_stripe_customer_id( $token->customer ) );
		$card     = $customer->sources->retrieve( $token->token );

		if ( $request->get_expiration_year() ) {
			$card->exp_year = $request->get_expiration_year();
		}

		if ( $request->get_expiration_month() ) {
			$card->exp_month = $request->get_expiration_month();
		}

		try {
			$card->save();
		} catch ( Exception $e ) {
			return null;
		}

		$token->set_expiration( $card->exp_month, $card->exp_year );

		return $token;
	}

	/**
	 * @inheritDoc
	 */
	public function can_update_field( $field ) {
		return in_array( $field, array( 'expiration_year', 'expiration_month' ), true );
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'update-payment-token'; }
}