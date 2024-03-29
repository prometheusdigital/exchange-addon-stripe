<?php
/**
 * Exchange will build your add-on's settings page for you and link to it from our add-on
 * screen. You are free to link from it elsewhere as well if you'd like... or to not use our API
 * at all. This file has all the functions related to registering the page, printing the form, and saving
 * the options. This includes the wizard settings. Additionally, we use the Exchange storage API to
 * save / retreive options. Add-ons are not required to do this.
*/

/**
 * This is the function registered in the options array when it_exchange_register_addon was called for stripe
 *
 * It tells Exchange where to find the settings page
 *
 * @return void
*/
function it_exchange_stripe_addon_settings_callback() {
    $IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
    $IT_Exchange_Stripe_Add_On->print_settings_page();
}

/**
 * Outputs wizard settings for Stripe
 *
 * Exchange allows add-ons to add a small amount of settings to the wizard.
 * You can add these settings to the wizard by hooking into the following action:
 * - it_exchange_print_[addon-slug]_wizard_settings
 * Exchange exspects you to print your fields here.
 *
 * @since 0.1.0
 * @todo make this better, probably
 * @param object $form Current IT Form object
 * @return void
*/
function it_exchange_print_stripe_wizard_settings( $form ) {
    $IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
    $settings = it_exchange_get_option( 'addon_stripe', true );
    $form_values = ITUtility::merge_defaults( ITForm::get_post_data(), $settings );
    $hide_if_js =  it_exchange_is_addon_enabled( 'stripe' ) ? '' : 'hide-if-js';
    ?>
    <div class="field stripe-wizard <?php echo $hide_if_js; ?>">
    <?php if ( empty( $hide_if_js ) ) { ?>
        <input class="enable-stripe" type="hidden" name="it-exchange-transaction-methods[]" value="stripe" />
    <?php } ?>
    <?php $IT_Exchange_Stripe_Add_On->get_stripe_payment_form_table( $form, $form_values ); ?>
    </div>
    <?php
}
add_action( 'it_exchange_print_stripe_wizard_settings', 'it_exchange_print_stripe_wizard_settings' );

/**
 * Saves stripe settings when the Wizard is saved
 *
 * @since 0.1.0
 *
 * @return void
*/
function it_exchange_save_stripe_wizard_settings( $errors ) {
    if ( ! empty( $errors ) )
        return $errors;

    $IT_Exchange_Stripe_Add_On = new IT_Exchange_Stripe_Add_On();
    return $IT_Exchange_Stripe_Add_On->stripe_save_wizard_settings();
}
add_action( 'it_exchange_save_stripe_wizard_settings', 'it_exchange_save_stripe_wizard_settings' );

/**
 * Default settings for stripe
 *
 * @since 0.1.0
 *
 * @param array $values
 * @return array
*/
function it_exchange_stripe_addon_default_settings( $values ) {
    $defaults = array(
        'stripe-test-mode'             => false,
        'stripe-live-secret-key'       => '',
        'stripe-live-publishable-key'  => '',
        'stripe-test-secret-key'       => '',
        'stripe-test-publishable-key'  => '',
        'stripe-purchase-button-label' => __( 'Purchase', 'LION' ),
        'stripe-checkout-image'        => '',
        'stripe_license'               => '',
        'enable-bitcoin'               => false,
    );
    $values = ITUtility::merge_defaults( $values, $defaults );
    return $values;
}
add_filter( 'it_storage_get_defaults_exchange_addon_stripe', 'it_exchange_stripe_addon_default_settings' );

/**
 * Filters default currencies to only display those supported by Stripe
 *
 * @since 0.1.0
 *
 * @param array $default_currencies Array of default currencies supplied by ExchangeWP
 * @return array filtered list of currencies only supported by Stripe
 */
function it_exchange_stripe_addon_get_currency_options( $default_currencies ) {
	if ( is_admin() && function_exists( 'get_current_screen' ) ) {
		$current_screen = get_current_screen();
		if ( ! empty( $current_screen->base )  && ( 'exchange_page_it-exchange-settings' == $current_screen->base || 'exchange_page_it-exchange-setup' == $current_screen->base ) ) {
		    $stripe_currencies = IT_Exchange_Stripe_Add_On::get_supported_currency_options();
			if ( !empty( $stripe_currencies ) )
				return array_intersect_key( $default_currencies, $stripe_currencies );
		}
	}
	return $default_currencies;
}
add_filter( 'it_exchange_get_currencies', 'it_exchange_stripe_addon_get_currency_options' );

