<?php

/**
 * Plugin Name: Blockpen Payment Gateway
 * Plugin URI:  https://commerce.blockpen.tech
 * Description: A secured and decentralized (as it should be) payment gateway that allows your consumers to pay with cryptocurrencies.
 * Version:     1.0.2
 * Author:      Blockpen
 * Author URI:  https://blockpen.tech/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function blockpen_paygate_setup_post_type() {
    register_post_type( 'book', ['public' => 'true'] );
}
add_action( 'init', 'blockpen_paygate_setup_post_type' );
 
function blockpen_paygate_install() {
    blockpen_paygate_setup_post_type();
 
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'blockpen_paygate_install' );

function blockpen_paygate_deactivation() {
    unregister_post_type( 'book' );
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'blockpen_paygate_deactivation' );

add_action( 'plugins_loaded', 'blockpen_paygate_load', 0 );

function blockpen_paygate_load() {

    /**
     * Add the gateway to WooCommerce.
     */
    add_filter( 'woocommerce_payment_gateways', 'wc_blockpen_paygate_load' );

    function wc_blockpen_paygate_load( $methods ) {
        if (!in_array('WC_Blockpen_PayGate', $methods)) {
            $methods[] = 'WC_Blockpen_PayGate';
        }
        return $methods;
    }


    class WC_Blockpen_PayGate extends WC_Payment_Gateway {

        var $ipn_url;

        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct() {
            global $woocommerce;

            $this->id           = 'blockpen';
            $this->icon         = $this->get_icon();
            $this->has_fields   = false;
            $this->method_title = __( 'Blockpen', 'woocommerce' );
            $this->ipn_url      = add_query_arg( 'wc-api', 'WC_Blockpen_PayGate', home_url( '/' ) );

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );

            $this->log = new WC_Logger();

            add_action( 'woocommerce_receipt_blockpen', array( $this, 'receipt_page' ) );
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function admin_options() {
            ?>
            <h3><?php _e( '', 'woocommerce' ); ?></h3>
            <p><?php _e( 'Completes checkout via blockpen.tech', 'woocommerce' ); ?></p>

            <table class="form-table">
            <?php
            $this->generate_settings_html();
            ?>
            </table><!--/.form-table-->;

            <?php
        }

        /**
         * @return string of <img> html to show payment currencies
         */
        public function get_icon() {
            $icon_html  = '';
            $icon_html .= '<img src="' . plugins_url(). '/Blockpen-Payment-Gateway/blockpen_checkout_button.png' . '"/>';

            return apply_filters( 'woocommerce_blockpen_icon', $icon_html, $this->id );
        }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable blockpen.tech', 'woocommerce' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'    => __( 'Title', 'woocommerce' ),
                    'type'     => 'text',
                    'default'  => __( 'Ethereum, Stellar and Tokens with Blockpen', 'woocommerce' ),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title'   => __( 'Description', 'woocommerce' ),
                    'type'    => 'textarea',
                    'default' => __( 'Pay with Ethereum, Stellar and Any tokens with Blockpen', 'woocommerce' )
                )
            );
        }

        /**
         * Get blockpen.tech Args
         *
         * @access public
         * @param mixed $order
         * @return array
         */
        function get_blockpen_args( $order ) {
            global $woocommerce;

            $order_id = $order->id;

            $blockpen_args = array(
                'merchant'    => $this->merchant_id,
                'currency'    => $order->get_currency(),
                'success_url' => esc_url_raw($this->get_return_url( $order )),
                'cancel_url'  => esc_url_raw($order->get_cancel_order_url_raw()),
                'first_name' => $order->billing_first_name,
                'last_name'  => $order->billing_last_name,
                'email'      => $order->billing_email,
            );

            if (
                sanitize_text_field( $blockpen_args['currency'] )   &&
                sanitize_text_field( $blockpen_args['first_name'] ) &&
                sanitize_text_field( $blockpen_args['last_name'] )  &&
                sanitize_email( $blockpen_args['email'] )
            ) {
                $blockpen_args = apply_filters( 'woocommerce_blockpen_args', $blockpen_args );
                return $blockpen_args;
            }
            else 
                throw new \Exception("Sanitize inputs");
        }


        /**
         * Generate the blockpen button link
         *
         * @access public
         * @param mixed $order_id
         * @return string
         */
        function generate_blockpen_url($order) {
            global $woocommerce;

            if ( $order->status != 'completed' && get_post_meta( $order->id, 'blockpen payment complete', true ) != 'Yes' ) {
                $order->update_status('pending', 'Customer is being redirected to blockpen...');
            }

            $blockpen_adr = "https://alpha.blockpen.tech/woocommerce/pay?";
                        
            $blockpen_args = $this->get_blockpen_args( $order );
            $blockpen_args["total"] = $order->total;

            if ( ! isset($blockpen_args['total']) ) throw new \Exception("Undefined amount to pay");

            $blockpen_adr .= http_build_query( $blockpen_args, '', '&' );

            return $blockpen_adr;
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

            return array(
                'result' => 'success',
                'redirect' => $this->generate_blockpen_url($order),
            );
        }


        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        function receipt_page( $order ) {
            echo '<p>'.__( 'Thank you for your order, please click the button below to pay with blockpen.tech.', 'woocommerce' ).'</p>';

            echo $this->generate_blockpen_form( $order );
        }

        /**
         * Successful Payment!
         *
         * @access public
         * @param array $posted
         * @return void
         */
        function successful_request( $posted ) {
            global $woocommerce;

            $posted = stripslashes_deep( $posted );

            $order = $this->get_blockpen_order( $posted );

            $this->log->add( 'blockpen', 'Order #'.$order->id.' payment status: ' . $posted['status_text'] );
            $order->add_order_note('blockpen.tech Payment Status: '.$posted['status_text']);

            if ( $order->status != 'completed' && get_post_meta( $order->id, 'blockpen payment complete', true ) != 'Yes' ) {
                if ( ! empty( $posted['txn_id'] ) )
                    update_post_meta( $order->id, 'Transaction ID', $posted['txn_id'] );

                if ( ! empty( $posted['first_name'] ) && sanitize_text_field( $posted['first_name'] ) )
                    update_post_meta( $order->id, 'Payer first name', $posted['first_name'] );

                if ( ! empty( $posted['last_name'] ) && sanitize_text_field( $posted['last_name'] ) )
                    update_post_meta( $order->id, 'Payer last name', $posted['last_name'] );

                if ( ! empty( $posted['email'] ) && is_email( $posted['email'] ) )
                    update_post_meta( $order->id, 'Payer email', $posted['email'] );

                if ($posted['status'] >= 100 || $posted['status'] == 2 || ($this->allow_zero_confirm && $posted['status'] >= 0 && $posted['received_confirms'] > 0 && $posted['received_amount'] >= $posted['amount2'])) {
                    print "Marking complete\n";
                    update_post_meta( $order->id, 'blockpen payment complete', 'Yes' );
                    $order->payment_complete();
                } else if ($posted['status'] < 0) {
                    print "Marking cancelled\n";
                    $order->update_status('cancelled', 'blockpen.tech Payment cancelled/timed out: '.$posted['status_text']);
                    mail( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s cancelled/timed out', 'woocommerce' ), $order->get_order_number() ), $posted['status_text'] );
                } else {
                    print "Marking pending\n";
                    $order->update_status('pending', 'blockpen.tech Payment pending: '.$posted['status_text']);
                }
            }
            die("IPN OK");
        }

        /**
         * get_blockpen_order function.
         *
         * @access public
         * @param mixed $posted
         * @return void
         */
        function get_blockpen_order( $posted ) {
            $custom = maybe_unserialize( stripslashes_deep($posted['custom']) );

            if ( is_numeric( $custom ) ) {
                $order_id = (int) $custom;
                $order_key = $posted['invoice'];
            } elseif( is_string( $custom ) ) {
                $order_id = (int) str_replace( $this->invoice_prefix, '', $custom );
                $order_key = $custom;
            } else {
                list( $order_id, $order_key ) = $custom;
            }

            $order = new WC_Order( $order_id );

            if ( ! isset( $order->id ) ) {
                $order_id       = woocommerce_get_order_id_by_order_key( $order_key );
                $order          = new WC_Order( $order_id );
            }

            if ( $order->order_key !== $order_key ) {
                return FALSE;
            }

            return $order;
        }

    }

    class WC_blockpen extends WC_Blockpen_PayGate {
        public function __construct() {
            _deprecated_function( 'WC_blockpen', '1.4', 'WC_Blockpen_PayGate' );
            parent::__construct();
        }
    }
}
