=== PayRam for WooCommerce ===
Contributors: payram
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.2

Accept crypto payments in WooCommerce via a self-hosted PayRam instance.

== Description ==
This plugin is a BRIDGE to an existing PayRam instance. It does NOT run PayRam.
At checkout it creates a payment in PayRam, redirects the buyer to PayRam's
hosted crypto checkout, and completes the order when PayRam reports payment.

== Setup ==
1. Have a running PayRam instance and a project API key.
2. WooCommerce > Settings > Payments > PayRam (Crypto): enable it, set the
   PayRam URL and API key.
3. In your PayRam project, register this webhook URL:
     {your-site}/wp-json/payram/v1/webhook
4. (v1) Set your WooCommerce store currency to USD — amounts are sent as
   amountInUSD. Multi-currency FX is a later enhancement.

== API contract used ==
- Create:  POST {payram_url}/api/v1/payment   header API-Key
           body { customerEmail, customerID, amountInUSD, invoiceID }
           -> { url, reference_id, host }
- Verify:  GET  {payram_url}/api/v1/payment/reference/{reference_id}
           -> { paymentState, invoiceID, customerID, amountInUSD,
                filledAmountInUSD, ... }
- Webhook: PayRam POSTs { invoice_id, reference_id, status, ... } with an
           X-Payram-Signature header (sha256= HMAC-SHA256 of the raw body,
           keyed by the project API key).
           status: OPEN|PARTIALLY_FILLED|FILLED|OVER_FILLED|CANCELLED
           Every webhook is refused (503) until the API key is set. An order
           changes only for the reference its own checkout was given, and is
           paid only when PayRam's filledAmountInUSD covers the order total;
           otherwise it goes on hold with a note.

== Changelog ==
= 0.3.2 =
* Security: a payment update changes only the order it belongs to. The
  plugin checks that the webhook's PayRam reference is the one this order's
  checkout was given, and ignores any other with an order note. Another
  store on the same PayRam project, or an older checkout of the same order,
  can no longer complete or cancel it.
* Security: an order is marked paid only when the amount PayRam confirms as
  paid covers the order total. If the total was raised after checkout, or
  PayRam does not report the amount, the order goes on hold with a note.
* Security: with no API key set, every webhook is refused and the log says
  why (before, anyone could sign a webhook with the empty key).
* A redelivered webhook no longer reopens an order the merchant cancelled
  after it was paid.
* Refusals and failures are always written to WooCommerce > Status > Logs
  (source "payram"), even with debug logging off.

= 0.3.1 =
* Security: a webhook can no longer complete an order on its own. The
  plugin re-reads the payment from PayRam (paymentState) before acting, and
  answers 503 when PayRam can't be reached, so PayRam retries instead of the
  order trusting the webhook body.
* Tested with WordPress 7.1 and WooCommerce 11.2.

= 0.3.0 =
* New payment method title, description and icon.

= 0.2.0 =
* Webhooks must carry X-Payram-Signature (HMAC-SHA256 of the body, keyed by
  the project API key); the API-Key header is no longer accepted.

= 0.1.0 =
* Initial scaffold: gateway settings, create-payment + redirect, webhook receiver.
