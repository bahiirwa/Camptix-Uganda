<?php
/*
	Plugin Name:	CampTix Paystack Payment Gateway
	Plugin URI: 	https://bosun.me
	Description: 	Paystack payment gateway for CampTix
	Version: 		1.0.0
	Author: 		Tunbosun Ayinla
	License:        GPL-2.0+
	License URI:    http://www.gnu.org/licenses/gpl-2.0.txt
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function tbz_paystack_camptix_add_ngn_currency( $currencies ) {

	$currencies['NGN'] = array(
		'label' 	=> 'Nigerian Naira',
		'format' 	=> '₦%s',
	);
	return $currencies;
}
add_filter( 'camptix_currencies', 'tbz_paystack_camptix_add_ngn_currency' );


function tbz_paystack_camptix_load_payment_method() {

	if ( ! class_exists( 'CampTix_Payment_Method_Paystack' ) )
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-paystack.php';
	camptix_register_addon( 'CampTix_Payment_Method_Paystack' );
}
add_action( 'camptix_load_addons', 'tbz_paystack_camptix_load_payment_method' );