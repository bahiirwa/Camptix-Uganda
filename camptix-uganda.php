<?php
/**
 * Plugin Name: CampTix UgMart Payment Gateway
 * Plugin URI: https://omukiguy.com
 * Author Name: Laurence Bahiirwa
 * Author URI: https://omukiguy.com
 * Description: This plugin does wonders
 * Version: 0.1.0
 * License:        GPL-2.0+
 * License URI:    http://www.gnu.org/licenses/gpl-2.0.txt
 * text-domain:    ugcamptix
*/

//namespace ugcamptix;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ugcamptix_add_ugx_currency( $currencies ) {

	$currencies['UGX'] = array(
		'label' 	=> 'Ugandan Shillings',
		'format' 	=> 'UGX',
	);
	return $currencies;
}
add_filter( 'camptix_currencies', 'ugcamptix_add_ugx_currency' );


function ugcamptix_load_payment_method() {

	if ( ! class_exists( 'CampTix_Payment_Method_UgMart' ) )
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-ugmart.php';
	camptix_register_addon( 'CampTix_Payment_Method_UgMart' );
}
add_action( 'camptix_load_addons', 'ugcamptix_load_payment_method' );