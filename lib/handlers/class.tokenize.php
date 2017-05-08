<?php
/**
 * Tokenize Handler
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Tokenize_Request_Handler
 */
class IT_Exchange_Stripe_Tokenize_Request_Handler implements ITE_Gateway_Request_Handler, ITE_Gateway_JS_Tokenize_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * @var IT_Exchange_Stripe_Purchase_Request_Handler_Helper
	 */
	private $helper;

	/**
	 * IT_Exchange_Stripe_Tokenize_Request_Handler constructor.
	 *
	 * @param \ITE_Gateway                                       $gateway
	 * @param IT_Exchange_Stripe_Purchase_Request_Handler_Helper $helper
	 */
	public function __construct( \ITE_Gateway $gateway, IT_Exchange_Stripe_Purchase_Request_Handler_Helper $helper ) {
		$this->gateway = $gateway;
		$this->helper  = $helper;
	}

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
			$from   = 'JS token';
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
			$from   = 'card';
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
			$from   = 'bank account';
		} else {
			it_exchange_log( 'Not enough information provided to create Stripe token.', array(
				'_group' => 'token'
			) );
			throw new InvalidArgumentException( 'Unable to create source from given information.' );
		}

		if ( $source instanceof \Stripe\Card ) {
			$token = ITE_Payment_Token_Card::create( array(
				'customer' => $request->get_customer()->ID,
				'token'    => $source->id,
				'gateway'  => $this->gateway->get_slug(),
				'label'    => $request->get_label(),
				'redacted' => $source->last4,
				'mode'     => $this->gateway->is_sandbox_mode() ? 'sandbox' : 'live',
			) );

			if ( $token ) {
				$token->set_brand( $source->brand );
				$token->set_expiration( $source->exp_month, $source->exp_year );
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
				'mode'     => $this->gateway->is_sandbox_mode() ? 'sandbox' : 'live',
			) );

			if ( $token ) {
				$token->set_bank_name( $source->bank_name );
				$token->set_account_type( $source->account_holder_type );
				$token->update_meta( 'stripe_fingerprint', $source->fingerprint );
			}
		} else {

			it_exchange_log( 'Stripe returned unexpected response while creating token from {from}: {response}.', array(
				'response' => $source,
				'from'     => $from,
				'_group'   => 'token',
			) );

			throw new UnexpectedValueException( sprintf(
				'Unexpected response object from Stripe %s.',
				is_object( $source ) ? get_class( $source ) : gettype( $source )
			) );
		}

		if ( ! $token ) {

			it_exchange_log( 'Failed to create ITE_Payment_Token object for Stripe {type}', array(
				'type'   => $from,
				'_group' => 'token',
			) );

			throw new UnexpectedValueException( 'Unable to create payment token.' );
		}

		if ( $request->should_set_as_primary() ) {
			$token->make_primary();
		} elseif ( $request->get_customer()->get_tokens()->count() === 1 ) {
			$token->make_primary();
		}

		it_exchange_log( 'Stripe tokenize request for {for} resulted in token #{token}', ITE_Log_Levels::INFO, array(
			'for'    => $from,
			'token'  => $token->get_ID(),
			'_group' => 'token',
		) );

		return $token;
	}

	/**
	 * @inheritDoc
	 */
	public function get_tokenize_js_function() { return $this->helper->get_tokenize_js_function(); }

	/**
	 * @inheritDoc
	 */
	public function is_js_tokenizer_configured() { return true; }

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'tokenize'; }
}