/**
 * Class for Stripe
 * @since 0.1.0
*/
class IT_Exchange_Stripe_Add_On {

    /**
     * @var boolean $_is_admin true or false
     * @since 0.1.0
    */
    var $_is_admin;

    /**
     * @var string $_current_page Current $_GET['page'] value
     * @since 0.1.0
    */
    var $_current_page;

    /**
     * @var string $_current_add_on Current $_GET['add-on-settings'] value
     * @since 0.1.0
    */
    var $_current_add_on;

    /**
     * @var string $status_message will be displayed if not empty
     * @since 0.1.0
    */
    var $status_message;

    /**
     * @var string $error_message will be displayed if not empty
     * @since 0.1.0
    */
    var $error_message;

    /**
     * Class constructor
     *
     * Sets up the class.
     * @since 0.1.0
     * @return void
    */
    function __construct() {
        $this->_is_admin       = is_admin();
        $this->_current_page   = empty( $_GET['page'] ) ? false : $_GET['page'];
        $this->_current_add_on = empty( $_GET['add-on-settings'] ) ? false : $_GET['add-on-settings'];

        if ( ! empty( $_POST ) && $this->_is_admin && 'it-exchange-addons' == $this->_current_page && 'stripe' == $this->_current_add_on ) {
            add_action( 'it_exchange_save_add_on_settings_stripe', array( $this, 'save_settings' ) );
            do_action( 'it_exchange_save_add_on_settings_stripe' );
        } else if ( ! empty( $_GET['remove-checkout-image'] ) && $this->_is_admin && 'it-exchange-addons' == $this->_current_page && 'stripe' == $this->_current_add_on ) {
            add_action( 'it_exchange_remove_checkout_image_add_on_settings_stripe', array( $this, 'remove_checkout_image' ) );
            do_action( 'it_exchange_remove_checkout_image_add_on_settings_stripe' );
        }

        // Creates our option in the database
        add_action( 'admin_notices', array( $this, 'exchange_stripe_admin_notices' ) );
        add_action( 'admin_init', array( $this, 'exchange_stripe_deactivate_license' ) );
        add_action( 'admin_init', array( $this, 'exchange_stripe_activate_license' ) );
     }

    /**
     * Class deprecated constructor
     *
     * Sets up the class.
     * @since 0.1.0
     * @return void
    */
    function IT_Exchange_Stripe_Add_On() {
  		self::__construct();
    }

    /**
     * Prints settings page
     *
     * @since 0.4.5
     * @return void
    */
    function print_settings_page() {
        $settings = it_exchange_get_option( 'addon_stripe' );
        $form_values  = empty( $this->error_message ) ? $settings : ITForm::get_post_data();
        $form_options = array(
            'id'      => apply_filters( 'it_exchange_add_on_stripe', 'it-exchange-add-on-stripe-settings' ),
            'enctype' => apply_filters( 'it_exchange_add_on_stripe_settings_form_enctype', 'multipart/form-data' ),
            'action'  => 'admin.php?page=it-exchange-addons&add-on-settings=stripe',
        );
        $form         = new ITForm( $form_values, array( 'prefix' => 'it-exchange-add-on-stripe' ) );

        if ( ! empty ( $this->status_message ) )
            ITUtility::show_status_message( $this->status_message );
        if ( ! empty( $this->error_message ) )
            ITUtility::show_error_message( $this->error_message );

        ?>
        <div class="wrap">
            <?php screen_icon( 'it-exchange' ); ?>
            <h2><?php _e( 'Stripe Settings', 'LION' ); ?></h2>

            <?php do_action( 'it_exchange_stripe_settings_page_top' ); ?>
            <?php do_action( 'it_exchange_addon_settings_page_top' ); ?>
            <?php $form->start_form( $form_options, 'it-exchange-stripe-settings' ); ?>
                <?php do_action( 'it_exchange_stripe_settings_form_top' ); ?>
                <?php $this->get_stripe_payment_form_table( $form, $form_values ); ?>
                <?php do_action( 'it_exchange_stripe_settings_form_bottom' ); ?>
                <p class="submit">
                    <?php $form->add_submit( 'submit', array( 'value' => __( 'Save Changes', 'LION' ), 'class' => 'button button-primary button-large' ) ); ?>
                </p>
            <?php $form->end_form(); ?>
            <?php do_action( 'it_exchange_stripe_settings_page_bottom' ); ?>
            <?php do_action( 'it_exchange_addon_settings_page_bottom' ); ?>
        </div>
        <?php
    }

