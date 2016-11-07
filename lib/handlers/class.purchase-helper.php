<?php
/**
 * Purchase Request Handler Helper.
 *
 * @since   1.36.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Purchase_Request_Handler_Helper
 */
class IT_Exchange_Stripe_Purchase_Request_Handler_Helper {

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
	public function get_plan_for_cart( ITE_Cart $cart, $currency ) {

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

		it_exchange_setup_stripe_request();

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
				add_post_meta( $product->ID, '_it_exchange_stripe_plans', $plan_config );
			}

			return $plan;
		} catch ( Exception $e ) {

			if ( strpos( strtolower( $e->getMessage() ), 'plan already exists' ) !== false ) {

				update_post_meta( $product->ID, '_it_exchange_stripe_plan_id', $plan_config );
				add_post_meta( $product->ID, '_it_exchange_stripe_plans', $plan_config );

				return \Stripe\Plan::retrieve( $plan_config );
			}

			$cart->get_feedback()->add_error(
				sprintf( __( 'Error: Unable to create Plan in Stripe - %s', 'LION' ), $e->getMessage() )
			);

			return false;
		}
	}

	/**
	 * Perform the transaction for a given request.
	 *
	 * @since 1.11.0
	 *
	 * @param \ITE_Gateway_Purchase_Request $request
	 * @param string                        $plan_id
	 *
	 * @return \IT_Exchange_Transaction|null
	 */
	public function do_transaction( ITE_Gateway_Purchase_Request $request, $plan_id = '' ) {

		$cart = $request->get_cart();

		try {

			it_exchange_setup_stripe_request();

			$stripe_customer = $this->get_stripe_customer_for_request( $request, $previous_default_source, $payment_token );

			if ( $plan_id ) {

				$args = array(
					'plan'    => $plan_id,
					'prorate' => apply_filters( 'it_exchange_stripe_subscription_prorate', false ),
				);

				if ( $request instanceof ITE_Gateway_Prorate_Purchase_Request && ( $prorates = $request->get_prorate_requests() ) ) {
					if ( $end_at = $this->get_trial_end_at_for_prorate( $request ) ) {
						$args['trial_end'] = $end_at;
					}
				}

				$args                = apply_filters( 'it_exchange_stripe_addon_subscription_args', $args, $request );
				$stripe_subscription = $stripe_customer->subscriptions->create( $args );

				$txn_id = it_exchange_add_transaction( 'stripe', $stripe_subscription->id, 'succeeded', $cart, null, array(
					'payment_token' => $payment_token ? $payment_token->ID : 0
				) );

				if ( ! $txn_id ) {
					return null;
				}

				if ( ! $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
					it_exchange_stripe_addon_set_stripe_customer_subscription_id( $request->get_customer()->ID, $stripe_subscription->id );
				}

				if ( function_exists( 'it_exchange_get_transaction_subscriptions' ) ) {
					$subscriptions = it_exchange_get_transaction_subscriptions( it_exchange_get_transaction( $txn_id ) );

					// should be only one
					foreach ( $subscriptions as $subscription ) {
						$subscription->set_subscriber_id( $stripe_subscription->id );
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
				$txn_id = it_exchange_add_transaction( 'stripe', $charge->id, 'succeeded', $cart, null, array(
					'payment_token' => $payment_token ? $payment_token->ID : 0
				) );
			}

			$transaction = it_exchange_get_transaction( $txn_id );

			if ( ! $transaction ) {
				throw new Exception( __( 'Unable to create transaction.', 'LION' ) );
			}

			if ( $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				$transaction->update_meta( 'stripe_guest_customer_id', $stripe_customer->id );
			}

			if ( $previous_default_source && $request->get_token() && $stripe_customer->default_source !== $request->get_token()->token ) {
				$stripe_customer->default_source = $previous_default_source;
				$stripe_customer->save();
			}

			return $transaction;
		} catch ( Exception $e ) {
			$cart->get_feedback()->add_error( $e->getMessage() );

			return null;
		}
	}

	/**
	 * Get the trial end at time for a prorate purchase request.
	 *
	 * @since 1.11.0
	 *
	 * @param ITE_Gateway_Prorate_Purchase_Request $request
	 *
	 * @return int
	 */
	protected function get_trial_end_at_for_prorate( ITE_Gateway_Prorate_Purchase_Request $request ) {

		/** @var ITE_Cart_Product $cart_product */
		$cart_product = $cart->get_items( 'product' )->filter( function ( ITE_Cart_Product $product ) {
			return $product->get_product()->has_feature( 'recurring-payments', array( 'setting' => 'auto-renew' ) );
		} )->first();

		if ( $cart_product && $cart_product->get_product() ) {

			$product = $cart_product->get_product();

			if ( isset( $prorates[ $product->ID ] ) && $prorates[ $product->ID ]->get_free_days() ) {
				return time() + ( $prorates[ $product->ID ]->get_free_days() * DAY_IN_SECONDS );
			}
		}

		return 0;
	}

	/**
	 * Get a Stripe customer object for a given purchase request.
	 *
	 * @since 1.11.0
	 *
	 * @param \ITE_Gateway_Purchase_Request $request
	 * @param string|null                   $previous_default_source
	 * @param \ITE_Payment_Token|null       $token
	 *
	 * @return \Stripe\Customer
	 */
	public function get_stripe_customer_for_request( ITE_Gateway_Purchase_Request $request, &$previous_default_source, &$token ) {

		$stripe_customer = it_exchange_stripe_addon_get_stripe_customer_id( $request->get_customer()->ID );
		$stripe_customer = $stripe_customer ? \Stripe\Customer::retrieve( $stripe_customer ) : '';

		if ( $request->get_token() ) {
			$token  = $request->get_token();
			$source = $request->get_token()->token;
		} elseif ( $request->get_tokenize() ) {
			$token = ITE_Gateways::get( 'stripe' )->get_handler_for( $request->get_tokenize() )->handle( $request->get_tokenize() );

			if ( $token ) {
				$source = $token->token;
			}
		}

		if ( empty( $source ) ) {
			throw new InvalidArgumentException( __( 'Unable to create Payment Token.', 'LION' ) );
		}

		if ( ! $stripe_customer || ! empty( $stripe_customer->deleted ) ) {

			$args = array(
				'email'    => $request->get_customer()->get_email(),
				'metadata' => array( 'wp_user_id' => $request->get_customer()->ID ),
				'source'   => $source,
			);

			if ( $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				$args['metadata']['is_guest'] = true;
			}

			$stripe_customer = \Stripe\Customer::create( $args );

			if ( ! $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
				it_exchange_stripe_addon_set_stripe_customer_id( $request->get_customer()->ID, $stripe_customer->id );
			}
		} else {
			$previous_default_source         = $stripe_customer->default_source;
			$stripe_customer->default_source = $source;
			$stripe_customer->save();
		}

		return $stripe_customer;
	}
}