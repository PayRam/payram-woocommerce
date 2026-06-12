<?php
/**
 * PayRam payment method integration for the WooCommerce Cart/Checkout BLOCKS.
 * Makes the gateway appear on the modern block-based checkout. The actual
 * payment still runs through WC_Gateway_PayRam::process_payment() server-side.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_PayRam_Blocks extends AbstractPaymentMethodType {

	/** @var string Must match the classic gateway id. */
	protected $name = 'payram';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_payram_settings', array() );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		$handle = 'wc-payram-blocks';
		wp_register_script(
			$handle,
			plugins_url( 'assets/js/blocks.js', PAYRAM_WC_PLUGIN_FILE ),
			array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			PAYRAM_WC_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'payram-woocommerce' );
		}
		return array( $handle );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : 'PayRam',
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products' ),
		);
	}
}
