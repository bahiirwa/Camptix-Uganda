<?php


class CampTix_Payment_Method_Paystack extends CampTix_Payment_Method {

	public $id = 'paystack';

	public $name = 'Paystack';

	public $description;

	public $supported_currencies = array( 'NGN' );

	protected $options = array();

    function __construct() {

        $this->description = $this->paystack_gateway_description();
		parent::__construct();
    }


    function paystack_gateway_description() {

    	global $camptix;

		$notify_url = add_query_arg( array(
			'tix_action' => 'payment_notify',
			'tix_payment_method' => 'paystack',
		), $camptix->get_tickets_url() );

		$description = 'Paystack <h4>Optional: To avoid situations where bad network makes it impossible to verify transactions, set your webhook URL <a href="https://dashboard.paystack.co/#/settings/developer" target=_blank rel="noopener noreferrer">here</a> to the URL below<strong style="color: red"><pre><code>' . esc_url( $notify_url ). '</code></pre></strong></h4>';

		return $description;
    }


	function camptix_init() {

		$this->options = array_merge( array(
			'test_public_key'	=> '',
			'test_secret_key'  	=> '',
			'live_public_key'  	=> '',
			'live_secret_key' 	=> '',
			'sandbox'       	=> true,
		), $this->get_payment_options() );

		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}


	function payment_settings_fields() {

		$this->add_settings_field_helper( 'test_secret_key', 'Test Secret Key', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'test_public_key', 'Test Public Key', array( $this, 'field_text' ) );

		$this->add_settings_field_helper( 'live_secret_key', 'Live Secret Key', array( $this, 'field_text' ) );
		$this->add_settings_field_helper( 'live_public_key', 'Live Public Key', array( $this, 'field_text' ) );

		$this->add_settings_field_helper( 'sandbox', 'Test Mode', array( $this, 'field_yesno' ),
			'The Paystack Test Mode is a way to test payments without using real accounts and transactions.'
		);
	}


	function validate_options( $input ) {

		$output = $this->options;

		if ( isset( $input['test_public_key'] ) ) {
			$output['test_public_key'] = $input['test_public_key'];
		}

		if ( isset( $input['test_secret_key'] ) ) {
			$output['test_secret_key'] = $input['test_secret_key'];
		}

		if ( isset( $input['live_public_key'] ) ) {
			$output['live_public_key'] = $input['live_public_key'];
		}

		if ( isset( $input['live_secret_key'] ) ) {
			$output['live_secret_key'] = $input['live_secret_key'];
		}

		if ( isset( $input['sandbox'] ) ) {
			$output['sandbox'] = (bool) $input['sandbox'];
		}

		return $output;

	}


	function template_redirect() {

		if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'paystack' != $_REQUEST['tix_payment_method'] ) {
			return;
		}