    /**
     * @todo verify video link
     */
    function get_stripe_payment_form_table( $form, $settings = array() ) {

        $general_settings = it_exchange_get_option( 'settings_general' );

        if ( !empty( $settings ) )
            foreach ( $settings as $key => $var )
                $form->set_option( $key, $var );

        if ( ! empty( $_GET['page'] ) && 'it-exchange-setup' == $_GET['page'] ) : ?>
            <h3><?php _e( 'Stripe', 'LION' ); ?></h3>
        <?php endif; ?>
        <div class="it-exchange-addon-settings it-exchange-stripe-addon-settings">
            <p>
                <?php _e( 'To get Stripe set up for use with Exchange, you\'ll need to add the following information from your Stripe account.', 'LION' ); ?>
                <br /><br />
                <?php _e( 'Video:', 'LION' ); ?>&nbsp;<a href="http://ithemes.com/tutorials/setting-up-stripe-in-exchange/" target="_blank"><?php _e( 'Setting Up Stripe in Exchange', 'LION' ); ?></a>
            </p>
            <p>
                <?php _e( 'Don\'t have a Stripe account yet?', 'LION' ); ?> <a href="http://stripe.com" target="_blank"><?php _e( 'Go set one up here', 'LION' ); ?></a>.
                <span class="tip" title="<?php _e( 'Enabling Stripe limits your currency options to the currencies available to Stripe customers.', 'LION' ); ?>">i</span>
            </p>
            </p>
            <?php
                if ( ! in_array( $general_settings['default-currency'], array_keys( $this->get_supported_currency_options() ) ) )
                    echo '<h4>' . sprintf( __( 'You are currently using a currency that is not supported by Stripe. <a href="%s">Please update your currency settings</a>.', 'LION' ), esc_url( add_query_arg( 'page', 'it-exchange-settings' ) ) ) . '</h4>';
            ?>
            <h4><?php _e( 'Step 1. Fill out your Stripe API Credentials', 'LION' ); ?></h4>
            <p>
                <label for="stripe-live-secret-key"><?php _e( 'Live Secret Key', 'LION' ); ?> <span class="tip" title="<?php _e( 'The Stripe Live Secret Key is available in your Stripe Dashboard (Your Account &rarr; Account Settings &rarr; API Keys).', 'LION' ); ?>">i</span></label>
                <?php $form->add_text_box( 'stripe-live-secret-key' ); ?>
            </p>
            <p>
                <label for="stripe-live-publishable-key"><?php _e( 'Live Publishable Key', 'LION' ); ?> <span class="tip" title="<?php _e( 'The Stripe Live Publishable Key is available in your Stripe Dashboard (Your Account &rarr; Account Settings &rarr; API Keys).', 'LION' ); ?>">i</span></label>
                <?php $form->add_text_box( 'stripe-live-publishable-key' ); ?>
            </p>

            <h4><?php _e( 'Step 2. Setup Stripe Webhooks', 'LION' ); ?></h4>
            <p><?php printf( __( 'Webhooks can be configured in the %sWebhook Settings%s section of the Stripe dashboard. Click "Add URL" to reveal a form to add a new URL for receiving webhooks.', 'LION' ), '<a href="https://manage.stripe.com/account/webhooks">', '</a>' ); ?></p>
            <p><?php _e( 'Please log in to your account and add this URL to your Webhooks so ExchangeWP is notified of things like refunds, payments, etc.', 'LION' ); ?></p>
            <code><?php echo get_site_url(); ?>/?<?php esc_attr_e( it_exchange_get_webhook( 'stripe' ) ); ?>=1</code>

            <h4><?php _e( 'Optional: Edit Purchase Button Label', 'LION' ); ?></h4>
            <p>
                <label for="stripe-purchase-button-label"><?php _e( 'Purchase Button Label', 'LION' ); ?> <span class="tip" title="<?php _e( 'This is the text inside the button your customers will press to purchase with Stripe', 'LION' ); ?>">i</span></label>
                <?php $form->add_text_box( 'stripe-purchase-button-label' ); ?>
            </p>

            <h4 class="hide-if-wizard"><?php _e( 'Optional: Checkout Image', 'LION' ); ?></h4>
            <p class="hide-if-wizard">
                <label for="stripe-checkout-image"><?php _e( 'Checkout Image', 'LION' ); ?> <span class="tip" title="<?php _e( 'This should be a square image (128x128 pixels) and will appear in the Stripe checkout', 'LION' ); ?>">i</span></label>
                <?php $form->add_file_upload( 'stripe-checkout-image' ); ?>
            </p>
            <?php if ( !empty( $settings['stripe-checkout-image'] ) ) { ?>
            <p class="hide-if-wizard">
                <?php
        		$attachment_image = wp_get_attachment_image_src( $settings['stripe-checkout-image'], 'it-exchange-stripe-addon-checkout-image' );
				echo '<img class="stripe-circle-image" src="' . $attachment_image[0] . '" width="' . $attachment_image[1] . '" height="' . $attachment_image[2] . '" />';
                ?>
                <br />
                <?php
                $remove_image_url = add_query_arg( 'remove-checkout-image', $settings['stripe-checkout-image'] );
                echo '<a href="' . esc_url( $remove_image_url ) . '">' . __( 'Remove Checkout Image', 'LION' ) . '</a>';
                ?>
            </p>
            <?php } ?>

            <h4 class="hide-if-wizard"><?php _e( 'Optional: Enable Bitcoin', 'LION' ); ?></h4>
            <p class="hide-if-wizard">
                <?php $form->add_check_box( 'enable-bitcoin' ); ?>
                <label for="enable-bitcoin"><?php _e( 'Enable Bitcoin?', 'LION' ); ?> <span class="tip" title="<?php _e( 'When you accept Bitcoin with Stripe, your currency settings must be set to USD. You currently need a US bank account to accept Bitcoin payments. NOTE: Bitcoin cannot be used with Stripe subscriptions/plans; we will remove the bitcoin option for those cases.', 'LION' ); ?>">i</span></label>
            </p>

            <h4 class="hide-if-wizard"><?php _e( 'Optional: Enable Stripe Test Mode', 'LION' ); ?></h4>
            <p class="hide-if-wizard">
                <?php $form->add_check_box( 'stripe-test-mode', array( 'class' => 'show-test-mode-options' ) ); ?>
                <label for="stripe-test-mode"><?php _e( 'Enable Stripe Test Mode?', 'LION' ); ?> <span class="tip" title="<?php _e( 'Use this mode for testing your store. This mode will need to be disabled when the store is ready to process customer payments.', 'LION' ); ?>">i</span></label>
            </p>
            <?php $hidden_class = ( $settings['stripe-test-mode'] ) ? '' : 'hide-if-live-mode'; ?>
            <p class="test-mode-options hide-if-wizard <?php echo $hidden_class; ?>">
                <label for="stripe-test-secret-key"><?php _e( 'Test Secret Key', 'LION' ); ?> <span class="tip" title="<?php _e( 'The Stripe Test Secret Key is available in your Stripe Dashboard (Your Account &rarr; Account Settings &rarr; API Keys).', 'LION' ); ?>">i</span></label>
                <?php $form->add_text_box( 'stripe-test-secret-key' ); ?>
            </p>
            <p class="test-mode-options hide-if-wizard <?php echo $hidden_class; ?>">
                <label for="stripe-test-publishable-key"><?php _e( 'Test Publishable Key', 'LION' ); ?> <span class="tip" title="<?php _e( 'The Stripe Test Publishable Key is available in your Stripe Dashboard (Your Account &rarr; Account Settings &rarr; API Keys).', 'LION' ); ?>">i</span></label>
                <?php $form->add_text_box( 'stripe-test-publishable-key' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Save settings
     *
     * @since 0.1.0
     * @return void
    */
    function save_settings() {
        $defaults = it_exchange_get_option( 'addon_stripe' );
        $new_values = wp_parse_args( ITForm::get_post_data(), $defaults );

        if ( !empty( $_FILES['stripe-checkout-image']['name'] ) ) {
        	$id = media_handle_upload( 'stripe-checkout-image', 0 ); //post id of Client Files page
			unset($_FILES);
			if ( is_wp_error($id) ) {
				$this->error_message = $id;
			} else {
				$new_values['stripe-checkout-image'] = $id;
			}
        }

        // Check nonce
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'it-exchange-stripe-settings' ) ) {
            $this->error_message = __( 'Error. Please try again', 'LION' );
            return;
        }

        if ( !empty( $new_values['stripe-live-secret-key'] ) )
            $new_values['stripe-live-secret-key'] = trim( $new_values['stripe-live-secret-key'] );
        if ( !empty( $new_values['stripe-live-publishable-key'] ) )
            $new_values['stripe-live-publishable-key'] = trim( $new_values['stripe-live-publishable-key'] );
        if ( !empty( $new_values['stripe-test-secret-key'] ) )
            $new_values['stripe-test-secret-key'] = trim( $new_values['stripe-test-secret-key'] );
        if ( !empty( $new_values['stripe-test-publishable-key'] ) )
            $new_values['stripe-test-publishable-key'] = trim( $new_values['stripe-test-publishable-key'] );

        $errors = apply_filters( 'it_exchange_add_on_stripe_validate_settings', $this->get_form_errors( $new_values ), $new_values );
        if ( ! $errors && it_exchange_save_option( 'addon_stripe', $new_values ) ) {
            ITUtility::show_status_message( __( 'Settings saved.', 'LION' ) );
        } else if ( $errors ) {
            $errors = implode( '<br />', $errors );
            $this->error_message = $errors;
        } else {
            $this->status_message = __( 'Settings not saved.', 'LION' );
        }

      }

