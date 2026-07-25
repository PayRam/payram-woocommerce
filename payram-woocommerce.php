<?php
/**
 * Plugin Name: PayRam for WooCommerce
 * Description: Accept crypto payments in WooCommerce via a self-hosted PayRam instance.
 * Version:     0.3.0
 * Author:      PayRam
 * Text Domain: payram-woocommerce
 * Domain Path: /languages
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 *
 * This plugin is a BRIDGE: it does not run PayRam. Point it at an existing,
 * already-running PayRam instance (URL + project API key). PayRam handles all
 * crypto/wallet logic; WooCommerce just creates a payment, redirects the buyer
 * to PayRam's hosted checkout, and reacts to PayRam's webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // no direct access
}

define( 'PAYRAM_WC_VERSION', '0.3.0' );
define( 'PAYRAM_WC_PLUGIN_FILE', __FILE__ );

/**
 * Declare High-Performance Order Storage (custom order tables) compatibility.
 * The plugin only uses WooCommerce CRUD APIs, so it is HPOS-safe.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/** Load translations. */
add_action( 'init', function () {
	load_plugin_textdomain( 'payram-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Register the gateway with WooCommerce (only if WooCommerce is active).
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'PayRam for WooCommerce requires WooCommerce to be installed and active.', 'payram-woocommerce' ) . '</p></div>';
		} );
		return;
	}

	require_once __DIR__ . '/includes/class-wc-gateway-payram.php';

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_Gateway_PayRam';
		return $gateways;
	} );
} );

/**
 * Register the gateway with the WooCommerce Cart/Checkout BLOCKS so it appears
 * on the modern (block-based) checkout, not only the classic shortcode one.
 */
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once __DIR__ . '/includes/class-wc-gateway-payram-blocks.php';
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		$registry->register( new WC_Gateway_PayRam_Blocks() );
	} );
} );

/**
 * Register the webhook receiver REST route: POST /wp-json/payram/v1/webhook
 * PayRam calls this when a payment's state changes.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'payram/v1', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'payram_wc_handle_webhook',
		'permission_callback' => '__return_true', // auth is checked inside (HMAC signature)
	) );
} );

/**
 * Buyer-return handler. Point your PayRam project's Success/Cancel URL at:
 *   https://your-store.com/?payram_return=1
 * PayRam's hosted checkout appends &reference_id=…&invoice_id=… on redirect,
 * and we forward the buyer to the matching order's "order received" page.
 */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['payram_return'] ) ) {
		return;
	}
	$invoice_id   = isset( $_GET['invoice_id'] ) ? sanitize_text_field( wp_unslash( $_GET['invoice_id'] ) ) : '';
	$reference_id = isset( $_GET['reference_id'] ) ? sanitize_text_field( wp_unslash( $_GET['reference_id'] ) ) : '';

	$order = $invoice_id ? wc_get_order( $invoice_id ) : false;
	if ( ! $order && $reference_id ) {
		$order = payram_wc_find_order_by_reference( $reference_id );
	}

	$url = $order ? $order->get_checkout_order_received_url() : wc_get_page_permalink( 'myaccount' );
	wp_safe_redirect( $url );
	exit;
} );

/**
 * Webhook handler. PayRam payload (see core utils.go):
 *   { customer_id, invoice_id, reference_id, status, amount, currency,
 *     filled_amount, filled_amount_in_usd, timestamp, ... }
 * status ∈ OPEN | PARTIALLY_FILLED | FILLED | OVER_FILLED | CANCELLED
 *
 * Auth:
 *   - "X-Payram-Signature: sha256=<hex>" = HMAC-SHA256(raw body, project API key).
 */
