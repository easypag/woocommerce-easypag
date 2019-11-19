<?php
/**
 * Plugin Name: EasyPag WooCommerce
 * Description: WooCommerce Boleto is a brazilian payment gateway for WooCommerce
 * Author: Carlos Alberto
 * Author URI: https://ccwebmaster.com
 * Version: 1.0.0
 * License: GPLv2 or later
 * Text Domain: easy
 * Domain Path: /languages/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_EasyPag' ) ) :

/**
 * WooCommerce Easy main class.
 */
class WC_EasyPag {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '1.5.2';

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin actions.
	 */
	private function __construct() {
		// Load plugin text domain
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// Checks with WooCommerce is installed.
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			// Public includes.
			$this->includes();

			// Admin includes.
			if ( is_admin() ) {
				$this->admin_includes();
			}

			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
			add_action( 'init', array( __CLASS__, 'add_boleto_endpoint' ) );
			add_action( 'template_include', array( $this, 'boleto_template' ), 9999 );
			add_action( 'woocommerce_view_order', array( $this, 'pending_payment_message' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
			add_action( 'init', array($this, 'register_easypag_order_status') );
			add_filter( 'wc_order_statuses', array($this, 'add_aeasypag_to_order_statuses') );

			add_action( 'rest_api_init', function () {
				register_rest_route( 'easypag/v1', '/orders/', array(
					'methods' => 'POST',
					'callback' => array($this, 'easypag_api_callback'),
				) );
			});
			
		} else {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
		}
	}

	public function register_easypag_order_status() {
    register_post_status( 'wc-marked', array(
        'label'                     => 'EasyPag - Marcada como Paga',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Marcada como paga <span class="count">(%s)</span>', 'Marcada como paga <span class="count">(%s)</span>' )
		) );
		
    register_post_status( 'wc-expired', array(
        'label'                     => 'EasyPag - Expirada',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Expeirada <span class="count">(%s)</span>', 'Expiradas <span class="count">(%s)</span>' )
		) );
		
    register_post_status( 'wc-late', array(
        'label'                     => 'EasyPag - Atrasada',
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Atrasada <span class="count">(%s)</span>', 'Atrasadas <span class="count">(%s)</span>' )
    ) );
		
	}

	public function add_aeasypag_to_order_statuses( $order_statuses ) {
    $new_order_statuses = array();
		
		foreach ( $order_statuses as $key => $status ) {
			
			$new_order_statuses[ $key ] = $status;

			if ( 'wc-completed' === $key ) { 
				$new_order_statuses['wc-completed'] = 'EasyPag - Cobrança Paga'; 
				$new_order_statuses['wc-marked'] 		= 'EasyPag - Marcada como paga'; 
				$new_order_statuses['wc-late'] 			= 'EasyPag - Cobrança Atrasada'; 
			}

			if ( 'wc-on-hold' === $key ) 		{ $new_order_statuses['wc-on-hold'] = 'EasyPag - Aguardando pagamento'; }
			if ( 'wc-cancelled' === $key ) 	{ 
				$new_order_statuses['wc-cancelled'] = 'EasyPag - Cobrança Cancelada'; 
				$new_order_statuses['wc-expired'] 	= 'EasyPag - Cobrança Expirada'; 
			}
		}

    return $new_order_statuses;
	}
	
  public function easypag_api_callback(WP_REST_Request $request) {
		$parameters = $request->get_json_params();

		$order_id       = (int) $parameters['resource']['reference'];
		$order          = new WC_Order($order_id);
		$order_status   = sanitize_text_field($order->get_status());
		$payment_status = $this->easypage_order_status((string) sanitize_text_field($parameters['resource']['payment']['status']));
		
		$payment_note   = (string) sanitize_text_field($parameters['resource']['payment']['description']);

		if($order_status != $payment_status) {
			if($order_status === 'on-hold') {
				return rest_ensure_response(array('order_update' => $order->update_status("wc-{$payment_status}", $payment_note)));
			} elseif ($order_status == 'late' && $payment_status != 'on-hold') {
				return rest_ensure_response(array('order_update' => $order->update_status("wc-{$payment_status}", $payment_note)));
			} elseif ($order_status == 'marked' && $payment_status == 'completed') {
				return rest_ensure_response(array('order_update' => $order->update_status("wc-{$payment_status}", $payment_note)));
			}
			return rest_ensure_response(array('order_update' => false));
		}
		return rest_ensure_response(array('order_update' => false));

		return rest_ensure_response(array('order_update' => $order->update_status("wc-{$payment_status}", $payment_note)));
	}
	
	public function easypage_order_status($status) {
		switch($status) {
			case '1':
				return (string) 'on-hold';
			case '2':
				return (string) 'completed';
			case '3':
				return (string) 'marked';
			case '4':
				return (string) 'late';
			case '5':
				return (string) 'cancelled';
			case '6':
				return (string) 'expired';
		}
	}

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Get plugin path.
	 *
	 * @return string
	 */
	public static function get_plugin_path() {
		return plugin_dir_path( __FILE__ );
	}

	/**
	 * Load the plugin text domain for translation.
	 */
	public function load_plugin_textdomain() {
		$locale = apply_filters( 'plugin_locale', get_locale(), 'easypag' );

		load_textdomain( 'easypag', trailingslashit( WP_LANG_DIR ) . 'woocommerce-boleto/woocommerce-boleto-' . $locale . '.mo' );
		load_plugin_textdomain( 'easypag', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Includes.
	 */
	private function includes() {
		include_once 'includes/wc-easypag-actions.php';
		include_once 'includes/wc-easypag-functions.php';
		include_once 'includes/class-wc-easypag-gateway.php';
	}

	/**
	 * Includes.
	 */
	private function admin_includes() {
		require_once 'includes/class-wc-easypag-admin.php';
	}

	/**
	 * Add the gateway to WooCommerce.
	 *
	 * @param  array $methods WooCommerce payment methods.
	 *
	 * @return array          Payment methods with Boleto.
	 */
	public function add_gateway( $methods ) {
		$methods[] = 'WC_EasyPag_Gateway';

		return $methods;
	}

	/**
	 * Created the boleto endpoint.
	 */
	public static function add_boleto_endpoint() {
		add_rewrite_endpoint( 'boleto', EP_PERMALINK | EP_ROOT );
	}

	/**
	 * Plugin activate method.
	 */
	public static function activate() {
		self::add_boleto_endpoint();

		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivate method.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Add custom template page.
	 *
	 * @param  string $template
	 *
	 * @return string
	 */
	public function boleto_template( $template ) {
		global $wp_query;

		if ( isset( $wp_query->query_vars['boleto'] ) ) {
			return self::get_plugin_path() . 'includes/views/html-boleto.php';
		}

		return $template;
	}

	/**
	 * Gets the boleto URL.
	 *
	 * @param  string $code Boleto code.
	 *
	 * @return string       Boleto URL.
	 */
	public static function get_boleto_url( $code ) {

		$home = home_url( '/' );

		if ( get_option( 'permalink_structure' ) ) {
			$url = trailingslashit( $home ) . 'boleto/' . $code;
		} else {
			$url = add_query_arg( array( 'boleto' => $code ), $home );
		}

		return apply_filters( 'woocommerce_boleto_url', $url, $code, $home );
	}

	/**
	 * Display pending payment message in order details.
	 *
	 * @param  int $order_id Order id.
	 *
	 * @return string        Message HTML.
	 */
	public function pending_payment_message( $order_id ) {
		$order = new WC_Order( $order_id );

		if ( 'on-hold' === $order->status && 'boleto' == $order->payment_method ) {
			$html = '<div class="woocommerce-info">';
			$html .= sprintf( '<a class="button" href="%s" target="_blank" style="display: block !important; visibility: visible !important;">%s</a>', esc_url( wc_easypag_get_boleto_url( $order->order_key ) ), __( 'Pay the Ticket &rarr;', 'easypag' ) );

			$message = sprintf( __( '%sAttention!%s Not registered the payment the docket for this product yet.', 'easypag' ), '<strong>', '</strong>' ) . '<br />';
			$message .= __( 'Please click the following button and pay the Ticket in your Internet Banking.', 'easypag' ) . '<br />';
			$message .= __( 'If you prefer, print and pay at any bank branch or lottery retailer.', 'easypag' ) . '<br />';
			$message .= __( 'Ignore this message if the payment has already been made​​.', 'easypag' ) . '<br />';

			$html .= apply_filters( 'wcboleto_pending_payment_message', $message, $order );

			$html .= '</div>';

			echo $html;
		}
	}

	/**
	 * Action links.
	 *
	 * @param  array $links
	 *
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array();

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '2.1', '>=' ) ) {
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_boleto_gateway' );
		} else {
			$settings_url = admin_url( 'admin.php?page=woocommerce_settings&tab=payment_gateways&section=WC_Boleto_Gateway' );
		}

		$plugin_links[] = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'easypag' ) . '</a>';

		return array_merge( $plugin_links, $links );
	}

	/**
	 * WooCommerce fallback notice.
	 *
	 * @return string
	 */
	public function woocommerce_missing_notice() {
		include_once 'includes/views/html-notice-woocommerce-missing.php';
	}
}

/**
 * Plugin activation and deactivation methods.
 */
register_activation_hook( __FILE__, array( 'WC_EasyPag', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WC_EasyPag', 'deactivate' ) );

/**
 * Initialize the plugin.
 */
add_action( 'plugins_loaded', array( 'WC_EasyPag', 'get_instance' ) );

endif;