    /**
    * This is a means of catching errors from the activation method above and displaying it to the customer
    *
    * @since 1.2.2
    */
    function exchange_stripe_admin_notices() {
      if ( isset( $_GET['sl_activation'] ) && ! empty( $_GET['message'] ) ) {

      	switch( $_GET['sl_activation'] ) {

      		case 'false':
      			$message = urldecode( $_GET['message'] );
      			?>
      			<div class="error">
      				<p><?php echo $message; ?></p>
      			</div>
      			<?php
      			break;

      		case 'true':
      		default:
      			// Developers can put a custom success message here for when activation is successful if they way.
      			break;

      	}
      }
    }

    function remove_checkout_image() {
        $settings = it_exchange_get_option( 'addon_stripe' );
        $attachment_id = absint( $_GET['remove-checkout-image'] );
        if ( $attachment_id == $_GET['remove-checkout-image'] ) {
	        if ( $attachment_id == $settings['stripe-checkout-image'] ) {
		        unset( $settings['stripe-checkout-image'] );

                $errors = apply_filters( 'it_exchange_add_on_stripe_validate_settings', $this->get_form_errors( $settings ), $settings );
		        if ( ! $errors && it_exchange_save_option( 'addon_stripe', $settings ) ) {
		            ITUtility::show_status_message( __( 'Settings saved.', 'LION' ) );
		        } else if ( $errors ) {
		            $errors = implode( '<br />', $errors );
		            $this->error_message = $errors;
		        } else {
		            $this->status_message = __( 'Settings not saved.', 'LION' );
		        }

	        }
        }
    }