		if ( isset( $_GET['tix_action'] ) ) {
			if ( 'payment_return' == $_GET['tix_action'] ) {
				$this->payment_return();
			}

			if ( 'payment_notify' == $_GET['tix_action'] ) {
				$this->payment_notify();
			}
		}
	}


	function payment_checkout( $payment_token ) {

		global $camptix;

		if ( ! $payment_token || empty( $payment_token ) )
			return false;

		if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) ) {
			wp_die( 'The selected currency is not supported by this payment method.' );
		}

		$options = $this->options;

		$public_key = $options['sandbox'] ? $options['test_public_key'] : $options['live_public_key'];
		$secret_key = $options['sandbox'] ? $options['test_secret_key'] : $options['live_secret_key'];

		if ( ! ( $public_key && $secret_key ) ) {
			wp_die( 'Kindly enter your Paystack API keys on the CampTix payment page.' );
		}

		$return_url = add_query_arg( array(
			'tix_action'         => 'payment_return',
			'tix_payment_token'  => $payment_token,
			'tix_payment_method' => 'paystack',
		), $camptix->get_tickets_url() );

		$order = $this->get_order( $payment_token );

		$attendee_email = get_post_meta( $order['attendee_id'], 'tix_receipt_email', true );

		$paystack_url 	= 'https://api.paystack.co/transaction/initialize';

		$headers = array(
			'Content-Type'	=> 'application/json',
			'Authorization' => 'Bearer ' . $secret_key,
			'Cache-Control'	=> 'no-cache'
		);

		$body = array(
			'amount'		=> $order['total'] * 100,
			'email'			=> $attendee_email,
			'callback_url'	=> $return_url
		);

		$args = array(
			'body'		=> json_encode( $body ),
			'headers'	=> $headers,
			'timeout'	=> 60
		);

		$request = wp_remote_post( $paystack_url, $args );

        if ( ! is_wp_error( $request ) && 200 == wp_remote_retrieve_response_code( $request ) ) {

        	$paystack_response = json_decode( wp_remote_retrieve_body( $request ) );

        	$transaction_reference = $paystack_response->data->reference;

        	$payment_url = $paystack_response->data->authorization_url;

        	update_post_meta( $order['attendee_id'], 'tix_transaction_id', $transaction_reference );

			wp_redirect( $payment_url );

			die();

        } else {

        	$paystack_response = json_decode( wp_remote_retrieve_body( $request ) );

			$camptix->error( 'Paystack error: ' . $paystack_response->message );

			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array() );

        }
	}


	function payment_return() {

		global $camptix;

		$payment_token = isset( $_REQUEST['tix_payment_token'] ) ? trim( $_REQUEST['tix_payment_token'] ) : '';

		$reference      = isset( $_REQUEST['reference'] ) ? trim( $_REQUEST['reference'] ) : '';

		$camptix->log( 'User returning from Paystack', null, compact( 'payment_token', 'reference' ) );

		if ( ! $payment_token || ! $reference  ) {
			$camptix->log( 'Dying because invalid Paystack return data', null, compact( 'payment_token', 'reference' ) );
			wp_die( 'empty payment reference' );
		}

		$order = $this->get_order( $payment_token );

		if ( ! $order ) {
			$camptix->log( "Dying because couldn't find order", null, compact( 'payment_token' ) );
			wp_die( 'could not find order' );
		}

		$options = $this->options;

		$secret_key = $options['sandbox'] ? $options['test_secret_key'] : $options['live_secret_key'];

		$paystack_url 	= 'https://api.paystack.co/transaction/verify/'  . rawurlencode($reference);

		$headers = array(
			'Content-Type'	=> 'application/json',
			'Authorization' => 'Bearer ' . $secret_key,
			'Cache-Control'	=> 'no-cache'
		);

		$args = array(
			'headers'	=> $headers,
			'timeout'	=> 60
		);

		$request = wp_remote_get( $paystack_url, $args );

        if ( ! is_wp_error( $request ) && 200 == wp_remote_retrieve_response_code( $request ) ) {

        	$txn = json_decode( wp_remote_retrieve_body( $request ) );

        	if( 'success' == $txn->data->status ) {

				$txn_id = $txn->data->reference;

				$camptix->log( sprintf( 'Payment details for %s', $txn_id ), $order['attendee_id'], $txn );

				$payment_data = array(
					'transaction_id' => $txn_id,
					'transaction_details' => array(
						'raw' => $txn,
					),
				);

				return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );

        	} else {

				$payment_data = array(
					'error' => 'Error verifying payment',
					'data' => $txn,
				);

				$camptix->log( 'Error verifying payment.', $order['attendee_id'], $txn );
				return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );

        	}

		} else {

        	$txn = json_decode( wp_remote_retrieve_body( $request ) );

			$payment_data = array(
				'error' => 'Error verifying payment',
				'data' => $txn,
			);
			$camptix->log( 'Error verifying payment.', $order['attendee_id'], $txn );
			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );

		}
	}


	function payment_notify() {

		global $camptix;

		if ( ( strtoupper( $_SERVER['REQUEST_METHOD'] ) != 'POST' ) || ! array_key_exists('HTTP_X_PAYSTACK_SIGNATURE', $_SERVER) ) {
			exit;
		}

	    $json = file_get_contents( "php://input" );

	    $options = $this->options;

		$secret_key = $options['sandbox'] ? $options['test_secret_key'] : $options['live_secret_key'];

		// validate event do all at once to avoid timing attack
		if ( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac( 'sha512', $json, $secret_key ) ) {
			exit;
		}

	    $event = json_decode( $json );

	    if ( 'charge.success' == $event->event ) {

			http_response_code( 200 );

	    	$txn_id = $event->data->reference;

			$rel_attendees_query = array(
				'post_type' => 'tix_attendee',
				'post_status' => 'any',
				'posts_per_page' => 5,
				'orderby' => 'ID',
				'order' => 'DESC',
				'meta_query' => array(
					array(
						'key' => 'tix_transaction_id',
						'compare' => '=',
						'value' => $txn_id,
					),
				),
			);

			$attendee = get_posts( $rel_attendees_query );

			if( 'publish' == $attendee[0]->post_status ) {
				return;
			}

			$attendee_id = $attendee[0]->ID;

			$payment_token = get_post_meta( $attendee_id, 'tix_payment_token', true );

			$order = $this->get_order( $payment_token );

			$camptix->log( sprintf( 'Payment details for %s', $txn_id ), $attendee_id, $txn );

			$payment_data = array(
				'transaction_id' => $txn_id,
				'transaction_details' => array(
					'raw' => $txn,
				),
			);

			return $camptix->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $payment_data );

		}

		exit;
	}
}