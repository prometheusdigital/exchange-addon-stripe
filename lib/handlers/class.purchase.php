<?php
/**
 * Purchase Request Handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */
use iThemes\Exchange\REST\Route\v1\Customer\Token\Serializer;
use iThemes\Exchange\REST\Route\v1\Customer\Token\Token;
use iThemes\Exchange\REST\Route\v1\Customer\Token\Tokens;

/**
 * Class IT_Exchange_Stripe_Purchase_Request_Handler
 */
class IT_Exchange_Stripe_Purchase_Request_Handler extends ITE_IFrame_Purchase_Request_Handler implements ITE_Gateway_JS_Tokenize_Handler {

	/** @var \IT_Exchange_Stripe_Purchase_Request_Handler_Helper */
	private $helper;

	/**
	 * @inheritDoc
	 */
	public function __construct(
		\ITE_Gateway $gateway,
		\ITE_Gateway_Request_Factory $factory,
		IT_Exchange_Stripe_Purchase_Request_Handler_Helper $helper
	) {
		parent::__construct( $gateway, $factory );
		$this->helper = $helper;
	}

	/**
	 * Get Checkout.js configuration.
	 *
	 * @since 2.0.0
	 *
	 * @param \ITE_Gateway_Purchase_Request $request
	 *
	 * @return array
	 * @throws \UnexpectedValueException
	 */
	protected function get_stripe_checkout_config( ITE_Gateway_Purchase_Request $request ) {

		$general = it_exchange_get_option( 'settings_general' );
		$setting = $this->get_gateway()->is_sandbox_mode() ? 'stripe-test-publishable-key' : 'stripe-live-publishable-key';

		$cart  = $request->get_cart();
		$total = $cart->get_total();

		$vars = array(
			'key'         => $this->get_gateway()->settings()->get( $setting ),
			'email'       => $request->get_customer()->get_email(),
			'name'        => $general['company-name'],
			'description' => strip_tags( it_exchange_get_cart_description( array( 'cart' => $cart ) ) ),
			'panelLabel'  => __( 'Checkout', 'LION' ),
			'zipCode'     => true,
			'currency'    => $general['default-currency'],
			'bitcoin'     => (bool) $this->get_gateway()->settings()->get( 'enable-bitcoin' )
		);

		if ( $plan = $this->helper->get_plan_for_cart( $cart, $general['default-currency'] ) ) {
			$vars['panelLabel'] = 'Subscribe';
			$vars['bitcoin']    = false;
		} elseif ( $plan === null ) {
			$vars['amount'] = (int) number_format( $total, 2, '', '' );
		} else {
			throw new UnexpectedValueException( 'Unable to get Stripe plan for subscription.' );
		}

		if ( $image_id = $this->get_gateway()->settings()->get( 'stripe-checkout-image' ) ) {
			$attachment = wp_get_attachment_image_src( $image_id, 'it-exchange-stripe-addon-checkout-image' );

			if ( ! empty( $attachment[0] ) ) {
				$vars['image'] = parse_url( $attachment[0], PHP_URL_PATH );
			}
		}

		/**
		 * Filter the Stripe checkout configuration settings.
		 *
		 * @link https://stripe.com/docs/checkout#integration-custom
		 *
		 * @param array                         $vars
		 * @param \ITE_Gateway_Purchase_Request $request
		 */
		return apply_filters( 'it_exchange_stripe_checkout_config', $vars, $request );
	}

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 */
	public function handle( $request ) {

		if ( ! static::can_handle( $request::get_name() ) ) {
			throw new InvalidArgumentException();
		}

		$cart = $request->get_cart();

		if ( ! wp_verify_nonce( $request->get_nonce(), $this->get_nonce_action() ) ) {
			$cart->get_feedback()->add_error(
				__( 'Purchase failed. Unable to verify security token.', 'it-l10n-ithemes-exchange' )
			);

			return null;
		}

		$plan = $this->helper->get_plan_for_cart( $cart, $cart->get_currency_code() );

		if ( $plan === false ) {
			$cart->get_feedback()->add_error(
				__( 'Purchase failed. Unable to create subscription.', 'it-l10n-ithemes-exchange' )
			);

			return null;
		}

		$plan = $plan instanceof \Stripe\Plan ? $plan->id : '';

		return $this->helper->do_transaction( $request, $plan );
	}


	/**
	 * @inheritDoc
	 */
	protected function get_inline_js( ITE_Gateway_Purchase_Request $request ) {

		$config = $this->get_stripe_checkout_config( $request );

		ob_start();
		?>
        <script type="text/javascript">

			jQuery( document ).ready( function ( $ ) {

				itExchange.hooks.addAction( 'iFramePurchaseBegin.stripe', function ( deferred ) {

					var fn = function () {
						var stripeConfig = <?php echo wp_json_encode( $config ) ?>;
						stripeConfig.token = function ( token ) {

							if ( itExchange.common.config.currentUser ) {
								deferred.resolve( { tokenize: token.id } );
							} else {
								deferred.resolve( { one_time_token: token.id } );
							}
						};
						stripeConfig.closed = function () {
							deferred.resolve( { cancelled: true } );
						};

						itExchange.stripeAddonCheckoutEmail = stripeConfig.email;
						itExchange.hooks.doAction( 'itExchangeStripeAddon.makePayment' );

						StripeCheckout.open( stripeConfig );
					};

					if ( !window.hasOwnProperty( 'StripeCheckout' ) ) {
						jQuery.getScript( 'https://checkout.stripe.com/checkout.js', fn );
					} else {
						fn();
					}
				} );
			} );
        </script>
		<?php

		return ob_get_clean();
	}

	/**
	 * @inheritDoc
	 */
	public function get_tokenize_js_function() { return $this->helper->get_tokenize_js_function(); }

	/**
	 * @inheritDoc
	 */
	public function is_js_tokenizer_configured() { return true; }
}