    function stripe_save_wizard_settings() {
        if ( empty( $_REQUEST['it_exchange_settings-wizard-submitted'] ) )
            return;

        $stripe_settings = array();

        // Fields to save
        $fields = array(
            'stripe-live-secret-key',
            'stripe-live-publishable-key',
            'stripe-test-secret-key',
            'stripe-test-publishable-key',
            'stripe-test-mode',
            'stripe-purchase-button-label',
        );
        $default_wizard_stripe_settings = apply_filters( 'default_wizard_stripe_settings', $fields );

        foreach( $default_wizard_stripe_settings as $var ) {
            if ( isset( $_REQUEST['it_exchange_settings-' . $var] ) ) {
                $stripe_settings[$var] = $_REQUEST['it_exchange_settings-' . $var];
            }
        }

        if ( !empty( $stripe_settings['stripe-live-secret-key'] ) )
            $stripe_settings['stripe-live-secret-key'] = trim( $stripe_settings['stripe-live-secret-key'] );
        if ( !empty( $stripe_settings['stripe-live-publishable-key'] ) )
            $stripe_settings['stripe-live-publishable-key'] = trim( $stripe_settings['stripe-live-publishable-key'] );
        if ( !empty( $stripe_settings['stripe-test-secret-key'] ) )
            $stripe_settings['stripe-test-secret-key'] = trim( $stripe_settings['stripe-test-secret-key'] );
        if ( !empty( $stripe_settings['stripe-test-publishable-key'] ) )
            $stripe_settings['stripe-test-publishable-key'] = trim( $stripe_settings['stripe-test-publishable-key'] );

        $settings = wp_parse_args( $stripe_settings, it_exchange_get_option( 'addon_stripe' ) );

        if ( $error_msg = $this->get_form_errors( $settings ) ) {

            return $error_msg;

        } else {
            it_exchange_save_option( 'addon_stripe', $settings );
            $this->status_message = __( 'Settings Saved.', 'LION' );
        }

        return;
    }

