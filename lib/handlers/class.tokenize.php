<?php
/**
 * Tokenize Handler
 *
 * @since   1.36.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Tokenize_Request_Handler
 */
class IT_Exchange_Stripe_Tokenize_Request_Handler implements ITE_Gateway_Request_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * IT_Exchange_Stripe_Tokenize_Request_Handler constructor.
	 *
	 * @param \ITE_Gateway $gateway
	 */
	public function __construct( \ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Tokenize_Request $request
	 *
	 * @throws \UnexpectedValueException
	 * @throws \InvalidArgumentException
	 */
	public function handle( $request ) {

		if ( ! static::can_handle( $request::get_name() ) ) {
			throw new InvalidArgumentException( 'Invalid request for handler.' );
		}

		$general_settings = it_exchange_get_option( 'settings_general' );

		it_exchange_setup_stripe_request();

		$to_tokenize = $request->get_source_to_tokenize();

		$stripe_customer = it_exchange_stripe_addon_get_stripe_customer_id( $request->get_customer()->ID );
		$stripe_customer = $stripe_customer ? \Stripe\Customer::retrieve( $stripe_customer ) : '';

		if ( ! $stripe_customer || ! empty( $stripe_customer->deleted ) ) {
			$stripe_customer = \Stripe\Customer::create( array(
				'email'    => $request->get_customer()->get_email(),
				'metadata' => array( 'wp_user_id' => $request->get_customer()->ID )
			) );
			it_exchange_stripe_addon_set_stripe_customer_id( $request->get_customer()->ID, $stripe_customer->id );
		}

		// This is a token returned from Checkout or Stripe.js
		if ( is_string( $to_tokenize ) ) {
			$source = $stripe_customer->sources->create( array( 'source' => $to_tokenize ) );
		} elseif ( $to_tokenize instanceof ITE_Gateway_Card ) {
			$source = $stripe_customer->sources->create( array(
				'source' => array(
					'object'    => 'card',
					'exp_month' => $to_tokenize->get_expiration_month(),
					'exp_year'  => $to_tokenize->get_expiration_year(),
					'number'    => $to_tokenize->get_number(),
					'cvc'       => $to_tokenize->get_cvc(),
					'name'      => $to_tokenize->get_holder_name(),
				)
			) );
		} elseif ( $to_tokenize instanceof ITE_Gateway_Bank_Account ) {
			$source = $stripe_customer->sources->create( array(
				'source' => array(
					'object'              => 'bank_account',
					'account_number'      => $to_tokenize->get_account_number(),
					'country'             => $request->get_customer()->get_billing_address()->offsetGet( 'country' ),
					'currency'            => strtolower( $general_settings['default-currency'] ),
					'account_holder_name' => $to_tokenize->get_holder_name(),
					'account_holder_type' => $to_tokenize->get_type(),
					'routing_number'      => $to_tokenize->get_routing_number(),
				)
			) );
		} else {
			throw new InvalidArgumentException( 'Unable to create source from given information.' );
		}

		if ( $source instanceof \Stripe\Card ) {
			$token = ITE_Payment_Token_Card::create( array(
				'customer' => $request->get_customer()->ID,
				'token'    => $source->id,
				'gateway'  => $this->gateway->get_slug(),
				'label'    => $request->get_label(),
				'redacted' => $source->last4,
			) );

			if ( $token ) {
				$token->set_brand( $source->brand );
				$token->set_expiration_month( $source->exp_month );
				$token->set_expiration_year( $source->exp_year );
				$token->set_funding( $source->funding );
				$token->update_meta( 'stripe_fingerprint', $source->fingerprint );
			}
		} elseif ( $source instanceof \Stripe\BankAccount ) {
			$token = ITE_Payment_Token_Bank_Account::create( array(
				'customer' => $request->get_customer()->ID,
				'token'    => $source->id,
				'gateway'  => $this->gateway->get_slug(),
				'label'    => $request->get_label(),
				'redacted' => $source->last4,
			) );

			if ( $token ) {
				$token->set_bank_name( $source->bank_name );
				$token->set_account_type( $source->account_holder_type );
				$token->update_meta( 'stripe_fingerprint', $source->fingerprint );
			}
		} else {
			throw new UnexpectedValueException( sprintf(
				'Unexpected response object from Stripe %s.',
				is_object( $source ) ? get_class( $source ) : gettype( $source )
			) );
		}

		if ( ! $token ) {
			throw new UnexpectedValueException( 'Unable to create payment token.' );
		}

		if ( $request->should_set_as_primary() ) {
			$token->make_primary();
		} elseif ( $request->get_customer()->get_tokens()->count() === 1 ) {
			$token->make_primary();
		}

		return $token;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'tokenize'; }
}