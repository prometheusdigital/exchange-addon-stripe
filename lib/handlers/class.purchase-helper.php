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

		$total = $cart->get_total();

		// If we only have products, fees, and taxes, then we can adjust the total by using tax_percent in Stripe.
		if ( count( array_diff( $cart->get_item_types(), array( 'product', 'fee', 'tax' ) ) ) === 0 ) {
			$total = $cart_product->get_total();
		}

		$fee = $cart_product->get_line_items()->with_only( 'fee' )
		                    ->having_param( 'is_free_trial', 'is_prorate_days' )->first();
		if ( $fee ) {
			$total += $fee->get_total() * - 1;
		}

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

				if ( count( array_diff( $cart->get_item_types(), array( 'product', 'fee', 'tax' ) ) ) === 0 ) {

					/** @var ITE_Cart_Product $cart_product */
					$cart_product = $cart->get_items( 'product' )->filter( function ( ITE_Cart_Product $product ) {
						return $product->get_product()->has_feature( 'recurring-payments', array( 'setting' => 'auto-renew' ) );
					} )->first();

					$total = $cart_product->get_total();
					$taxes = $cart->calculate_total( 'tax' );

					if ( $cart->get_total() == 0 ) {
						/** @var ITE_Fee_Line_Item $fee */
						$fee = $cart->get_items()->flatten()->with_only( 'fee' )
						            ->having_param( 'is_free_trial', 'is_prorate_days' )->first();

						if ( $fee ) {
							$total += $fee->get_total() * - 1;
							$taxes += $fee->get_line_items()->with_only( 'tax' )->total() * - 1;
						}
					}

					$tax_percent         = $total / $taxes;
					$args['tax_percent'] = number_format( $tax_percent, 4, '.', '' );
				}

				$args                = apply_filters( 'it_exchange_stripe_addon_subscription_args', $args, $request );
				$stripe_subscription = $stripe_customer->subscriptions->create( $args );

				$txn_id = $this->add_transaction( $request, $stripe_subscription->id, 'succeeded', array(
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
				$total   = $cart->get_total();

				// Now that we have a valid Customer ID, charge them!
				$args = array(
					'customer'    => $stripe_customer->id,
					'amount'      => number_format( $total, 2, '', '' ),
					'currency'    => strtolower( $general['default-currency'] ),
					'description' => strip_tags( it_exchange_get_cart_description( array( 'cart' => $cart ) ) ),
				);

				$args   = apply_filters( 'it_exchange_stripe_addon_charge_args', $args, $request );
				$charge = \Stripe\Charge::create( $args );
				$txn_id = $this->add_transaction( $request, $charge->id, 'succeeded', array(
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
			error_log( $e->getMessage() );
			$cart->get_feedback()->add_error( $e->getMessage() );

			return null;
		}
	}

	/**
	 * Add the transaction in Exchange.
	 *
	 * @since 1.11.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 * @param string                                 $method_id
	 * @param string                                 $status
	 * @param array                                  $args
	 *
	 * @return int|false
	 */
	protected function add_transaction( ITE_Gateway_Purchase_Request $request, $method_id, $status, $args ) {

		if ( $p = $request->get_child_of() ) {
			return it_exchange_add_child_transaction( 'stripe', $method_id, $status, $request->get_cart(), $p->get_ID(), $args );
		}

		return it_exchange_add_transaction( 'stripe', $method_id, $status, $request->get_cart(), null, $args );
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
		$cart_product = $request->get_cart()->get_items( 'product' )->filter( function ( ITE_Cart_Product $product ) {
			return $product->get_product()->has_feature( 'recurring-payments', array( 'setting' => 'auto-renew' ) );
		} )->first();

		if ( $cart_product && $cart_product->get_product() ) {

			$product = $cart_product->get_product();

			if ( isset( $prorates[ $product->ID ] ) && $prorates[ $product->ID ]->get_credit_type() === 'days' ) {

				if ( $prorates[ $product->ID ]->get_free_days() ) {
					return time() + ( $prorates[ $product->ID ]->get_free_days() * DAY_IN_SECONDS );
				}
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

		$general_settings = it_exchange_get_option( 'settings_general' );

		if ( $request->get_customer() instanceof IT_Exchange_Guest_Customer ) {
			$to_tokenize = $request->get_tokenize()->get_source_to_tokenize();
			if ( is_string( $to_tokenize ) ) {
				$source = $to_tokenize;
			} elseif ( $to_tokenize instanceof ITE_Gateway_Card ) {
				$source = array(
					'object'    => 'card',
					'exp_month' => $to_tokenize->get_expiration_month(),
					'exp_year'  => $to_tokenize->get_expiration_year(),
					'number'    => $to_tokenize->get_number(),
					'cvc'       => $to_tokenize->get_cvc(),
					'name'      => $to_tokenize->get_holder_name(),
				);
			} elseif ( $to_tokenize instanceof ITE_Gateway_Bank_Account ) {
				$source = array(
					'object'              => 'bank_account',
					'account_number'      => $to_tokenize->get_account_number(),
					'country'             => $request->get_customer()->get_billing_address()->offsetGet( 'country' ),
					'currency'            => strtolower( $general_settings['default-currency'] ),
					'account_holder_name' => $to_tokenize->get_holder_name(),
					'account_holder_type' => $to_tokenize->get_type(),
					'routing_number'      => $to_tokenize->get_routing_number(),
				);
			}
		} elseif ( $request->get_token() ) {
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
		
		$stripe_customer  = it_exchange_stripe_addon_get_stripe_customer_id( $request->get_customer()->ID );
		$stripe_customer  = $stripe_customer ? \Stripe\Customer::retrieve( $stripe_customer ) : '';

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