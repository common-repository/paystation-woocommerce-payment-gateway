<?php
/*
	Plugin Name: Paystation WooCommerce Payment Gateway
	Description: Take credit card payments via Paystation's hosted payment pages.
	Version: 1.2.4
	Author: Paystation Limited
	Author URI: http://www.paystation.co.nz
	License: GPL-2.0+
 	License URI: http://www.gnu.org/licenses/gpl-2.0.txt
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'woocommerce_paystation_init', 0);

function woocommerce_paystation_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Paystation_Threeparty extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'threeparty';
            $this->method_title = __('Paystation', 'paystation');
            $this->method_description = __('Paystation allows you to accept credit card payments on your WooCommerce store.', 'paystation');
            $this->order_button_text = __('Proceed to Paystation', 'paystation');
            // Icon gets replaced by woocommerce_gateway_icon hook
            $this->icon = plugins_url('assets/logo.svg', __FILE__);
            $this->has_fields = false;
            $this->supports = array('products', 'refunds');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->init_form_fields();

            //WC-api hook for PostBack
            add_action('woocommerce_api_wc_paystation_threeparty', array($this, 'check_threeparty_response'));

            //Hook to call function when the 'Thank you page' is generated - checks the response code
            add_action('woocommerce_thankyou_threeparty', array($this, 'thankyou_page'));

            //Hook called when the checkout page is generated - used to display error message if any
            add_action('before_woocommerce_pay', array($this, 'checkout_page'));

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
        }

        /**
         * @param $param
         * Display paystation error message on the checkout page if the appropriate query string is set
         */
        function checkout_page($param)
        {
            if (isset($_GET['ec']) && !empty($_GET['ec']) && isset($_GET['em']) && !empty($_GET['em']) && $_GET['ec'] != '0') {
                $error_code = esc_html($_GET['em']);
                $error_message = esc_html($_GET['ti']);
                echo "<ul class='woocommerce-error' role='alert'>
                    <li>Your payment failed with the following error message from payment processing</li>
                    <li>Reason: $error_code</li>
                    <li>Transaction ID: $error_message</li>
                    </ul>";
            }
        }

        /**
         * @param $order_id
         *  This redirects from the 'Thank you page' to the checkout page
         * if the payment failed, adding parameters to the query string so
         * an error message will display on the cart
         */
        function thankyou_page($order_id)
        {

            $order = wc_get_order($order_id);
            if (strlen($_GET['ec']) < 4 && $_GET['ec'] != '0') {
                wp_redirect($order->get_checkout_payment_url(false) . "&ec=" . urlencode($_GET['ec']) . "&em=" . urlencode($_GET['em']) . "&ti=" . urlencode($_GET['ti']));
                exit();
            }
        }

        function init_form_fields()
        {
            //Fields to display in admin checkout settings
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'paystation'),
                    'type' => 'checkbox',
                    'label' => __('Enable Paystation Payment Module.', 'paystation'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title:', 'paystation'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'paystation'),
                    'default' => __('Credit card using Paystation Payment Gateway', 'paystation')
                ),
                'description' => array(
                    'title' => __('Description:', 'paystation'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'paystation'),
                    'default' => __('You will be redirected to Paystation Payment Gateway to complete your transaction.', 'paystation')
                ),
                'paystation_id' => array(
                    'title' => __('Paystation ID', 'paystation'),
                    'type' => 'text',
                    'description' => __('Paystation merchant ID given you by Paystation')
                ),
                'gateway_id' => array(
                    'title' => __('Gateway ID', 'paystation'),
                    'type' => 'text',
                    'description' => __('Gateway ID given you by Paystation ', 'paystation')
                ),
                'HMAC_key' => array(
                    'title' => __('HMAC key', 'paystation'),
                    'type' => 'text',
                    'description' => __('HMAC key given you by Paystation ', 'paystation')
                ),
                'test_mode' => array(
                    'title' => __('Test mode', 'paystation'),
                    'type' => 'checkbox',
                    'label' => __('Enable test mode', 'paystation'),
                    'default' => 'yes'
                ),
            );
        }

        /**
         * @param $order_id
         * @return array
         * This function is called when the "Place Order" button is clicked
         */
        function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $redirect_url = $this->initiate_paystation($order_id, $order);

            return array('result' => 'success', 'redirect' => $redirect_url);
        }

        function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);
            $transactionId = $order->get_transaction_id();

            if ($amount == null) {
                $amount = $order->get_total();
            }

            $result = $this->processRefund($order_id, $transactionId, $amount);
            if ($result !== true) {
                error_log('An error occurred doing process_refund');
                return false;
            } else {
                $refund_message = sprintf(__('Refunded %s via Paystation, on Order: %s', 'p4m'), wc_price($amount), $order_id);
                if ($reason) {
                    $refund_message .= 'because ' . $reason;
                }
                $order->add_order_note($refund_message);
                return true;
            }
        }

        /**
         * Process postback response
         *
         */
        function check_threeparty_response()
        {
            $xml = file_get_contents('php://input');
            $xml = simplexml_load_string($xml);

            if (!empty($xml)) {
                $errorCode = $xml->ec;
                $transactionId = $xml->ti;
                $merchantReference = $xml->merchant_ref;

                if ( function_exists( 'wc_sequential_order_numbers' ) ) {
                    /*
                    * If using sequential order numbers then the merchant reference is the order_number not order_id
                    * So get order_id from order_number
                    */
                    $merchantReference = wc_sequential_order_numbers()->find_order_by_order_number( (int) wc_clean( $merchantReference ) );
                }

                $order = wc_get_order((int)wc_clean($merchantReference));

                if (!($order instanceof WC_Order) || !$order->needs_payment()) {
                    exit('cant find order');
                }

                if ($errorCode == '0') {
                    echo "payment successful";
                    $order->payment_complete((string)esc_html($transactionId));
                } else {
                    echo "payment failed";
                    $order->update_status('failed');
                }
            }

            exit();
        }

        /**
         * @param string $prefix Prepended to the merchant session.
         * @return string A new and unique merchant session value.
         */
        function makePaystationSessionID($prefix = 'woo')
        {
            return $prefix . '_' . uniqid() . time();
        }

        function directTransaction($url, $params)
        {
            $body = $params;

            $args = array(
                'body' => $body,
                'timeout' => '5',
                'redirection' => '5',
                'httpversion' => '1.1',
                'blocking' => true
            );

            $response = wp_remote_post($url, $args);
            return $response['body'];
        }

        function initiate_paystation($order_id, $order)
        {
            $paystationURL = "https://www.paystation.co.nz/direct/paystation.dll";
            $amount = $order->get_total() * 100;
            $testMode = $this->settings['test_mode'] == 'yes';
            $pstn_pi = trim($this->settings['paystation_id']);
            $pstn_gi = trim($this->settings['gateway_id']);

            if ( function_exists( 'wc_sequential_order_numbers' ) ) {
                /*
                * If using sequential order numbers then the merchant reference should be set to the order_number not order_id
                */
                $order        = wc_get_order( $order_id );
                $order_number = $order->get_order_number();
                $pstn_mr      = urlencode( $order_number );
            } else {
                $pstn_mr = urlencode( $order_id );
            }

            $merchantSession = urlencode($this->makePaystationSessionID());

            $returnURL = $order->get_checkout_order_received_url();
            $pstn_du = urlencode($returnURL);

            $pstn_cu = $order->get_currency() ?: get_woocommerce_currency();

            $paystationParams = [
                'paystation' => '_empty',
                'pstn_nr' => 't',
                'pstn_du' => $pstn_du,
                'pstn_dp' => site_url() . '/wc-api/wc_paystation_threeparty',
                'pstn_pi' => $pstn_pi,
                'pstn_gi' => $pstn_gi,
                'pstn_ms' => $merchantSession,
                'pstn_am' => $amount,
                'pstn_mr' => $pstn_mr,
                'pstn_cu' => $pstn_cu,
                'pstn_rf' => 'JSON'
            ];

            if ($testMode) {
                $paystationParams['pstn_tm'] = 't';
            }

            $paystationParams = http_build_query($paystationParams);
            $hmacGetParams = $this->constructHMACParams($paystationParams);
            $paystationURL .= $hmacGetParams;
            $initiationResult = $this->directTransaction($paystationURL, $paystationParams);
            $json = json_decode($initiationResult, true);
			if(isset($json['response']['PaystationErrorCode'])) {
				$errorJson = $json['response'];
				$errorMessage = isset($errorJson['PaystationErrorMessage']) ?  $errorJson['PaystationErrorMessage'] : '';
				$transactionID = isset($errorJson['TransactionID']) ?  $errorJson['TransactionID'] : '';
				$checkoutLog = 'Error Code: '.$errorJson['PaystationErrorCode'].' - Error Message: '.$errorMessage.' - TransactionID: '.$transactionID;
				$logger = wc_get_logger();
				$logger->error( $checkoutLog, array( 'source' => 'paystation' ) );
			}
            $json = isset($json['InitiationRequestResponse']) ? $json['InitiationRequestResponse'] : null;
            $url = $json['DigitalOrder'] ?: null;		
            return $url;
        }

        /**
         * @param $order_id
         * @param $transactionId
         * @param $amount float in dollars
         * @return bool
         */
        function processRefund($order_id, $transactionId, $amount)
        {
            $amount = $amount * 100;
            $paystationURL = "https://www.paystation.co.nz/direct/paystation.dll";
            $testMode = $this->get_option('test_mode') == 'yes';
            $pstn_pi = trim($this->get_option('paystation_id'));
            $pstn_gi = trim($this->get_option('gateway_id'));

            if ( function_exists( 'wc_sequential_order_numbers' ) ) {
                /*
                * If using sequential order numbers then the merchant reference is the order_number not order_id
                */
                $order        = wc_get_order( $order_id );
                $order_number = $order->get_order_number();
                $pstn_mr      = urlencode( $order_number );
            } else {
                $pstn_mr = urlencode( $order_id );
            }

            $merchantSession = urlencode(time() . '-' . $this->makePaystationSessionID());

            $paystationParams = [
                'paystation' => '_empty',
                'pstn_2p' => 't',
                'pstn_rc' => 't',
                'pstn_nr' => 't',
                'pstn_pi' => $pstn_pi,
                'pstn_gi' => $pstn_gi,
                'pstn_ms' => $merchantSession,
                'pstn_am' => $amount,
                'pstn_mr' => $pstn_mr,
                'pstn_rt' => $transactionId,
                'pstn_rf' => 'JSON'
            ];

            if ($testMode) {
                $paystationParams['pstn_tm'] = 't';
            }

            $paystationParams = http_build_query($paystationParams);

            $hmacGetParams = $this->constructHMACParams($paystationParams);
            $paystationURL .= $hmacGetParams;
            $result = $this->directTransaction($paystationURL, $paystationParams);

            $json = json_decode($result, true);
            $json = isset($json['PaystationRefundResponse']) ? $json['PaystationRefundResponse'] : null;
            $errorCode = isset($json['PaystationErrorCode']) ? $json['PaystationErrorCode'] : null;

			if(!empty($errorCode) && $errorCode != '0') {
				$errorMessage = isset($json['PaystationErrorMessage']) ?  $json['PaystationErrorMessage'] : null;
				$refund_message = 'The refund has failed. Error: '.$errorMessage. ' ('.$errorCode.').';
				$order = wc_get_order($order_id);
				$order->add_order_note($refund_message);
			}

            return $errorCode === '0';
        }

        function constructHMACParams($body)
        {
            $key = trim($this->get_option('HMAC_key'));
            $hmac_timestamp = time();
            $hmac_hash = hash_hmac('sha512', "{$hmac_timestamp}paystation$body", $key);
            return '?' . http_build_query(['pstn_HMACTimestamp' => $hmac_timestamp, 'pstn_HMAC' => $hmac_hash]);
        }
    }

    /**
     * Add Paystation Gateway to WC
     **/
    function woocommerce_add_paystation_gateway($methods)
    {
        $methods[] = 'WC_Paystation_ThreeParty';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_paystation_gateway');

    function paystation_plugin_action_links($links, $file)
    {
        static $this_plugin;

        if (!$this_plugin) {
            $this_plugin = plugin_basename(__FILE__);
        }
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=threeparty') . '">Settings</a>';
        if ($file == $this_plugin) {
            array_unshift($links, $settings_link);
        }
        return $links;
    }

    add_filter('plugin_action_links', 'paystation_plugin_action_links', 10, 2);

    /**
     *  Icon
     */
    function paystation_gateway_icon($icon, $gateway_id) {
        if ($gateway_id == 'threeparty') {
            $img = plugins_url( 'assets/logo.svg', __FILE__ );
            return '<img src="'.$img.'" width="125" />';
        }
        return $icon;
    }
    add_filter('woocommerce_gateway_icon', 'paystation_gateway_icon', 10, 2 );

}
