<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assets URL.
 *
 * @return string
 */
function wc_easypag_assets_url() {
	return plugin_dir_url( dirname( __FILE__ ) ) . 'assets/';
}
	
/**
 * Get boleto URL from order key.
 *
 * @param  string $code
 *
 * @return string
 */
function wc_easypag_get_boleto_url( $code ) {
	return WC_EasyPag::get_boleto_url( $code );
}

/**
 * Get boleto URL from order key.
 *
 * @param  int $order_id
 *
 * @return string
 */
function wc_easypag_get_boleto_url_by_order_id( $order_id ) {
	$order_id = intval( $order_id );
	$order    = new WC_Order( $order_id );

	if ( isset( $order->order_key ) ) {
		return wc_easypag_get_boleto_url( $order->order_key );
	}

	return '';
}
