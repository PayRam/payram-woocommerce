<?php
/**
 * Webhook behaviour tests for payram-woocommerce. Run by tests/e2e/run.sh
 * with `wp eval-file`; not part of the plugin and not in the release zip.
 *
 * The CLI process is the test client: it creates orders (process_payment talks
 * to the stand-in PayRam), sends signed webhooks over real HTTP to the
 * Apache-served site, and reads the orders back. Host names match run.sh.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit; // CLI only
}

const PAYRAM  = 'http://payram-woo-e2e-payram:8080';
const WEBHOOK = 'http://payram-woo-e2e-wp/wp-json/payram/v1/webhook';
const KEY     = 'test-key';

add_filter( 'pre_wp_mail', '__return_false' ); // no mail server in the test network

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = array();

function check( $name, $ok, $detail = '' ) {
	if ( $ok ) {
		$GLOBALS['pass']++;
		echo "  PASS  $name\n";
	} else {
		$GLOBALS['fail'][] = $name;
		echo "  FAIL  $name  $detail\n";
	}
}

function ctl( $path, $body ) {
	$r = wp_remote_post( PAYRAM . $path, array( 'body' => wp_json_encode( $body ), 'headers' => array( 'Content-Type' => 'application/json' ) ) );
	if ( is_wp_error( $r ) ) {
		throw new Exception( 'control call failed: ' . $r->get_error_message() );
	}
	return json_decode( wp_remote_retrieve_body( $r ), true );
}

function hits( $ref ) {
	$r = wp_remote_get( PAYRAM . '/_control/hits/' . rawurlencode( $ref ) );
	return (int) json_decode( wp_remote_retrieve_body( $r ), true )['hits'];
}

function settings( $overrides = array() ) {
	update_option( 'woocommerce_payram_settings', array_merge( array(
		'enabled'     => 'yes',
		'title'       => 'PayRam',
		'description' => 'Pay with crypto',
		'payram_url'  => PAYRAM,
		'api_key'     => KEY,
		'debug'       => 'yes',
	), $overrides ) );
}

/** POST a webhook over HTTP. $sig: null = sign with $key, false = no header, string = send as is. */
function webhook( $body, $key = KEY, $sig = null ) {
	$raw     = wp_json_encode( $body );
	$headers = array( 'Content-Type' => 'application/json' );
	if ( null === $sig ) {
		$headers['X-Payram-Signature'] = 'sha256=' . hash_hmac( 'sha256', $raw, $key );
	} elseif ( false !== $sig ) {
		$headers['X-Payram-Signature'] = $sig;
	}
	$r = wp_remote_post( WEBHOOK, array( 'body' => $raw, 'headers' => $headers, 'timeout' => 30 ) );
	if ( is_wp_error( $r ) ) {
		throw new Exception( 'webhook call failed: ' . $r->get_error_message() );
	}
	return array( (int) wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
}

function reload( $id ) {
	wp_cache_flush();
	return wc_get_order( $id );
}

function notes_text( $id ) {
	wp_cache_flush();
	$out = array();
	foreach ( wc_get_order_notes( array( 'order_id' => $id ) ) as $n ) {
		$out[] = $n->content;
	}
	return implode( "\n", $out );
}

function make_order( $total, $method = 'payram', $currency = 'USD', $customer_id = 0 ) {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	$order->set_billing_email( 'buyer@example.invalid' );
	$order->set_currency( $currency );
	$fee = new WC_Order_Item_Fee();
	$fee->set_name( 'Item' );
	$fee->set_amount( $total );
	$fee->set_total( $total );
	$order->add_item( $fee );
	$order->set_payment_method( $method );
	$order->calculate_totals( false );
	$order->save();
	return $order->get_id();
}

function pay( $id ) {
	$gw = new WC_Gateway_PayRam();
	$r  = $gw->process_payment( $id );
	return array( $r, (string) reload( $id )->get_meta( '_payram_reference_id' ) );
}

function raise_total( $id, $extra ) {
	$order = reload( $id );
	$fee   = new WC_Order_Item_Fee();
	$fee->set_name( 'Added after checkout' );
	$fee->set_amount( $extra );
	$fee->set_total( $extra );
	$order->add_item( $fee );
	$order->calculate_totals( false );
	$order->save();
}

function log_text() {
	$out = '';
	foreach ( glob( WP_CONTENT_DIR . '/uploads/wc-logs/payram-*.log' ) as $f ) {
		$out .= file_get_contents( $f );
	}
	return $out;
}

if ( ! WC()->session ) {
	WC()->session = new WC_Session_Handler();
	WC()->session->init();
}

$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off';
echo 'PHP ' . PHP_VERSION . ' (client) | WP ' . get_bloginfo( 'version' ) . ' | WC ' . WC()->version . ' | plugin ' . PAYRAM_WC_VERSION . " | HPOS $hpos\n";
settings();

// ---------------------------------------------------------------- unit: cents
if ( method_exists( 'WC_Gateway_PayRam', 'usd_to_cents' ) ) {
echo "[unit] usd_to_cents\n";
$cases = array(
	array( '10', 1000 ), array( '10.00', 1000 ), array( '9.999999999999999999', 1000 ), array( '9.994', 999 ),
	array( '9.995', 1000 ), array( '0.000001', 0 ), array( 10, 1000 ), array( 10.5, 1050 ), array( '25.50', 2550 ),
	array( '-1', null ), array( 'abc', null ), array( '', null ), array( null, null ), array( array( 1 ), null ),
	array( '1e3', null ), array( '10.', null ), array( ' 7.1 ', 710 ), array( '1234567890123456', null ),
);
foreach ( $cases as $c ) {
	$got = WC_Gateway_PayRam::usd_to_cents( $c[0] );
	check( 'usd_to_cents(' . wp_json_encode( $c[0] ) . ') = ' . wp_json_encode( $c[1] ), $got === $c[1], 'got ' . wp_json_encode( $got ) );
}
check( 'format_cents(2550) = 25.50', '25.50' === WC_Gateway_PayRam::format_cents( 2550 ) );
check( 'format_cents(5) = 0.05', '0.05' === WC_Gateway_PayRam::format_cents( 5 ) );
} else {
	echo "[unit] skipped: usd_to_cents not in this version\n";
}

// ----------------------------------------------------- A: matching reference
echo "[A] matching reference completes\n";
$a = make_order( '10.00' );
list( $res, $ref_a ) = pay( $a );
check( 'process_payment succeeds and saves the reference', 'success' === $res['result'] && '' !== $ref_a, wp_json_encode( $res ) );
check( 'checkout note records the reference', false !== strpos( notes_text( $a ), "PayRam checkout created (ref $ref_a)" ) );
ctl( '/_control/set', array( 'reference_id' => $ref_a, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10.000000' ) );
list( $code, $body ) = webhook( array( 'invoice_id' => (string) $a, 'reference_id' => $ref_a, 'status' => 'FILLED' ) );
$o = reload( $a );
check( 'A1 FILLED + covers total -> 200, paid (fee-only order: WooCommerce marks it completed)', 200 === $code && $o->is_paid() && in_array( $o->get_status(), array( 'processing', 'completed' ), true ), "code=$code status=" . $o->get_status() );
check( 'A1 transaction id = reference', $ref_a === $o->get_transaction_id() );
list( $code, $body ) = webhook( array( 'invoice_id' => (string) $a, 'reference_id' => $ref_a, 'status' => 'FILLED' ) );
check( 'A2 replay -> 200 already paid', 200 === $code && 'already paid' === ( $body['note'] ?? '' ), wp_json_encode( $body ) );
$o->update_status( 'cancelled', 'merchant cancelled after payment' );
list( $code, $body ) = webhook( array( 'invoice_id' => (string) $a, 'reference_id' => $ref_a, 'status' => 'FILLED' ) );
check( 'A3 replay after merchant cancel -> stays cancelled', 200 === $code && 'cancelled' === reload( $a )->get_status(), "code=$code status=" . reload( $a )->get_status() );

$ao = make_order( '10.00' );
list( , $ref_ao ) = pay( $ao );
ctl( '/_control/set', array( 'reference_id' => $ref_ao, 'paymentState' => 'OVER_FILLED', 'filledAmountInUSD' => '12.345678' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $ao, 'reference_id' => $ref_ao, 'status' => 'OVER_FILLED' ) );
check( 'A4 OVER_FILLED covering total -> paid', 200 === $code && reload( $ao )->is_paid() );

// -------------------------------------------------- B: mismatched reference
echo "[B] mismatched reference refused\n";
$b = make_order( '10.00' );
list( , $ref_b ) = pay( $b );
$other = 'other-store-' . $b;
ctl( '/_control/set', array( 'reference_id' => $other, 'invoiceID' => (string) $b, 'customerID' => (string) $b, 'amountInUSD' => '10', 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
list( $code, $body ) = webhook( array( 'invoice_id' => (string) $b, 'reference_id' => $other, 'status' => 'FILLED' ) );
$o = reload( $b );
check( 'B1 FILLED for another reference -> 200 ignored, order stays pending', 200 === $code && ! empty( $body['ignored'] ) && 'pending' === $o->get_status(), "code=$code body=" . wp_json_encode( $body ) . ' status=' . $o->get_status() );
check( 'B1 note names both references', false !== strpos( notes_text( $b ), "It is for PayRam payment $other, but this order's PayRam checkout is $ref_b" ), notes_text( $b ) );
check( 'B1 PayRam not called for a reference that is not this order\'s', 0 === hits( $other ), 'hits=' . hits( $other ) );
ctl( '/_control/set', array( 'reference_id' => $other, 'paymentState' => 'CANCELLED' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $b, 'reference_id' => $other, 'status' => 'CANCELLED' ) );
check( 'B2 CANCELLED for another reference -> order not cancelled', 200 === $code && 'pending' === reload( $b )->get_status() );

$b3 = make_order( '10.00' );
list( , $ref_old ) = pay( $b3 );
list( , $ref_new ) = pay( $b3 );
check( 'B3 paying again replaces the saved reference', $ref_old !== $ref_new && '' !== $ref_new );
ctl( '/_control/set', array( 'reference_id' => $ref_old, 'paymentState' => 'CANCELLED' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $b3, 'reference_id' => $ref_old, 'status' => 'CANCELLED' ) );
check( 'B3 old checkout CANCELLED -> order not cancelled', 200 === $code && 'pending' === reload( $b3 )->get_status(), 'status=' . reload( $b3 )->get_status() );
ctl( '/_control/set', array( 'reference_id' => $ref_new, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $b3, 'reference_id' => $ref_new, 'status' => 'FILLED' ) );
check( 'B3 current checkout FILLED -> paid', 200 === $code && reload( $b3 )->is_paid() );

// ------------------------------------------------------- C: underpaid FILLED
echo "[C] FILLED that does not cover the order total -> on hold\n";
$c = make_order( '10.00' );
list( , $ref_c ) = pay( $c );
raise_total( $c, '15.00' );
check( 'C0 order total raised to 25.00 after checkout', '25.00' === reload( $c )->get_total() );
ctl( '/_control/set', array( 'reference_id' => $ref_c, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10.000000' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $c, 'reference_id' => $ref_c, 'status' => 'FILLED' ) );
$o = reload( $c );
check( 'C1 FILLED 10.00 on a 25.00 order -> on-hold, not paid', 200 === $code && 'on-hold' === $o->get_status() && ! $o->is_paid(), "code=$code status=" . $o->get_status() );
check( 'C1 note states paid and due', false !== strpos( notes_text( $c ), 'with 10.00 USD paid, but the order total is 25.00 USD' ), notes_text( $c ) );
ctl( '/_control/set', array( 'reference_id' => $ref_c, 'paymentState' => 'OVER_FILLED', 'filledAmountInUSD' => '25.000001' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $c, 'reference_id' => $ref_c, 'status' => 'OVER_FILLED' ) );
check( 'C2 later top-up to 25.00 -> paid', 200 === $code && reload( $c )->is_paid() );

$c3 = make_order( '10.00' );
list( , $ref_c3 ) = pay( $c3 );
ctl( '/_control/set', array( 'reference_id' => $ref_c3, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '__delete__' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $c3, 'reference_id' => $ref_c3, 'status' => 'FILLED' ) );
check( 'C3 FILLED with no filledAmountInUSD -> on-hold', 200 === $code && 'on-hold' === reload( $c3 )->get_status() );
check( 'C3 note says the amount was not reported', false !== strpos( notes_text( $c3 ), 'did not say how much was paid' ) );
ctl( '/_control/set', array( 'reference_id' => $ref_c3, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '9.99' ) );
webhook( array( 'invoice_id' => (string) $c3, 'reference_id' => $ref_c3, 'status' => 'FILLED' ) );
check( 'C4 already on hold, still short -> second note added', false !== strpos( notes_text( $c3 ), 'with 9.99 USD paid, but the order total is 10.00 USD' ) && 'on-hold' === reload( $c3 )->get_status() );

$c5 = make_order( '10.00' );
list( , $ref_c5 ) = pay( $c5 );
ctl( '/_control/set', array( 'reference_id' => $ref_c5, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '9.999999999999999999' ) );
webhook( array( 'invoice_id' => (string) $c5, 'reference_id' => $ref_c5, 'status' => 'FILLED' ) );
check( 'C5 decimal dust below 10.00 (9.999999999999999999) still completes', reload( $c5 )->is_paid() );

// ------------------------------------------------------------ D: legacy path
echo "[D] orders with no saved reference\n";
$d = make_order( '10.00' );
$ref_d = 'legacy-' . $d;
ctl( '/_control/set', array( 'reference_id' => $ref_d, 'invoiceID' => (string) $d, 'customerID' => (string) $d, 'amountInUSD' => '10', 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $d, 'reference_id' => $ref_d, 'status' => 'FILLED' ) );
check( 'D1 legacy PayRam order, invoiceID + customerID match -> paid', 200 === $code && reload( $d )->is_paid(), "code=$code status=" . reload( $d )->get_status() );

$uid = wp_insert_user( array( 'user_login' => 'buyer' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'b' . wp_rand() . '@example.invalid' ) );
$d2  = make_order( '10.00', 'payram', 'USD', $uid );
$ref_d2 = 'legacy-' . $d2;
ctl( '/_control/set', array( 'reference_id' => $ref_d2, 'invoiceID' => (string) $d2, 'customerID' => (string) $d2, 'amountInUSD' => '10', 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
list( $code, $body ) = webhook( array( 'invoice_id' => (string) $d2, 'reference_id' => $ref_d2, 'status' => 'FILLED' ) );
check( 'D2 legacy order, customerID differs -> 200 ignored, pending', 200 === $code && ! empty( $body['ignored'] ) && 'pending' === reload( $d2 )->get_status(), "code=$code status=" . reload( $d2 )->get_status() );
check( 'D2 note shows what PayRam recorded vs the order', false !== strpos( notes_text( $d2 ), "customer \"$d2\"; this order is invoice \"$d2\" for customer \"$uid\"" ), notes_text( $d2 ) );

$d3 = make_order( '10.00' );
$ref_d3 = 'legacy-' . $d3;
ctl( '/_control/set', array( 'reference_id' => $ref_d3, 'invoiceID' => 'someone-else', 'customerID' => (string) $d3, 'amountInUSD' => '10', 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $d3, 'reference_id' => $ref_d3, 'status' => 'FILLED' ) );
check( 'D3 legacy order, invoiceID differs -> not paid', 200 === $code && 'pending' === reload( $d3 )->get_status() );

$d4 = make_order( '10.00', 'bacs' );
$ref_d4 = 'legacy-' . $d4;
ctl( '/_control/set', array( 'reference_id' => $ref_d4, 'invoiceID' => (string) $d4, 'customerID' => (string) $d4, 'amountInUSD' => '10', 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
list( $code, $body ) = webhook( array( 'invoice_id' => (string) $d4, 'reference_id' => $ref_d4, 'status' => 'FILLED' ) );
check( 'D4 order placed with another gateway -> 200 ignored, pending', 200 === $code && ! empty( $body['ignored'] ) && 'pending' === reload( $d4 )->get_status() );
check( 'D4 note says not placed with PayRam; PayRam not called', false !== strpos( notes_text( $d4 ), 'this order was not placed with PayRam' ) && 0 === hits( $ref_d4 ) );

$d5 = make_order( '10.00' );
list( , $ref_d5 ) = pay( $d5 );
ctl( '/_control/set', array( 'reference_id' => $ref_d5, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
list( $code ) = webhook( array( 'invoice_id' => 'not-an-order-id', 'reference_id' => $ref_d5, 'status' => 'FILLED' ) );
check( 'D5 invoice_id names no order -> found by saved reference -> paid', 200 === $code && reload( $d5 )->is_paid(), "code=$code" );

// --------------------------------------------------------- E: no API key set
echo "[E] no API key: every webhook refused\n";
$e = make_order( '10.00' );
list( , $ref_e ) = pay( $e );
ctl( '/_control/set', array( 'reference_id' => $ref_e, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
settings( array( 'api_key' => '', 'debug' => 'no' ) );
$before = substr_count( log_text(), 'Webhook refused: no PayRam API key is set' );
list( $code, $body ) = webhook( array( 'invoice_id' => (string) $e, 'reference_id' => $ref_e, 'status' => 'FILLED' ), '' );
check( 'E1 webhook signed with the empty key -> 503, order unchanged', 503 === $code && 'pending' === reload( $e )->get_status(), "code=$code body=" . wp_json_encode( $body ) );
check( 'E1 refusal logged with debug logging off', substr_count( log_text(), 'Webhook refused: no PayRam API key is set' ) === $before + 1 );
list( $code ) = webhook( array( 'invoice_id' => (string) $e, 'reference_id' => $ref_e, 'status' => 'FILLED' ), 'test-key' );
check( 'E2 any signature with no key set -> 503', 503 === $code );
settings( array( 'api_key' => '   ', 'debug' => 'no' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $e, 'reference_id' => $ref_e, 'status' => 'FILLED' ), '' );
check( 'E3 whitespace-only key counts as no key -> 503', 503 === $code );
settings( array( 'debug' => 'no' ) );
$received_before = substr_count( log_text(), 'Webhook received (hmac)' );
$ignored_before  = substr_count( log_text(), 'Webhook ignored for order ' . $b . ' ' );
webhook( array( 'invoice_id' => (string) $b, 'reference_id' => $other, 'status' => 'FILLED' ) );
check( 'E4 debug off: refusal still logged, routine lines are not', substr_count( log_text(), 'Webhook ignored for order ' . $b . ' ' ) === $ignored_before + 1 && substr_count( log_text(), 'Webhook received (hmac)' ) === $received_before );
settings();
list( $code ) = webhook( array( 'invoice_id' => (string) $e, 'reference_id' => $ref_e, 'status' => 'FILLED' ) );
check( 'E5 key set again -> redelivery completes the order', 200 === $code && reload( $e )->is_paid() );

// ----------------------------------------------- F/G: auth + verify failures
echo "[F] signature and [G] verification\n";
$f = make_order( '10.00' );
list( , $ref_f ) = pay( $f );
ctl( '/_control/set', array( 'reference_id' => $ref_f, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $f, 'reference_id' => $ref_f ), KEY, false );
check( 'F1 no signature -> 401', 401 === $code );
list( $code ) = webhook( array( 'invoice_id' => (string) $f, 'reference_id' => $ref_f ), 'wrong-key' );
check( 'F2 wrong key -> 401', 401 === $code );
list( $code ) = webhook( array( 'invoice_id' => (string) $f, 'status' => 'FILLED' ) );
check( 'F3 signed body without reference_id -> 400', 400 === $code );
ctl( '/_control/raw', array( 'reference_id' => $ref_f, 'status' => 200, 'body' => array( 'status' => 'FILLED' ) ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $f, 'reference_id' => $ref_f, 'status' => 'FILLED' ) );
check( 'G1 verify answers 200 without paymentState -> 503, not paid', 503 === $code && ! reload( $f )->is_paid() );
ctl( '/_control/raw', array( 'reference_id' => $ref_f, 'status' => 500, 'body' => array( 'paymentState' => 'FILLED', 'filledAmountInUSD' => '10' ) ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $f, 'reference_id' => $ref_f, 'status' => 'FILLED' ) );
check( 'G2 verify answers 500 (even with a FILLED body) -> 503, not paid', 503 === $code && ! reload( $f )->is_paid() );

// -------------------------------------------------- H: non-USD store currency
echo "[H] EUR order, rate saved at checkout\n";
set_transient( 'payram_fx_EUR', 1.1, HOUR_IN_SECONDS );
$h = make_order( '20.00', 'payram', 'EUR' );
list( , $ref_h ) = pay( $h );
check( 'H0 rate and USD amount saved', '1.1' === (string) reload( $h )->get_meta( '_payram_fx_rate' ) && '22' === (string) reload( $h )->get_meta( '_payram_amount_usd' ), (string) reload( $h )->get_meta( '_payram_amount_usd' ) );
set_transient( 'payram_fx_EUR', 0.5, HOUR_IN_SECONDS ); // a later rate must not matter
ctl( '/_control/set', array( 'reference_id' => $ref_h, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '22.000000' ) );
list( $code ) = webhook( array( 'invoice_id' => (string) $h, 'reference_id' => $ref_h, 'status' => 'FILLED' ) );
check( 'H1 EUR 20.00 at 1.1, 22.00 USD paid -> paid', 200 === $code && reload( $h )->is_paid() );
set_transient( 'payram_fx_EUR', 1.1, HOUR_IN_SECONDS );
$h2 = make_order( '20.00', 'payram', 'EUR' );
list( , $ref_h2 ) = pay( $h2 );
raise_total( $h2, '5.00' );
ctl( '/_control/set', array( 'reference_id' => $ref_h2, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '22' ) );
webhook( array( 'invoice_id' => (string) $h2, 'reference_id' => $ref_h2, 'status' => 'FILLED' ) );
check( 'H2 EUR total raised to 25.00 (27.50 USD), 22.00 paid -> on-hold', 'on-hold' === reload( $h2 )->get_status() && false !== strpos( notes_text( $h2 ), 'with 22.00 USD paid, but the order total is 27.50 USD' ), notes_text( $h2 ) );
$h3 = make_order( '20.00', 'payram', 'EUR' );
$ref_h3 = 'legacy-' . $h3;
ctl( '/_control/set', array( 'reference_id' => $ref_h3, 'invoiceID' => (string) $h3, 'customerID' => (string) $h3, 'paymentState' => 'FILLED', 'filledAmountInUSD' => '22' ) );
webhook( array( 'invoice_id' => (string) $h3, 'reference_id' => $ref_h3, 'status' => 'FILLED' ) );
check( 'H3 EUR order with no saved rate -> on-hold, says why', 'on-hold' === reload( $h3 )->get_status() && false !== strpos( notes_text( $h3 ), 'could not be converted to USD' ) );

// -------------------------------------- I: create response without reference
echo "[I] PayRam create response without reference_id\n";
ctl( '/_control/create_mode', array( 'omit_reference' => true ) );
$i = make_order( '10.00' );
list( $res, $ref_i ) = pay( $i );
ctl( '/_control/create_mode', array( 'omit_reference' => false ) );
wc_clear_notices();
check( 'I1 process_payment fails and saves nothing', 'failure' === $res['result'] && '' === $ref_i, wp_json_encode( $res ) );

// ---------------------------------------------------- partial + cancel paths
echo "[J] partial and cancelled for this order's reference (unchanged behaviour)\n";
$j = make_order( '10.00' );
list( , $ref_j ) = pay( $j );
ctl( '/_control/set', array( 'reference_id' => $ref_j, 'paymentState' => 'PARTIALLY_FILLED', 'filledAmountInUSD' => '4' ) );
webhook( array( 'invoice_id' => (string) $j, 'reference_id' => $ref_j, 'status' => 'PARTIALLY_FILLED' ) );
check( 'J1 PARTIALLY_FILLED -> on-hold', 'on-hold' === reload( $j )->get_status() );
ctl( '/_control/set', array( 'reference_id' => $ref_j, 'paymentState' => 'CANCELLED' ) );
webhook( array( 'invoice_id' => (string) $j, 'reference_id' => $ref_j, 'status' => 'CANCELLED' ) );
check( 'J2 CANCELLED for this order\'s reference -> cancelled', 'cancelled' === reload( $j )->get_status() );

echo "\nRESULT HPOS $hpos: " . $GLOBALS['pass'] . ' passed, ' . count( $GLOBALS['fail'] ) . " failed\n";
foreach ( $GLOBALS['fail'] as $n ) {
	echo "  failed: $n\n";
}
if ( $GLOBALS['fail'] ) {
	WP_CLI::halt( 1 );
}