    /**
     * Validates for values
     *
     * Returns string of errors if anything is invalid
     *
     * @since 0.1.0
     * @return void
    */
    public function get_form_errors( $values ) {
        //TODO Need to figure out how to make this better so that it will still
        // save the license key even though there are other fields that don't get entered.
        // For not I think it's ok since the likelihood that someone will not fill out other
        // fields is less likely.
        $errors = array();
        // if ( empty( $values['stripe-live-secret-key'] ) )
        //     $errors[] = __( 'Please include your Stripe Live Secret Key', 'LION' );
        // if ( empty( $values['stripe-live-publishable-key'] ) )
        //     $errors[] = __( 'Please include your Stripe Live Publishable Key', 'LION' );

        try {
			\Stripe\Stripe::setApiKey( $values['stripe-live-secret-key'] );
			\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );
			$account = \Stripe\Account::retrieve();
        }
        catch( Exception $e ) {
          //This needs to be uncommented when addressing the issues above for other
          //error messages.
			// $errors[] = $e->getMessage();
        }

        if ( !empty( $values['stripe-test-mode' ] ) ) {
            if ( empty( $values['stripe-test-secret-key'] ) )
                $errors[] = __( 'Please include your Stripe Test Secret Key', 'LION' );
            if ( empty( $values['stripe-test-publishable-key'] ) )
                $errors[] = __( 'Please include your Stripe Test Publishable Key', 'LION' );

	        try {
				\Stripe\Stripe::setApiKey( $values['stripe-test-secret-key'] );
				\Stripe\Stripe::setApiVersion( ITE_STRIPE_API_VERSION );
				$account = \Stripe\Account::retrieve();
	        }
	        catch( Exception $e ) {
				$errors[] = $e->getMessage();
	        }
        }

        return $errors;
    }

    /**
     * Prints HTML options for default status
     *
     * @since 0.1.0
     * @return void
    */
    public static function get_supported_currency_options() {
		$currencies = array();
        $settings = it_exchange_get_option( 'addon_stripe', true );
		$general_settings = it_exchange_get_option( 'settings_general' );
		if ( !empty( $settings['stripe-live-secret-key'] ) ) {
			try {
				\Stripe\Stripe::setApiKey( $settings['stripe-live-secret-key'] );

				$country = \Stripe\CountrySpec::retrieve( $general_settings['company-base-country'] );

				$currencies = array_change_key_case( array_flip( $country->supported_payment_currencies ), CASE_UPPER );
			}
			catch( Exception $e ) {}
	    }
        return $currencies;
    }

}
