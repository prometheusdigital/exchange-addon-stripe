<?php
/**
 * Stripe Gateway class.
 *
 * @since   1.36.0
 * @license GPLv2
 */

/**
 * Class IT_Exchange_Stripe_Gateway
 */
class IT_Exchange_Stripe_Gateway extends ITE_Gateway {

	/** @var ITE_Gateway_Request_Handler[] */
	private $handlers = array();

	/**
	 * IT_Exchange_Stripe_Gateway constructor.
	 */
	public function __construct() {

		$factory          = new ITE_Gateway_Request_Factory();
		$this->handlers[] = new IT_Exchange_Stripe_Tokenize_Request_Handler( $this );
		$this->handlers[] = new IT_Exchange_Stripe_Webhook_Request_Handler( $this );
		$this->handlers[] = new IT_Exchange_Stripe_Refund_Request_Handler();
		$this->handlers[] = new IT_Exchange_Stripe_Cancel_Subscription_Request_Handler();

		$helper = new IT_Exchange_Stripe_Purchase_Request_Handler_Helper();

		if ( $this->settings()->has( 'use-checkout' ) && $this->settings()->get( 'use-checkout' ) ) {
			$this->handlers[] = new IT_Exchange_Stripe_Purchase_Request_Handler( $this, $factory, $helper );
		} else {
			$this->handlers[] = new IT_Exchange_Stripe_Purchase_Dialog_Request_Handler( $this, $factory, $helper );
		}

		add_action( "it_exchange_{$this->get_settings_name()}_top", array(
			$this,
			'notify_invalid_currency_settings'
		) );
		add_filter( "it_exchange_save_admin_form_settings_for_{$this->get_settings_name()}", array(
			$this,
			'sanitize_settings'
		) );
		add_filter( "it_exchange_validate_admin_form_settings_for_{$this->get_settings_name()}", array(
			$this,
			'validate_settings'
		), 10, 2 );

		if (
			! empty( $_GET['remove-checkout-image'] ) &&
			is_admin() &&
			'it-exchange-addons' === ( empty( $_GET['page'] ) ? false : $_GET['page'] ) &&
			'stripe' === ( empty( $_GET['add-on-settings'] ) ? false : $_GET['add-on-settings'] )
		) {
			$this->remove_checkout_image();
		}
	}

	/**
	 * @inheritDoc
	 */
	public function get_name() { return __( 'Stripe', 'it-l10n-ithemes-exchange' ); }

	/**
	 * @inheritDoc
	 */
	public function get_slug() { return 'stripe'; }

	/**
	 * @inheritDoc
	 */
	public function get_addon() { return it_exchange_get_addon( 'stripe' ); }

	/**
	 * @inheritDoc
	 */
	public function get_handlers() { return $this->handlers; }

	/**
	 * @inheritDoc
	 */
	public function is_sandbox_mode() { return (bool) $this->settings()->get( 'stripe-test-mode' ); }

	/**
	 * @inheritDoc
	 */
	public function get_webhook_param() { return 'it_exchange_stripe'; }

	/**
	 * @inheritDoc
	 */
	public function get_wizard_settings() {
		$fields = array(
			'preamble',
			'step1',
			'stripe-live-secret-key',
			'stripe-live-publishable-key',
			'step2',
			'step3',
			'stripe-purchase-button-label'
		);

		$wizard = array();

		foreach ( $this->get_settings_fields() as $field ) {
			if ( in_array( $field['slug'], $fields ) ) {
				$wizard[] = $field;
			}
		}

		return $wizard;
	}

