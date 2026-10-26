<?php
/**
 * PayRam WooCommerce payment gateway.
 *
 * Flow:
 *  - process_payment(): POST {payram_url}/api/v1/payment with the project API-Key
 *    header and { customerEmail, customerID, amountInUSD, invoiceID }. PayRam
 *    returns { url, reference_id, host }. We redirect the buyer to `url`
 *    (PayRam's hosted checkout) and remember reference_id on the order.
 *  - The webhook receiver (in the main plugin file) completes the order when
 *    PayRam reports THIS order's payment (the saved reference_id) FILLED and
 *    the amount paid covers the order total.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_PayRam extends WC_Payment_Gateway {

	/** @var string */
	public $payram_url;
	/** @var string */
	public $api_key;
	/** @var bool */
	public $debug;

	public function __construct() {
		$this->id                 = 'payram';
		$this->method_title       = 'PayRam (Crypto)';
		$this->method_description = 'Accept crypto via a self-hosted PayRam instance.';
		$this->has_fields         = false;
		$this->icon               = plugins_url( 'assets/images/payram-icon.png', PAYRAM_WC_PLUGIN_FILE );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->payram_url  = untrailingslashit( trim( (string) $this->get_option( 'payram_url' ) ) );
		$this->api_key     = trim( (string) $this->get_option( 'api_key' ) );
		$this->debug       = 'yes' === $this->get_option( 'debug' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$webhook_url = home_url( '/wp-json/payram/v1/webhook' );
		$return_url  = home_url( '/?payram_return=1' );

		$this->form_fields = array(
			'enabled'     => array(
				'title'   => 'Enable/Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable PayRam crypto payments',
				'default' => 'no',
			),
			'title'       => array(
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'Shown to the customer at checkout.',
				'default'     => 'PayRam',
				'desc_tip'    => true,
			),
			'description' => array(
				'title'   => 'Description',
				'type'    => 'textarea',
				'default' => 'Accept crypto payments on your store',
			),
			'payram_url'  => array(
				'title'       => 'PayRam URL',
				'type'        => 'text',
				'description' => 'Base URL of your PayRam instance, e.g. http://localhost:8080',
				'default'     => 'http://localhost:8080',
				'desc_tip'    => true,
			),
			'api_key'     => array(
				'title'       => 'Project API Key',
				'type'        => 'password',
				'description' => 'The PayRam project API key (sent as the API-Key header).',
				'default'     => '',
			),
			'webhook_info' => array(
				'title'       => 'Webhook URL',
				'type'        => 'title',
				'description' => 'Register this URL as the webhook in your PayRam project:<br><code>' . esc_html( $webhook_url ) . '</code>',
			),
			'return_info' => array(
				'title'       => 'Return URL',
				'type'        => 'title',
				'description' => 'Set this as your PayRam project Success &amp; Cancel URL so buyers return to their order:<br><code>' . esc_html( $return_url ) . '</code>',
			),
			'debug'       => array(
				'title'   => 'Debug logging',
				'type'    => 'checkbox',
				'label'   => 'Log requests/webhooks to WooCommerce logs (WooCommerce > Status > Logs, source "payram")',
				'default' => 'no',
			),
		);
	}

	/**
	 * Create the payment in PayRam and redirect the buyer to the hosted checkout.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( empty( $this->payram_url ) || empty( $this->api_key ) ) {
			wc_add_notice( 'PayRam is not configured. Please contact the store owner.', 'error' );
			return array( 'result' => 'failure' );
		}

		// PayRam settles in USD (amountInUSD). Convert the order total from the
		// store currency to USD using a live, cached rate. Rate fluctuation
		// between now and payment is intentionally ignored for v1.
		$amount_usd = $this->to_usd( (float) $order->get_total(), $order->get_currency(), $order );
		if ( null === $amount_usd ) {
			wc_add_notice( __( 'Could not fetch an exchange rate right now. Please try again.', 'payram-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$payload = array(
			'customerEmail' => $order->get_billing_email(),
			'customerID'    => $this->get_payram_customer_id( $order ),
			'amountInUSD'   => $amount_usd,
			'invoiceID'     => (string) $order_id,
		);

		$response = wp_remote_post( $this->payram_url . '/api/v1/payment', array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'API-Key'      => $this->api_key,
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( 'create payment error: ' . $response->get_error_message() );
			wc_add_notice( 'Could not reach PayRam. Please try again.', 'error' );
			return array( 'result' => 'failure' );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->log( 'create payment response (' . $code . '): ' . wp_remote_retrieve_body( $response ) );

		$reference_id = ( is_array( $body ) && isset( $body['reference_id'] ) && is_string( $body['reference_id'] ) )
			? sanitize_text_field( $body['reference_id'] )
			: '';
		if ( $code < 200 || $code >= 300 || empty( $body['url'] ) || '' === $reference_id ) {
			$this->log( 'create payment failed for order ' . $order_id . ': HTTP ' . $code . ( '' === $reference_id ? ', no reference_id returned' : '' ), 'error' );
			wc_add_notice( 'PayRam could not create the payment. Please try again.', 'error' );
			return array( 'result' => 'failure' );
		}

		// The webhook acts only on this reference for this order. A new
		// checkout for the same order (the buyer pays again) replaces it; the
		// note keeps every reference the order was given visible to the merchant.
		$order->update_meta_data( '_payram_reference_id', $reference_id );
		$order->update_status( 'pending', __( 'Awaiting PayRam crypto payment.', 'payram-woocommerce' ) );
		$order->add_order_note( sprintf(
			/* translators: %s: PayRam reference id */
			__( 'PayRam checkout created (ref %s).', 'payram-woocommerce' ),
			$reference_id
		) );
		$order->save();

		// Empty the cart and redirect to PayRam's hosted checkout.
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $body['url'] ),
		);
	}

	/**
	 * The customerID this plugin sends PayRam for an order: the WooCommerce
	 * customer id, or the order id for a guest. The webhook compares it with
	 * what PayRam reports for an order that has no saved reference.
	 */
	public function get_payram_customer_id( $order ) {
		return $order->get_customer_id() ? (string) $order->get_customer_id() : (string) $order->get_id();
	}

	/**
	 * Read a payment straight from PayRam, so the webhook acts on PayRam's
	 * record rather than on the webhook body (a validly signed body can be
	 * old, or belong to another payment). Returns the decoded body
	 * ({ paymentState, invoiceID, customerID, amountInUSD, filledAmountInUSD,
	 * ... }), or null when PayRam is unreachable or does not answer 2xx.
	 * GET {payram_url}/api/v1/payment/reference/{reference_id}
	 */
	public function fetch_payment_status( $reference_id ) {
		if ( empty( $reference_id ) || empty( $this->payram_url ) ) {
			return null;
		}
		$response = wp_remote_get( $this->payram_url . '/api/v1/payment/reference/' . rawurlencode( $reference_id ), array(
			'timeout' => 20,
			'headers' => array( 'API-Key' => $this->api_key ),
		) );
		if ( is_wp_error( $response ) ) {
			$this->log( 'verify error: ' . $response->get_error_message(), 'warning' );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->log( 'verify error: PayRam answered HTTP ' . $code . ' for reference ' . $reference_id, 'warning' );
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * The order's current total in US cents, to check against what PayRam
	 * reports as paid. A USD order uses its total as is. Another currency uses
	 * the rate saved when its PayRam checkout was created, never a live rate,
	 * so a rate move after checkout cannot hold a fully paid order. Null when
	 * it cannot be worked out (no saved rate, or the order changed currency).
	 */
	public function get_order_total_usd_cents( $order ) {
		$currency = strtoupper( (string) $order->get_currency() );
		if ( 'USD' === $currency ) {
			return self::usd_to_cents( $order->get_total() );
		}
		$usd_per_unit = (float) $order->get_meta( '_payram_fx_rate' );
		if ( $usd_per_unit <= 0 || strtoupper( (string) $order->get_meta( '_payram_store_currency' ) ) !== $currency ) {
			return null;
		}
		return self::usd_to_cents( sprintf( '%.2F', $this->convert_to_usd( $order->get_total(), $usd_per_unit ) ) );
	}

	/**
	 * A USD amount as whole cents, the third decimal rounded half up (PayRam
	 * reports amounts as decimal strings with up to 18 places). Works on the
	 * digits, not on floats. Null for anything that is not a plain
	 * non-negative amount.
	 */
	public static function usd_to_cents( $amount ) {
		if ( is_int( $amount ) ) {
			$amount = (string) $amount;
		} elseif ( is_float( $amount ) ) {
			$amount = sprintf( '%.6F', $amount );
		}
		if ( ! is_string( $amount ) || ! preg_match( '/^(\d{1,15})(?:\.(\d+))?$/', trim( $amount ), $parts ) ) {
			return null;
		}
		$fraction = isset( $parts[2] ) ? $parts[2] : '';
		$cents    = (int) $parts[1] * 100 + (int) substr( str_pad( $fraction, 2, '0' ), 0, 2 );
		if ( strlen( $fraction ) > 2 && (int) $fraction[2] >= 5 ) {
			$cents++;
		}
		return $cents;
	}

	/** Whole cents as a "12.34" string, for notes and logs. */
	public static function format_cents( $cents ) {
		return sprintf( '%d.%02d', intdiv( $cents, 100 ), $cents % 100 );
	}

	/**
	 * Convert an amount in the store currency to USD. Stores the rate + USD
	 * amount on the order for reconciliation. Returns null on FX failure.
	 */
	private function to_usd( $amount, $currency, $order ) {
		$currency = strtoupper( (string) $currency );
		if ( 'USD' === $currency ) {
			return round( (float) $amount, 2 );
		}
		$usd_per_unit = $this->get_usd_rate( $currency );
		if ( ! $usd_per_unit ) {
			$this->log( 'FX: no rate for ' . $currency );
			return null;
		}
		$usd = $this->convert_to_usd( $amount, $usd_per_unit );
		$order->update_meta_data( '_payram_store_currency', $currency );
		$order->update_meta_data( '_payram_fx_rate', $usd_per_unit );
		$order->update_meta_data( '_payram_amount_usd', $usd );
		return $usd;
	}

	/** USD for an amount at a given rate, rounded to cents as sent to PayRam. */
	private function convert_to_usd( $amount, $usd_per_unit ) {
		return round( (float) $amount * $usd_per_unit, 2 );
	}

	/** USD value of 1 unit of $currency, cached for an hour. Null on failure. */
	private function get_usd_rate( $currency ) {
		$cache_key = 'payram_fx_' . $currency;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (float) $cached;
		}
		// open.er-api.com (free, no key): rates[CUR] = units of CUR per 1 USD.
		$response = wp_remote_get( 'https://open.er-api.com/v6/latest/USD', array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			$this->log( 'FX error: ' . $response->get_error_message() );
			return null;
		}
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$rates = ( is_array( $body ) && isset( $body['rates'] ) && is_array( $body['rates'] ) ) ? $body['rates'] : array();
		if ( empty( $rates[ $currency ] ) || (float) $rates[ $currency ] <= 0 ) {
			return null;
		}
		$usd_per_unit = 1 / (float) $rates[ $currency ];
		set_transient( $cache_key, $usd_per_unit, HOUR_IN_SECONDS );
		return $usd_per_unit;
	}

	/**
	 * Log to WooCommerce > Status > Logs (source "payram"). Routine traffic
	 * ('info') is written only with debug logging on; refusals and failures
	 * ('warning', 'error') are always written, so a merchant can see why an
	 * order did not change without turning debug on first.
	 */
	public function log( $message, $level = 'info' ) {
		if ( 'info' === $level && ! $this->debug ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'payram' ) );
		}
	}
}
