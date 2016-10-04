<?php
/**
 * Purchase Request Handler.
 *
 * @since   1.36.0
 * @license GPLv2
 */
use iThemes\Exchange\REST\Route\Customer\Token\Serializer;
use iThemes\Exchange\REST\Route\Customer\Token\Token;
use iThemes\Exchange\REST\Route\Customer\Token\Tokens;

/**
 * Class IT_Exchange_Stripe_Purchase_Request_Handler
 */
class IT_Exchange_Stripe_Purchase_Request_Handler extends ITE_IFrame_Purchase_Request_Handler {

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
	 * @since 1.11.0
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
		$total = it_exchange_get_cart_total( false, array( 'cart' => $cart ) );

		$vars = array(
			'key'         => $this->get_gateway()->settings()->get( $setting ),
			'email'       => $request->get_customer()->get_email(),
			'name'        => $general['company-name'],
			'description' => strip_tags( it_exchange_get_cart_description( array( 'cart' => $cart ) ) ),
			'panelLabel'  => 'Checkout',
			'zipCode'     => true,
			'currency'    => $general['default-currency'],
			'bitcoin'     => (bool) $this->get_gateway()->settings()->get( 'enable-bitcoin' )
		);

		if ( $plan = $this->helper->get_plan_for_cart( $cart, $general['default-currency'] ) ) {
			$vars['plan'] = $plan->id;
			$vars['bitcoin'] = false;
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

		$http_request = $request->get_http_request();
		$plan         = empty( $http_request['stripe_subscription_id'] ) ? '' : $http_request['stripe_subscription_id'];

		return $this->helper->do_transaction( $request, $plan );
	}

	/**
	 * @inheritDoc
	 */
	protected function get_inline_js( ITE_Gateway_Purchase_Request $request ) {

		$config = $this->get_stripe_checkout_config( $request );

		if ( ! $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
			$tokens_endpoint = \iThemes\Exchange\REST\get_rest_url(
				new Tokens( new Serializer(), new ITE_Gateway_Request_Factory(), new Token( new Serializer() ) ),
				array( 'customer_id' => $request->get_customer()->ID )
			);
			$tokens_endpoint = wp_nonce_url( $tokens_endpoint, 'wp_rest' );
		} else {
			$tokens_endpoint = '';
		}

		ob_start();
		?>
		<script type="text/javascript">
			jQuery( '#stripe-purchase-form' ).submit( function ( e ) {

				if ( jQuery( "[name='purchase_token'],[name='to_tokenize']", jQuery( this ) ).length ) {
					return;
				}

				e.preventDefault();

				jQuery( this ).attr( 'disabled', true );

				itExchange.stripeAddonCheckoutEmail = '<?php echo esc_js( $config['email'] ); ?>';
				itExchange.hooks.doAction( 'itExchangeStripeAddon.makePayment' );

				var $purchaseForm = jQuery( this );
				var stripeConfig = <?php echo wp_json_encode( $config ) ?>;
				var tokensEndpoint = '<?php echo esc_js( $tokens_endpoint ); ?>';

				stripeConfig.token = function ( token ) {

					it_exchange_stripe_processing_payment_popup();

					if ( tokensEndpoint.length ) {
						jQuery.post( tokensEndpoint, {
							gateway: 'stripe',
							source : token.id,
							primary: true
						}, function ( result ) {
							$purchaseForm.append( jQuery( '<input type="hidden" name="purchase_token">' ).val( result.id ) );

							if ( stripeConfig.plan ) {
								$purchaseForm.append( jQuery( '<input type="hidden" name="stripe_subscription_id">' ).val( stripeConfig.plan ) );
							}

							$purchaseForm.submit();
						} );
					} else {

						$purchaseForm.append( jQuery( '<input type="hidden" name="to_tokenize">' ).val( token.id ) );

						if ( stripeConfig.plan ) {
							$purchaseForm.append( jQuery( '<input type="hidden" name="stripe_subscription_id">' ).val( stripeConfig.plan ) );
						}

						$purchaseForm.submit();
					}
				};

				StripeCheckout.open( stripeConfig );
			} );
		</script>
		<?php

		return ob_get_clean();
	}
}