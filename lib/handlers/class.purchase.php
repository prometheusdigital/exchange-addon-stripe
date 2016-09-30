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

	/**
	 * Get Checkout.js configuration.
	 *
	 * @since 1.36.0
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

		if ( $plan = $this->get_plan_for_cart( $cart, $general['default-currency'] ) ) {
			$vars['plan'] = $plan->id;
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
	 * Get the Stripe plan for a cart.
	 *
	 * @since 1.36.0
	 *
	 * @param \ITE_Cart $cart
	 * @param string    $currency
	 *
	 * @return \Stripe\Plan|null|false Null if no plan needed. False if unable to be created.
	 */
	protected function get_plan_for_cart( ITE_Cart $cart, $currency ) {

		/** @var ITE_Cart_Product $cart_product */
		$cart_product = $cart->get_items( 'product' )->filter( function ( ITE_Cart_Product $product ) {
			return $product->get_product()->has_feature( 'recurring-payments', array( 'setting' => 'auto-renew' ) );
		} )->first();

		if ( ! $cart_product ) {
			return null;
		}

		$total   = it_exchange_get_cart_total( false, array( 'cart' => $cart ) );
		$total   = number_format( $total, 2, '', '' );
		$product = $cart_product->get_product();

		$allow_trial = $product->get_feature( 'recurring-payments', array( 'setting' => 'trial-enabled' ) );

		if ( $allow_trial && function_exists( 'it_exchange_is_customer_eligible_for_trial' ) ) {
			$allow_trial = it_exchange_is_customer_eligible_for_trial( $product, $cart->get_customer() );
		}

		$allow_trial = apply_filters( 'it_exchange_stripe_addon_make_payment_button_allow_trial', $allow_trial, $product->ID );

		$interval       = $product->get_feature( 'recurring-payments', array( 'setting' => 'interval' ) );
		$interval_count = $product->get_feature( 'recurring-payments', array( 'setting' => 'interval-count' ) );

		$trial_interval       = $product->get_feature( 'recurring-payments', array( 'setting' => 'trial-interval' ) );
		$trial_interval_count = $product->get_feature( 'recurring-payments', array( 'setting' => 'trial-interval-count' ) );
		$trial_period_days    = null;

		if ( $allow_trial && $trial_interval_count > 0 ) {
			switch ( $trial_interval ) {
				case 'year':
					$days = 365;
					break;
				case 'month':
					$days = 31;
					break;
				case 'week':
					$days = 7;
					break;
				case 'day':
				default:
					$days = 1;
					break;
			}
			$trial_period_days = $trial_interval_count * $days;
		}

		$plan_config = md5( "{$total}|{$interval}|{$interval_count}|{$trial_period_days}" );
		$plans       = get_post_meta( $product->ID, '_it_exchange_stripe_plans' );

		if ( in_array( $plan_config, $plans, true ) ) {
			$plan = \Stripe\Plan::retrieve( $plan_config );

			if ( $plan ) {
				return $plan;
			}
		}

		try {
			$plan = \Stripe\Plan::create( array(
				'amount'            => $total,
				'interval'          => $interval,
				'interval_count'    => $interval_count,
				'name'              => get_the_title( $product->ID ),
				'currency'          => $currency,
				'id'                => $plan_config,
				'trial_period_days' => $trial_period_days,
			) );

			if ( $plan ) {
				update_post_meta( $product->ID, '_it_exchange_stripe_plan_id', $plan_config );
			}

			return $plan;
		}
		catch ( Exception $e ) {
			$cart->get_feedback()->add_error(
				sprintf( __( 'Error: Unable to create Plan in Stripe - %s', 'LION' ), $e->getMessage() )
			);

			return false;
		}
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

		try {

			it_exchange_setup_stripe_request();

			$stripe_customer = $this->get_stripe_customer_for_request( $request );
			$http_request    = $request->get_http_request();

			if ( ! empty( $http_request['stripe_subscription_id'] ) ) {

				$args         = array(
					'plan'    => $http_request['stripe_subscription_id'],
					'prorate' => apply_filters( 'it_exchange_stripe_subscription_prorate', false ),
				);
				$args         = apply_filters( 'it_exchange_stripe_addon_subscription_args', $args, $request );
				$subscription = $stripe_customer->subscriptions->create( $args );
				$txn_id       = it_exchange_add_transaction( 'stripe', $subscription->id, 'succeeded', $cart );

				if ( ! $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
					it_exchange_stripe_addon_set_stripe_customer_subscription_id( $request->get_customer()->ID, $subscription->id );
				}

				if ( function_exists( 'it_exchange_get_transaction_subscriptions' ) ) {
					$subscriptions = it_exchange_get_transaction_subscriptions( it_exchange_get_transaction( $txn_id ) );

					// should be only one
					foreach ( $subscriptions as $subscription ) {
						$subscription->set_subscriber_id( $subscription->id );
					}
				}
			} else {

				$general = it_exchange_get_option( 'settings_general' );
				$total   = it_exchange_get_cart_total( false, array( 'cart' => $cart ) );

				// Now that we have a valid Customer ID, charge them!
				$args = array(
					'customer'    => $stripe_customer->id,
					'amount'      => number_format( $total, 2, '', '' ),
					'currency'    => strtolower( $general['default-currency'] ),
					'description' => strip_tags( it_exchange_get_cart_description( array( 'cart' => $cart ) ) ),
				);

				$args   = apply_filters( 'it_exchange_stripe_addon_charge_args', $args, $request );
				$charge = \Stripe\Charge::create( $args );
				$txn_id = it_exchange_add_transaction( 'stripe', $charge->id, 'succeeded', $cart );
			}

			$transaction = it_exchange_get_transaction( $txn_id );

			if ( $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				$transaction->update_meta( 'stripe_guest_customer_id', $stripe_customer->id );
			}

			if ( $request->get_token() ) {
				$transaction->add_meta( 'payment_tokens', $request->get_token()->ID );
			}

			return $transaction;
		}
		catch ( Exception $e ) {
			$cart->get_feedback()->add_error( $e->getMessage() );

			return null;
		}
	}

	/**
	 * Get a Stripe customer object for a given purchase request.
	 *
	 * @since 1.36.0
	 *
	 * @param \ITE_Gateway_Purchase_Request $request
	 *
	 * @return \Stripe\Customer
	 */
	protected function get_stripe_customer_for_request( ITE_Gateway_Purchase_Request $request ) {

		$stripe_customer = it_exchange_stripe_addon_get_stripe_customer_id( $request->get_customer()->ID );
		$stripe_customer = $stripe_customer ? \Stripe\Customer::retrieve( $stripe_customer ) : '';

		if ( ! $stripe_customer || ! empty( $stripe_customer->deleted ) ) {

			$args = array(
				'email'    => $request->get_customer()->get_email(),
				'metadata' => array( 'wp_user_id' => $request->get_customer()->ID ),
			);

			if ( $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				$args['metadata']['is_guest'] = true;
			}

			if ( $request->get_tokenize() ) {
				$args['source'] = $request->get_tokenize()->get_source_to_tokenize();
			}

			$stripe_customer = \Stripe\Customer::create( $args );

			if ( ! $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				it_exchange_stripe_addon_set_stripe_customer_id( $request->get_customer()->ID, $stripe_customer->id );
			}
		} else {
			$stripe_customer->default_source = $request->get_token()->token;
			$stripe_customer->save();
		}

		return $stripe_customer;
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