	/**
	 * @inheritDoc
	 */
	public function get_settings_fields() {

		if ( $this->settings()->has( 'stripe-checkout-image' ) ) {
			$image            = wp_get_attachment_image_src(
				$this->settings()->get( 'stripe-checkout-image' ),
				'it-exchange-stripe-addon-checkout-image'
			);
			$remove_image_url = add_query_arg( 'remove-checkout-image', $this->settings()->get( 'stripe-checkout-image' ) );
		} else {
			$image            = array( '', 0, 0 );
			$remove_image_url = '';
		}

		$fields = array(
			array(
				'type' => 'html',
				'slug' => 'preamble',
				'html' =>
					'<p>' .
					__( 'To get Stripe set up for use with Exchange, you\'ll need to add the following information from your Stripe account .', 'it-l10n-ithemes-exchange' ) .
					__( 'Enabling Stripe limits your currency options to the currencies available to Stripe customers.', 'LION' ) .
					'<p>' .
					sprintf(
						__( 'Video: %1$s Setting up Stripe in Exchange %2$s', 'it-l10n-ithemes-exchange' ),
						'<a href="http://ithemes.com/tutorials/setting-up-stripe-in-exchange/" target="_blank">', '</a>'
					) . '</p><p>' .
					sprintf(
						__( 'Don\'t have a Stripe account yet? %1$sGo set one up here%2$s.', 'it-l10n-ithemes-exchange' ),
						'<a href="https://stripe.com" target="_blank">', '</a>'
					) . '</p>',
			),
			array(
				'type' => 'html',
				'slug' => 'step1',
				'html' => '<h4>' . __( 'Step 1. Fill out your Stripe API Credentials', 'LION' ) . '</h4>',
			),
			array(
				'type'    => 'text_box',
				'slug'    => 'stripe-live-secret-key',
				'label'   => __( 'Live Secret Key', 'LION' ),
				'tooltip' => __( 'The Stripe Live Secret Key is available in your Stripe Dashboard (Your Account &rarr; Account Settings &rarr; API Keys).', 'LION' ),
			),
			array(
				'type'    => 'text_box',
				'slug'    => 'stripe-live-publishable-key',
				'label'   => __( 'Live Publishable Key', 'LION' ),
				'tooltip' => __( 'The Stripe Live Publishable Key is available in your Stripe Dashboard (Your Account &rarr; Account Settings &rarr; API Keys).', 'LION' ),
			),
			array(
				'type' => 'html',
				'slug' => 'step2',
				'html' =>
					'<h4>' . __( 'Step 2. Setup Stripe Webhooks', 'LION' ) . '</h4><p>' .
					sprintf(
						__( 'Webhooks can be configured in the %sWebhook Settings%s section of the Stripe dashboard. Click "Add URL" to reveal a form to add a new URL for receiving webhooks.', 'LION' ),
						'<a href="https://manage.stripe.com/account/webhooks">', '</a>'
					) . '</p><p>' .
					__( 'Please log in to your account and add this URL to your Webhooks so iThemes Exchange is notified of things like refunds, payments, etc.', 'LION' ) .
					'</p><code>' . it_exchange_get_webhook_url( 'stripe' ) . '</code>',
			),
			array(
				'type' => 'html',
				'slug' => 'step3',
				'html' => '<h4>' . __( 'Step 3. Optional Configuration', 'LION' ) . '</h4>',
			),
			array(
				'type'    => 'text_box',
				'slug'    => 'stripe-purchase-button-label',
				'label'   => __( 'Edit Purchase Button Label', 'LION' ),
				'tooltip' => __( 'This should be a square image (128x128 pixels) and will appear in the Stripe checkout', 'LION' ),
				'default' => __( 'Purchase', 'LION' ),
			),
			array(
				'type'    => 'check_box',
				'slug'    => 'use-checkout',
				'label'   => __( 'Use Stripe Checkout Modal', 'LION' ),
				'desc'    => __( 'Use the Checkout modal provided by Stripe, instead of hosting the payment form on your site.', 'LION' ) . ' ' .
				             sprintf( __( 'Learn more at %s.', 'LION' ), '<a href="https://stripe.com/checkout">Stripe</a>' ),
				'default' => true,
			),
			array(
				'type'    => 'file_upload',
				'slug'    => 'stripe-checkout-image',
				'label'   => __( 'Optional: Checkout Image', 'LION' ),
				'tooltip' => __( 'This should be a square image (128x128 pixels) and will appear in the Stripe checkout', 'LION' ),
				'show_if' => array( 'field' => 'use-checkout', 'value' => true, 'compare' => '=' ),
			),
			'preview' => array(
				'type' => 'html',
				'slug' => 'checkout-image-preview',
				'html' => '<p><img class="stripe-circle-image" src="' . $image[0] . '" width="' . $image[1] . '" height="' . $image[2] . '" /><br>' .
				          '<a href="' . esc_url( $remove_image_url ) . '">' . __( 'Remove Checkout Image', 'LION' ) . '</a></p>'
			),
			array(
				'type'    => 'check_box',
				'slug'    => 'enable-bitcoin',
				'label'   => __( 'Enable Bitcoin?', 'it-l10n-ithemes-exchange' ),
				'tooltip' => __( 'When you accept Bitcoin with Stripe, your currency settings must be set to USD. You currently need a US bank account to accept Bitcoin payments. NOTE: Bitcoin cannot be used with Stripe subscriptions/plans; we will remove the bitcoin option for those cases.', 'LION' ),
				'show_if' => array( 'field' => 'use-checkout', 'value' => true, 'compare' => '=' ),
			),
			array(
				'type'    => 'check_box',
				'slug'    => 'stripe-test-mode',
				'label'   => __( 'Enable Stripe Test Mode', 'it-l10n-ithemes-exchange' ),
				'tooltip' => __( 'Use this mode for testing your store. This mode will need to be disabled when the store is ready to process customer payments.', 'LION' ),
			),
			array(
				'type'    => 'text_box',
				'slug'    => 'stripe-test-secret-key',
				'label'   => __( 'Test Secret Key', 'LION' ),
				'tooltip' => __( 'The Stripe Test Secret Key is available in your Stripe Dashboard (Your Account &rarr; Account Settings &rarr; API Keys).', 'LION' ),
				'show_if' => array( 'field' => 'stripe-test-mode', 'value' => true, 'compare' => '=' ),
			),
			array(
				'type'    => 'text_box',
				'slug'    => 'stripe-test-publishable-key',
				'label'   => __( 'Test Publishable Key', 'LION' ),
				'tooltip' => __( 'The Stripe Test Publishable Key is available in your Stripe Dashboard (Your Account &rarr; Account Settings &rarr; API Keys).', 'LION' ),
				'show_if' => array( 'field' => 'stripe-test-mode', 'value' => true, 'compare' => '=' ),
			),
		);

		if ( $this->settings()->has( 'stripe-checkout-image' ) ) {
			if ( ! $this->settings()->get( 'stripe-checkout-image' ) || ! $this->settings()->get( 'use-checkout' ) ) {
				unset( $fields['preview'] );
			}
		}

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	public function get_settings_name() { return 'addon_stripe'; }

	/**
	 * Get the supported currency options by Stripe.
	 *
	 * @since 1.36.0
	 *
	 * @return array
	 */
	public function get_supported_currency_options() {

		$currencies       = array();
		$general_settings = it_exchange_get_option( 'settings_general' );

		if ( $this->settings()->has( 'stripe-live-secret-key' ) ) {
			try {
				\Stripe\Stripe::setApiKey( $this->settings()->get( 'stripe-live-secret-key' ) );

				$country = \Stripe\CountrySpec::retrieve( $general_settings['company-base-country'] );

				$currencies = array_change_key_case( array_flip( $country->supported_payment_currencies ), CASE_UPPER );
			} catch ( Exception $e ) {
			}
		}

		return $currencies;
	}

	/**
	 * Notify the user if an invalid currency is selected.
	 *
	 * @since 1.36.0
	 */
	public function notify_invalid_currency_settings() {

		if ( ! $this->settings()->get( 'stripe-live-secret-key' ) ) {
			return;
		}

		$general_settings = it_exchange_get_option( 'settings_general' );

		if ( array_key_exists( $general_settings['default-currency'], $this->get_supported_currency_options() ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		printf(
			__( 'You are currently using a currency that is not supported by Stripe. <a href="%s">Please update your currency settings</a>.', 'LION' ),
			esc_url( add_query_arg( 'page', 'it-exchange-settings' ) )
		);
		echo '</p></div>';
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 1.36.0
	 *
	 * @param array $values
	 *
	 * @return array
	 */
	public function sanitize_settings( $values ) {

		$values['stripe-live-secret-key']      = trim( $values['stripe-live-secret-key'] );
		$values['stripe-live-publishable-key'] = trim( $values['stripe-live-publishable-key'] );
		$values['stripe-test-secret-key']      = trim( $values['stripe-test-secret-key'] );
		$values['stripe-test-publishable-key'] = trim( $values['stripe-test-publishable-key'] );

		if ( ! empty( $_FILES['stripe-checkout-image']['name'] ) ) {

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
			}

			if ( ! function_exists( 'media_handle_sideload' ) ) {
				require_once ABSPATH . '/wp-admin/includes/media.php';
			}

			if ( ! function_exists( 'wp_read_image_metadata' ) ) {
				require_once ABSPATH . '/wp-admin/includes/image.php';
			}

			$id = media_handle_upload( 'stripe-checkout-image', 0 ); //post id of Client Files page
			unset( $_FILES['stripe-checkout-image'] );

			if ( is_wp_error( $id ) ) {
				it_exchange_add_message( 'error', $id->get_error_message() );
			} else {
				$values['stripe-checkout-image'] = $id;
			}
		}

		return $values;
	}

	/**
	 * Validate settings.
	 *
	 * @since 1.36.0
	 *
	 * @param WP_Error|null $errors
	 * @param array         $values
	 *
	 * @return \WP_Error|null
	 */
	public function validate_settings( $errors, $values ) {

		if ( ! is_wp_error( $errors ) ) {
			$errors = new WP_Error();
		}

		$error_strings = apply_filters( 'it_exchange_add_on_stripe_validate_settings', array(), $values );

		foreach ( $error_strings as $error_string ) {
			$errors->add( '', $error_string );
		}

		if ( empty( $values['stripe-live-secret-key'] ) ) {
			$errors->add( '', __( 'Please include your Stripe Live Secret Key', 'LION' ) );
		}

		if ( empty( $values['stripe-live-publishable-key'] ) ) {
			$errors->add( '', __( 'Please include your Stripe Live Publishable Key', 'LION' ) );
		}

		try {
			\Stripe\Stripe::setApiKey( $values['stripe-live-secret-key'] );
			\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );
			\Stripe\Account::retrieve();
		} catch ( Exception $e ) {
			$errors->add( '', $e->getMessage() );
		}

		if ( ! empty( $values['stripe-test-mode'] ) ) {

			if ( empty( $values['stripe-test-secret-key'] ) ) {
				$errors->add( '', __( 'Please include your Stripe Test Secret Key', 'LION' ) );
			}

			if ( empty( $values['stripe-test-publishable-key'] ) ) {
				$errors->add( '', __( 'Please include your Stripe Test Publishable Key', 'LION' ) );
			}

			try {
				\Stripe\Stripe::setApiKey( $values['stripe-test-secret-key'] );
				\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );
				\Stripe\Account::retrieve();
			} catch ( Exception $e ) {
				$errors->add( '', $e->getMessage() );
			}
		}

		if ( $errors->get_error_messages() ) {
			return $errors;
		}

		return null;
	}

	/**
	 * Remove the stripe checkout image.
	 */
	public function remove_checkout_image() {

		$attachment_id = absint( $_GET['remove-checkout-image'] );

		if ( $attachment_id == $_GET['remove-checkout-image'] ) {
			if ( $attachment_id == $this->settings()->get( 'stripe-checkout-image' ) ) {
				$this->settings()->set( 'stripe-checkout-image', null );
			}
		}
	}
}