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
	 * @param \ITE_Gateway_Purchase_Request_Interface $request
	 *
	 * @return array
	 * @throws \UnexpectedValueException
	 */
	protected function get_stripe_checkout_config( ITE_Gateway_Purchase_Request_Interface $request ) {

		$general = it_exchange_get_option( 'settings_general' );
		$setting = $this->get_gateway()->is_sandbox_mode() ? 'stripe-test-publishable-key' : 'stripe-live-publishable-key';

		$cart  = $request->get_cart();
		$total = it_exchange_get_cart_total( false, array( 'cart' => $cart ) );

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
			$vars['plan']    = $plan->id;
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
	public function get_data_for_REST( ITE_Gateway_Purchase_Request_Interface $request ) {
		$data = parent::get_data_for_REST( $request );
		$data['accepts'] = array( 'token', 'tokenize' );

		return $data;
	}

	/**
	 * @inheritDoc
	 */
	protected function get_inline_js( ITE_Gateway_Purchase_Request_Interface $request ) {

		$config = $this->get_stripe_checkout_config( $request );

		if ( ! $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
			$tokens_endpoint = rest_url( "it_exchange/v1/customers/{$request->get_customer()->id}/tokens/" );
			$tokens_endpoint = wp_nonce_url( $tokens_endpoint, 'wp_rest' );
		} else {
			$tokens_endpoint = '';
		}

		ob_start();
		?>
		<script type="text/javascript">
			(function ( $ ) {
				var tokensEndpoint = '<?php echo esc_js( $tokens_endpoint ); ?>',
					newMethodLabel = '<?php echo esc_js( __( 'New Payment Method', 'LION' ) ); ?>',
					completeLabel = '<?php echo esc_js( __( 'Complete Purchase', 'LION' ) ); ?>',
					cancelLabel = '<?php echo esc_js( __( 'Cancel', 'LION' ) ); ?>',
					$purchaseForm = $( '#stripe-purchase-form' );

				var stripeConfig = <?php echo wp_json_encode( $config ) ?>;
				stripeConfig.token = function ( token ) {

					it_exchange_stripe_processing_payment_popup();

					$purchaseForm.append( $( '<input type="hidden" name="to_tokenize">' ).val( token.id ) );

					if ( stripeConfig.plan ) {
						$purchaseForm.append( $( '<input type="hidden" name="stripe_subscription_id">' ).val( stripeConfig.plan ) );
					}

					$purchaseForm.submit();
				};

				$purchaseForm.submit( function ( e ) {

					if ( $( "input[name='purchase_token'],input[name='to_tokenize']", $( this ) ).length ) {
						return;
					}

					e.preventDefault();

					$( this ).attr( 'disabled', true );

					itExchange.stripeAddonCheckoutEmail = '<?php echo esc_js( $config['email'] ); ?>';
					itExchange.hooks.doAction( 'itExchangeStripeAddon.makePayment' );

					getTokens().then( function ( tokens ) {

						if ( ! tokens.length ) {
							StripeCheckout.open( stripeConfig );

							return;
						}

						var html = buildTokenSelector( tokens );
						html += '<input type="submit" value="' + completeLabel + '" id="it-exchange-stripe-complete-button">';
						html += '<a href="#" id="it-exchange-stripe-cancel" style="width: 100%;	display: inline-block; text-align: center;">';
						html += cancelLabel;
						html += '</a>';

						$( '.payment-methods-wrapper > form input, .it-exchange-purchase-button' ).hide();

						$purchaseForm.append( '<div id="it-exchange-stripe-select-method">' + html + '</div>' );

						if ( stripeConfig.plan ) {
							$purchaseForm.append( $('<input type="hidden" name="stripe_subscription_id">').val(stripeConfig.plan) );
						}

						} ).fail( function ( err ) {
						console.log( 'Stripe Tokens Error: ' + err );

						StripeCheckout.open( stripeConfig );
					} );
				} );

				$( document ).on( 'click', '#new-method-stripe', function ( e ) {
					StripeCheckout.open( stripeConfig );
				} );

				$( document ).on( 'click', '#it-exchange-stripe-cancel', function ( e ) {
					e.preventDefault();

					$( "#it-exchange-stripe-select-method" ).remove();
					$( '.payment-methods-wrapper > form, .it-exchange-purchase-button' ).show();
				} );

				// Prime the cache asynchronously.
				getTokens();

				/**
				 * Get all Stripe Payment Tokens.
				 *
				 * This function will internally cache the HTTP request.
				 *
				 * @since 1.11.0
				 *
				 * @returns {*} Promise that resolves to a list of tokens.
				 */
				function getTokens() {

					var promise = $.Deferred();

					if ( ! tokensEndpoint.length ) {

						return $.when( [] );
					}

					if ( this.hasOwnProperty( 'tokens' ) ) {
						return $.when( this.tokens );
					}

					$.get( tokensEndpoint + '&gateway=stripe', ( function ( data, statusText, xhr ) {
						if ( xhr.status !== 200 ) {
							promise.reject( data );
						} else {
							this.tokens = data;
							promise.resolve( data );
						}
					} ).bind( this ) );

					return promise.promise();
				}

				/**
				 * Build the tokens selector.
				 *
				 * @param {Object[]} tokens
				 * @param {int} tokens[].id
				 * @param {string} tokens[].label
				 * @param {bool} tokens[].primary
				 *
				 * @returns {string}
				 */
				function buildTokenSelector( tokens ) {
					var html = '<div class="it-exchange-credit-card-selector">';

					for ( var i = 0, len = tokens.length; i < len; i ++ ) {

						var token = tokens[ i ], c = '';

						if ( token.primary ) {
							c = ' checked="checked"';
						}

						html += '<label><input type="radio" name="purchase_token"' + c + ' value="' + token.id + '"> ';
						html += token.label.rendered;
						html += '</label><br>';
					}

					html += '<label><input type="radio" name="purchase_token" value="new_method" id="new-method-stripe"> ';
					html += newMethodLabel;
					html += '</label>';

					html += '</div>';

					return html;
				}

			})( jQuery );
		</script>
		<?php

		return ob_get_clean();
	}
}