function payram_wc_handle_webhook( WP_REST_Request $request ) {
	$gateway = payram_wc_get_gateway();
	if ( ! $gateway ) {
		return new WP_REST_Response( array( 'error' => 'gateway not configured' ), 500 );
	}

	// 1. Authenticate: HMAC signature over the raw body.
	$raw      = $request->get_body();
	$sent_sig = (string) $request->get_header( 'x_payram_signature' ); // "X-Payram-Signature"

	$sig_ok = false;
	if ( $sent_sig !== '' && strpos( $sent_sig, 'sha256=' ) === 0 ) {
		$expected = 'sha256=' . hash_hmac( 'sha256', $raw, (string) $gateway->api_key );
		$sig_ok   = hash_equals( $expected, $sent_sig );
	}
	if ( ! $sig_ok ) {
		$gateway->log( 'Webhook rejected: missing or invalid HMAC signature' );
		return new WP_REST_Response( array( 'error' => 'unauthorised' ), 401 );
	}

	$body         = $request->get_json_params();
	$invoice_id   = isset( $body['invoice_id'] ) ? $body['invoice_id'] : '';
	$reference_id = isset( $body['reference_id'] ) ? $body['reference_id'] : '';
	$status       = isset( $body['status'] ) ? strtoupper( (string) $body['status'] ) : '';
	$gateway->log( 'Webhook received (hmac): ' . wp_json_encode( $body ) );

	// 2. Find the order (invoice_id == WooCommerce order id).
	$order = $invoice_id ? wc_get_order( $invoice_id ) : false;
	if ( ! $order && $reference_id ) {
		$order = payram_wc_find_order_by_reference( $reference_id );
	}
	if ( ! $order ) {
		$gateway->log( 'Webhook: no matching order for invoice_id=' . $invoice_id . ' reference_id=' . $reference_id );
		return new WP_REST_Response( array( 'error' => 'order not found' ), 404 );
	}

	// 3. Defence-in-depth: re-verify with PayRam. The verify endpoint
	// (GET /payment/reference/{id}) reports the state under `paymentState`,
	// using the same vocabulary as the webhook `status` (FILLED,
	// PARTIALLY_FILLED, OVER_FILLED, OPEN, CANCELLED). Prefer it; fall back to
	// `status` for older cores. FAIL CLOSED: if PayRam is unreachable or
	// returns no usable state, answer 503 — PayRam's webhook delivery retries
	// (quick retries + long-term delivery log), so the order is updated on a
	// later attempt. The signed webhook body alone is never trusted for order
	// transitions (a replayed old-but-validly-signed body would otherwise pass).
	$verified       = $gateway->fetch_payment_status( $reference_id );
	$verified_state = '';
	if ( is_array( $verified ) ) {
		if ( isset( $verified['paymentState'] ) ) {
			$verified_state = (string) $verified['paymentState'];
		} elseif ( isset( $verified['status'] ) ) {
			$verified_state = (string) $verified['status'];
		}
	}
	if ( '' === $verified_state ) {
		$gateway->log( 'Webhook: could not re-verify reference ' . $reference_id . ' with PayRam - asking for redelivery (503)' );
		return new WP_REST_Response( array( 'error' => 'verification unavailable, retry later' ), 503 );
	}
	$status = strtoupper( $verified_state );
	$gateway->log( 'Webhook: re-verified paymentState=' . $status );

	// 4. Idempotency — if already paid, ack and stop.
	if ( $order->is_paid() ) {
		return new WP_REST_Response( array( 'received' => true, 'note' => 'already paid' ), 200 );
	}

	// 5. Map PayRam state -> WooCommerce.
	switch ( $status ) {
		case 'FILLED':
		case 'OVER_FILLED':
			$order->payment_complete( $reference_id );
			$order->add_order_note( sprintf(
				/* translators: 1: PayRam payment state, 2: reference id */
				__( 'PayRam: payment %1$s (ref %2$s)', 'payram-woocommerce' ),
				$status,
				$reference_id
			) );
			break;
		case 'PARTIALLY_FILLED':
			$order->update_status( 'on-hold', __( 'PayRam: partial payment received.', 'payram-woocommerce' ) );
			break;
		case 'CANCELLED':
			$order->update_status( 'cancelled', __( 'PayRam: payment cancelled.', 'payram-woocommerce' ) );
			break;
		default: // OPEN / UNDEFINED — nothing to do yet
			$gateway->log( 'Webhook: no-op for status=' . $status );
			break;
	}

	return new WP_REST_Response( array( 'received' => true ), 200 );
}

/** Helper: get a configured instance of the gateway. */
function payram_wc_get_gateway() {
	if ( ! class_exists( 'WC_Gateway_PayRam' ) ) {
		return null;
	}
	$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
	return isset( $gateways['payram'] ) ? $gateways['payram'] : new WC_Gateway_PayRam();
}

/** Helper: locate an order by stored PayRam reference id. */
function payram_wc_find_order_by_reference( $reference_id ) {
	$orders = wc_get_orders( array(
		'limit'      => 1,
		'meta_key'   => '_payram_reference_id',
		'meta_value' => $reference_id,
	) );
	return ! empty( $orders ) ? $orders[0] : false;
}
