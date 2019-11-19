<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if(!class_exists('WC_EasyPag_Admin'))
{

	class WC_EasyPag_Admin {

		public function __construct() {
			add_action('add_meta_boxes', array($this, 'register_metabox'));
			add_action('save_post', array($this, 'save'));
			add_action('admin_init', array($this, 'update'), 5);
			add_action('admin_enqueue_scripts', array($this, 'scripts'));
		}

		public function register_metabox() {
			add_meta_box(
				'woo-easy',
				__('EasyPag', 'easypag'),
				array($this, 'metabox_content'),
				'shop_order',
				'side',
				'default'
			);
		}
		
		public function metabox_content( $post ) {
			$order = new WC_Order( $post->ID );

			// Use nonce for verification.
			wp_nonce_field( basename( __FILE__ ), 'WC_EasyPag_metabox_nonce' );
			
			if ( 'boleto' == $order->payment_method ) {
				$boleto_data = get_post_meta( $post->ID, 'wc_easypag_boleto_data', true );

				if ( ! isset( $boleto_data['data_vencimento'] ) ) {
					$settings                   = get_option( 'woocommerce_boleto_settings', array() );
					$boleto_time                = isset( $settings['boleto_time'] ) ? absint( $settings['boleto_time'] ) : 5;
					$data                       = array();
					$data['nosso_numero']       = apply_filters( 'wcboleto_our_number', $order->id );
					$data['numero_documento']   = apply_filters( 'wcboleto_document_number', $order->id );
					$data['data_vencimento']    = sanitize_text_field(date( 'd/m/Y', time() + ( $boleto_time * 86400 ) ));
					$data['data_documento']     = sanitize_text_field(date( 'd/m/Y' ));
					$data['data_processamento'] = sanitize_text_field(date( 'd/m/Y' ));

					update_post_meta( $post->ID, 'wc_easypag_boleto_data', $data );

					$boleto_data['data_vencimento'] = sanitize_text_field($data['data_vencimento']);
				}
				
				$html = '<p><strong>' . __( 'Expiration date:', 'easypag' ) . '</strong> ' . $boleto_data['data_vencimento'] . '</p>';
				$html .= '<p><strong>' . __( 'URL:', 'easypag' ) . '</strong> <a target="_blank" href="' . esc_url( wc_easypag_get_boleto_url( $order->order_key ) ) . '">' . __( 'View boleto', 'easypag' ) . '</a></p>';

				$html .= '<p style="border-top: 1px solid #ccc;"></p>';

				$html .= '<label for="wcboleto_expiration_date">' . __( 'Set new expiration data:', 'easypag' ) . '</label><br />';
				$html .= '<input type="text" id="wcboleto_expiration_date" name="wcboleto_expiration_date" style="width: 100%;" />';
				$html .= '<span class="description">' . __( 'Configuring a new expiration date the boleto is resent to the client.', 'easypag' ) . '</span>';

			} else {
				$html = '<p>' . __( 'This purchase was not paid with Ticket.', 'easypag' ) . '</p>';
				$html .= '<style>#woocommerce-boleto.postbox {display: none;}</style>';
			}
		}

		public function save( $post_id ) {
			// Verify nonce.
			if ( ! isset( $_POST['WC_EasyPag_metabox_nonce'] ) || ! wp_verify_nonce( $_POST['WC_EasyPag_metabox_nonce'], basename( __FILE__ ) ) ) {
				return $post_id;
			}

			// Verify if this is an auto save routine.
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return $post_id;
			}

			// Check permissions.
			if ( 'shop_order' == $_POST['post_type'] && ! current_user_can( 'edit_page', $post_id ) ) {
				return $post_id;
			}

			if ( isset( $_POST['wcboleto_expiration_date'] ) && ! empty( $_POST['wcboleto_expiration_date'] ) ) {
				// Gets ticket data.
				$boleto_data = get_post_meta( $post_id, 'wc_easypag_boleto_data', true );
				$boleto_data['data_vencimento'] = sanitize_text_field( $_POST['wcboleto_expiration_date'] );

				// Update ticket data.
				update_post_meta( $post_id, 'wc_easypag_boleto_data', $boleto_data );

				// Gets order data.
				$order = new WC_Order( $post_id );

				// Add order note.
				$order->add_order_note( sprintf( __( 'Expiration date updated to: %s', 'easypag' ), $boleto_data['data_vencimento'] ) );

				// Send email notification.
				$this->email_notification( $order, $boleto_data['data_vencimento'] );
			}
		}

		protected function email_notification( $order, $expiration_date ) {
			if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '2.1', '>=' ) ) {
				$mailer = WC()->mailer();
			} else {
				global $woocommerce;
				$mailer = $woocommerce->mailer();
			}

			$subject = sprintf( __( 'New expiration date for the boleto your order %s', 'easypag' ), $order->get_order_number() );

			// Mail headers.
			$headers = array();
			$headers[] = "Content-Type: text/html\r\n";

			// Body message.
			$main_message = '<p>' . sprintf( __( 'The expiration date of your boleto was updated to: %s', 'easypag' ), '<code>' . $expiration_date . '</code>' ) . '</p>';
			$main_message .= '<p>' . sprintf( '<a class="button" href="%s" target="_blank">%s</a>', esc_url( wc_easypag_get_boleto_url( $order->order_key ) ), __( 'Pay the Ticket &rarr;', 'easypag' ) ) . '</p>';

			// Sets message template.
			$message = $mailer->wrap_message( __( 'New expiration date for your boleto', 'easypag' ), $main_message );

			// Send email.
			$mailer->send( $order->billing_email, $subject, $message, $headers, '' );
		}
		
		public function scripts( $hook ) {

			$suffix = defined( 'SCRIPT_DEBUG' ) && !SCRIPT_DEBUG ? '' : '.min';

			if ( in_array( $hook, array( 'woocommerce_page_wc-settings', 'woocommerce_page_woocommerce_settings' ) ) && ( isset( $_GET['section'] ) && 'wc_boleto_gateway' == strtolower( (string) $_GET['section'] ) ) ) {

				wp_enqueue_script( 'wc-boleto-admin', plugins_url( 'assets/js/admin' . $suffix . '.js', plugin_dir_path( __FILE__ ) ), array( 'jquery' ), WC_EasyPag::VERSION, true );
			}
			
			wp_enqueue_script( 'wc-easy-backend', plugins_url( 'assets/js/backend' . $suffix . '.js', plugin_dir_path( __FILE__ ) ), array( 'jquery' ), WC_EasyPag::VERSION, true );
			
			wp_localize_script('wc-easy-backend', 'easypag_globals', array(
				'ajaxurl'        => admin_url('admin-ajax.php'),
				'noPerm'         => __('Sorry, you are not allowed to do that.'),
				'broken'         => __('Something went wrong.')
			));
		}
		
		public function update() {
			$db_version = get_option( 'woocommerce_boleto_db_version' );
			$version    = WC_EasyPag::VERSION;

			// Update to 1.2.2.
			if ( version_compare( $db_version, '1.2.2', '<' ) ) {
				// Delete boleto page.
				$boleto_post = get_page_by_path( 'boleto' );
				if ( $boleto_post ) {
					wp_delete_post( $boleto_post->ID, true );
				}

				// Flush urls.
				WC_EasyPag::activate();
			}

			// Update the db version.
			if ( $db_version != $version ) {
				update_option( 'woocommerce_boleto_db_version', $version );
			}
		}
	}

	new WC_EasyPag_Admin();
}