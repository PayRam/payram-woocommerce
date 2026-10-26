<?php
/**
 * Plugin Name: PayRam for WooCommerce
 * Description: Accept crypto payments in WooCommerce via a self-hosted PayRam instance.
 * Version:     0.3.1
 * Author:      PayRam
 * Text Domain: payram-woocommerce
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 11.2
 *
 * This plugin is a BRIDGE: it does not run PayRam. Point it at an existing,
 * already-running PayRam instance (URL + project API key). PayRam handles all
 * crypto/wallet logic; WooCommerce just creates a payment, redirects the buyer
 * to PayRam's hosted checkout, and reacts to PayRam's webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // no direct access
}

define( 'PAYRAM_WC_VERSION', '0.3.1' );
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

	$order = payram_wc_find_order( $invoice_id, $reference_id );

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
 *
 * A valid signature proves the webhook came from the PayRam project, not that
 * it is about this order: every store registered on the project receives every
 * payment's webhook, and an order's total can change after its checkout was
 * created. So the order changes only when the webhook's reference is the one
 * this order's checkout was given, and PayRam's own record of that payment
 * (not the webhook body) says it is paid in full.
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
	$invoice_id   = isset( $body['invoice_id'] ) && is_scalar( $body['invoice_id'] ) ? (string) $body['invoice_id'] : '';
	$reference_id = isset( $body['reference_id'] ) && is_scalar( $body['reference_id'] ) ? (string) $body['reference_id'] : '';
	$gateway->log( 'Webhook received (hmac): ' . wp_json_encode( $body ) );
	if ( '' === $reference_id ) {
		$gateway->log( 'Webhook refused: signed body has no reference_id (invoice_id=' . $invoice_id . ')', 'warning' );
		return new WP_REST_Response( array( 'error' => 'reference_id missing' ), 400 );
	}

	// 2. Find the order (invoice_id == WooCommerce order id).
	$order = payram_wc_find_order( $invoice_id, $reference_id );
	if ( ! $order ) {
		$gateway->log( 'Webhook: no matching order for invoice_id=' . $invoice_id . ' reference_id=' . $reference_id );
		return new WP_REST_Response( array( 'error' => 'order not found' ), 404 );
	}

	// 3. The webhook must be about THIS order's payment. An order whose
	// checkout this plugin created carries the reference PayRam gave it; any
	// other reference is someone else's payment (another store on the same
	// project, an older checkout of this order) and changes nothing here.
	$saved_reference = (string) $order->get_meta( '_payram_reference_id' );
	if ( '' !== $saved_reference && $saved_reference !== $reference_id ) {
		return payram_wc_ignore_webhook( $gateway, $order, $reference_id, sprintf(
			/* translators: 1: reference id in the webhook, 2: reference id of this order's checkout */
			__( 'It is for PayRam payment %1$s, but this order\'s PayRam checkout is %2$s.', 'payram-woocommerce' ),
			$reference_id,
			$saved_reference
		) );
	}
	if ( '' === $saved_reference && 'payram' !== $order->get_payment_method() ) {
		return payram_wc_ignore_webhook( $gateway, $order, $reference_id, sprintf(
			/* translators: %s: reference id in the webhook */
			__( 'It is for PayRam payment %s, but this order was not placed with PayRam.', 'payram-woocommerce' ),
			$reference_id
		) );
	}

	// 4. Re-verify with PayRam: act on PayRam's own record of the payment
	// (state and amounts), never on the webhook body, which could be an old
	// but validly signed delivery. FAIL CLOSED: if PayRam is unreachable or
	// returns no usable state, answer 503 so PayRam redelivers later.
	$verified       = $gateway->fetch_payment_status( $reference_id );
	$verified_state = ( is_array( $verified ) && isset( $verified['paymentState'] ) && is_string( $verified['paymentState'] ) )
		? $verified['paymentState']
		: '';
	if ( '' === $verified_state ) {
		$gateway->log( 'Webhook: could not re-verify reference ' . $reference_id . ' with PayRam - asking for redelivery (503)', 'warning' );
		return new WP_REST_Response( array( 'error' => 'verification unavailable, retry later' ), 503 );
	}
	$status = strtoupper( $verified_state );
	$gateway->log( 'Webhook: re-verified paymentState=' . $status );

	// 5. An order with no saved reference (none of this plugin's checkouts
	// should lack one) is matched on what PayRam recorded when the payment was
	// created: this order's id as invoiceID and its customer as customerID.
	if ( '' === $saved_reference ) {
		$payram_invoice  = isset( $verified['invoiceID'] ) && is_scalar( $verified['invoiceID'] ) ? (string) $verified['invoiceID'] : '';
		$payram_customer = isset( $verified['customerID'] ) && is_scalar( $verified['customerID'] ) ? (string) $verified['customerID'] : '';
		if ( (string) $order->get_id() !== $payram_invoice || $gateway->get_payram_customer_id( $order ) !== $payram_customer ) {
			return payram_wc_ignore_webhook( $gateway, $order, $reference_id, sprintf(
				/* translators: 1: reference id, 2: PayRam invoice id, 3: PayRam customer id, 4: this order's id, 5: this order's customer id */
				__( 'PayRam records payment %1$s for invoice "%2$s" and customer "%3$s"; this order is invoice "%4$s" for customer "%5$s".', 'payram-woocommerce' ),
				$reference_id,
				$payram_invoice,
				$payram_customer,
				(string) $order->get_id(),
				$gateway->get_payram_customer_id( $order )
			) );
		}
	}

	// 6. Idempotency — if already paid, or this payment already completed the
	// order once (its reference is the transaction id), ack and stop. The
	// second check keeps a redelivered webhook from reopening an order the
	// merchant cancelled after it was paid.
	if ( $order->is_paid() || $order->get_transaction_id() === $reference_id ) {
		return new WP_REST_Response( array( 'received' => true, 'note' => 'already paid' ), 200 );
	}

	// 7. Map PayRam state -> WooCommerce.
	switch ( $status ) {
		case 'FILLED':
		case 'OVER_FILLED':
			// Complete only when what PayRam confirmed as paid covers the
			// order's current total (it may have been raised after checkout).
			$paid_cents = WC_Gateway_PayRam::usd_to_cents( isset( $verified['filledAmountInUSD'] ) ? $verified['filledAmountInUSD'] : null );
			$due_cents  = $gateway->get_order_total_usd_cents( $order );
			if ( null === $paid_cents || null === $due_cents || $paid_cents < $due_cents ) {
				if ( null === $paid_cents ) {
					/* translators: 1: PayRam payment state, 2: reference id */
					$reason = sprintf( __( 'PayRam reports payment %2$s as %1$s but did not say how much was paid.', 'payram-woocommerce' ), $status, $reference_id );
				} elseif ( null === $due_cents ) {
					/* translators: 1: PayRam payment state, 2: reference id, 3: currency code */
					$reason = sprintf( __( 'PayRam reports payment %2$s as %1$s, but this order\'s %3$s total could not be converted to USD (no exchange rate was saved at checkout).', 'payram-woocommerce' ), $status, $reference_id, $order->get_currency() );
				} else {
					/* translators: 1: PayRam payment state, 2: reference id, 3: USD paid, 4: order total in USD */
					$reason = sprintf( __( 'PayRam reports payment %2$s as %1$s with %3$s USD paid, but the order total is %4$s USD.', 'payram-woocommerce' ), $status, $reference_id, WC_Gateway_PayRam::format_cents( $paid_cents ), WC_Gateway_PayRam::format_cents( $due_cents ) );
				}
				$gateway->log( 'Webhook: order ' . $order->get_id() . ' put on hold, not completed: ' . $reason, 'warning' );
				$hold_note = $reason . ' ' . __( 'The order is on hold: check the payment in PayRam before shipping.', 'payram-woocommerce' );
				if ( $order->has_status( 'on-hold' ) ) {
					$order->add_order_note( $hold_note ); // update_status() adds no note when the status does not change
				} else {
					$order->update_status( 'on-hold', $hold_note );
				}
				break;
			}
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

/**
 * Refuse a signed webhook that is not about this order's payment: log why,
 * leave a note on the order for the merchant, change nothing. Answers 200 so
 * PayRam does not keep redelivering a webhook that can never apply here.
 */
function payram_wc_ignore_webhook( $gateway, $order, $reference_id, $reason ) {
	$gateway->log( 'Webhook ignored for order ' . $order->get_id() . ' (reference ' . $reference_id . '): ' . $reason, 'warning' );
	$order->add_order_note( sprintf(
		/* translators: %s: why the update does not apply to this order */
		__( 'PayRam: a payment update was ignored and this order was not changed. %s', 'payram-woocommerce' ),
		$reason
	) );
	return new WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
}

/**
 * Helper: the order PayRam names, by invoice id (= WooCommerce order id),
 * else by the stored reference id. Used by the buyer return and the webhook.
 */
function payram_wc_find_order( $invoice_id, $reference_id ) {
	$order = '' !== (string) $invoice_id ? wc_get_order( $invoice_id ) : false;
	if ( ! $order && '' !== (string) $reference_id ) {
		$order = payram_wc_find_order_by_reference( $reference_id );
	}
	return $order;
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
