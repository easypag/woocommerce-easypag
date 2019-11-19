<?php
if (!defined('ABSPATH')) {
  exit;
}

if (!class_exists('WC_EasyPag_Gateway')) 
{
  class WC_EasyPag_Gateway extends WC_Payment_Gateway
	{
		public function __construct()
		{
			$this->id                   = 'easypag';
			$this->icon                 = esc_url('https://sandbox.easypagamentos.com.br/app/assets/img/logos/logo-blue.png');
			$this->has_fields           = __('EasyPag', 'easypag');
			$this->method_description   = __('Habilitar pagamentos via Easypag.', 'easypag');

			$this->init_form_fields();
			$this->init_settings();

			$this->title        = $this->get_option('title');
			$this->description  = $this->get_option('description');
			$this->instructions  = $this->get_option('instructions');
			$this->boleto_time  = $this->get_option('boleto_time');
			$this->api          = ('yes' == $this->get_option('sandbox') ? 'https://sandbox.easypag.com.br/api/v1' : 'https://easypag.com.br/api/v1');
			
			if(!empty($this->get_option('clientID')) && !empty($this->get_option('clientSecret'))) {
				$this->token = base64_encode($this->get_option('clientID') . ':' . $this->get_option('clientSecret'));
			} else {
				$this->token = null;
			}

			add_action('woocommerce_thankyou_' . $this->id , array($this, 'thankyou_page'));
			add_action('woocommerce_email_after_order_table', array($this, 'email_instructions'), 10, 2);
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_order_status_cancelled', array($this, 'send_mail'));
		}

		public function send_mail($order_id) {
			$order = new WC_Order($order_id);
			
			$msg =  'Olá, ' . $order->billing_first_name . '.<br />';
			$msg .= 'Informamos que o seu pedido (<a href="' . $order->get_view_order_url() . '">' . $order->get_order_number() . '</a>) foi cancelado.<br />';
			$msg .= 'Obrigado.';
			
			$mailer = WC()->mailer();
			$mailer->send($order->billing_email, get_bloginfo('name'), $mailer->wrap_message('Pedido Cancelado',  $msg), '', '');
		}

		public function using_supported_currency()
		{
			return ('BRL' === get_woocommerce_currency());
		}

		public function is_available()
		{
			$available = ('yes' == $this->get_option('enabled') && $this->using_supported_currency());

			return $available;
		}

		public function admin_options()
		{
			include 'views/html-admin-page.php';
		}

		public function init_form_fields()
		{

			$this->form_fields = array(
				'enabled' => array(
					'title'   => __('Habilitar/Desabilitar', 'easypag'),
					'type'    => 'checkbox',
					'label'   => __('Habilitar EasyPag Geteway', 'easypag'),
					'default' => 'yes'
				),
				'title' => array(
					'title'       => __('Título', 'easypag'),
					'type'        => 'text',
					'description' => __('Controla o título que o usuário vê durante a finalização da compra.', 'easypag'),
					'desc_tip'    => true,
					'default'     => __('Easypag', 'easypag')
				),
				'description' => array(
					'title'       => __('Descrição', 'easypag'),
					'type'        => 'textarea',
					'description' => __('Controla a descrição que o usuário vê durante a finalização da compra.', 'easypag'),
					'desc_tip'    => true,
					'default'     => __('Pagar com EasyPag', 'easypag')
				),
				'instructions' => array(
					'title'       => __('Intrução de pagamento.', 'easypag'),
					'type'        => 'textarea',
					'description' => __('Descrição das instruções de como o boleto deve ser pago.', 'easypag'),
					'desc_tip'    => true,
					'default'     => __('', 'easypag')
				),
				'boleto_details' => array(
					'title' => __('Ticket Details', 'easypag'),
					'type'  => 'title'
				),
				'boleto_time' => array(
					'title'       => __('Prazo de Validade', 'easypag'),
					'type'        => 'text',
					'description' => __('Números de dias até o vencimento do boleto.', 'easypag'),
					'desc_tip'    => true,
					'default'     => 5
				),
				'sandbox' => array(
					'title'   => __('Habilitar Sandbox', 'easypag'),
					'type'    => 'checkbox',
					'label'   => __('Habilitar Sandbox EasyPag', 'easypag'),
					'default' => 'yes'
				),
				'clientID'      => array(
					'title'     => 'clientId',
					'type'      => 'text',
					'description' => '',
					'default'   => '',
					'desc_tip'  => false
				),
				'clientSecret'   => array(
					'title'     => 'clientSecret',
					'type'      => 'password',
					'description' => '',
					'default'   => '',
					'desc_tip'  => false
				)
			);
		}

		public function process_payment($order_id)
		{
			$order = new WC_Order($order_id);

			$is_generated = $this->generate_boleto_data($order);

			if($is_generated) {
				$order->update_status('on-hold', __('Aguardando pagamento do boleto.','easypag'));

				$order->reduce_order_stock();

				if (defined('WC_VERSION') && version_compare(WC_VERSION, '2.1', '>=')) {
					WC()->cart->empty_cart();

					$url = $order->get_checkout_order_received_url();
				} else {
					global $woocommerce;

					$woocommerce->cart->empty_cart();

					$url = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))));
				}

				return array(
					'result'    => 'success',
					'redirect'  => $url
				);
			}
		}

		public function thankyou_page( $order_id )
		{
			$order    = new WC_Order((int) $order_id);
			$payload  = json_decode(get_post_meta($order->id, 'wc_easypag_boleto_data')[0],true);

			$html = '<div class="woocommerce-message">';
			$html .= sprintf('<a class="button" href="%s" target="_blank" style="display: block !important; visibility: visible !important;">%s</a>', esc_url($payload['invoiceUrl']), __('Baixar Boleto &rarr;', 'easypag'));

			$message = sprintf(__('%sInstruções de Pagamento: %s', 'easypag'), '<strong>', '</strong>') . '<br />';
			$message .= $this->instructions. '<br />';

			$html .= apply_filters('wcboleto_thankyou_page_message', $message);

			$html .= '<strong style="display: block; margin-top: 15px; font-size: 0.8em">' . sprintf(__('Validade do boleto: %s.', 'easypag'), date('d/m/Y', time() + (absint($this->boleto_time) * 86400))) . '</strong>';

			$html .= '</div>';

			echo $html;
		}

		public function generate_boleto_data($order)
		{
			$invoices = array();

			$invoices['dueDate'] = date('Y-m-d', time() + (absint($this->boleto_time) * 86400));

			$items = array();
			foreach ($order->get_items() as $item_id => $item) {
				$items[] = array(
					"description" => (string) sanitize_text_field(($item['name'])),
					"quantity"    => (int) esc_attr($item['quantity']),
					"price"       => (int) esc_attr($item['line_total'] * 100)
				);
			}
			$invoices['items'] = $items;

			$invoices['customer'] = array(
				"name" => (string) sanitize_text_field($order->get_formatted_billing_full_name()),
				"email" => sanitize_email($order->get_billing_email()),
				"phoneNumber" => (string) sanitize_text_field('55' . preg_replace('/[^0-9]/', '', $order->get_billing_phone())),
				"docNumber" => !empty($order->get_meta('_billing_cpf')) ? preg_replace('/[^0-9]/', '', $order->get_meta('_billing_cpf')) : preg_replace('/[^0-9]/', '', $order->get_meta('_billing_cnpj')),
				"address" => array(
					"cep" => (string) sanitize_text_field(preg_replace('/[^0-9]/', '', $order->get_billing_postcode())),
					"uf" => (string) sanitize_text_field($order->get_billing_state()),
					"city" => (string) sanitize_text_field($order->get_billing_city()),
					"area" => (string) sanitize_text_field($order->get_billing_address_1()),
					"addressLine1" => (string) sanitize_text_field($order->get_meta('_billing_neighborhood')),
					"addressLine2" => (string) sanitize_text_field($order->get_billing_address_2()),
					"streetNumber" => (string) sanitize_text_field($order->get_meta('_billing_number'))
				)
			);

			$invoices['notifications'] = array(
				"cc" => array(
					"emails" => [sanitize_email($order->get_billing_email())],
				),
				"sendOnCreate" => (bool) true,
				"reminders" => [
					"before" => [
						"send" => (bool) true,
						"days" => (int) 1,
						"time" => (string) "08:00:00"
					],
					"after" => [
						"send" => (bool) true,
						"days" => (int) 2,
						"time" => (string) "08:00:00",
						"recurrent" => (bool) false
					],
					"expiration" => [
						"send" => (bool) true,
						"time" => (string) "08:00:00"
					],
					"overdue" => [
						"send" => (bool) true
					]
				],
				"types" => [
					"email" => (bool) true,
					"sms" => (bool) false,
					"whatsapp" => (bool) true
				]
			);

			$invoices['instructionsMsg'] = $this->instructions;
			$invoicesNotes = 'Pedido: ';
			foreach ($order->get_items() as $item_id => $item) {
				$invoicesNotes.= sanitize_text_field($item['name'] . ' (' . $item['quantity'] . ')' .'; ');
			}
			$invoices['notes'] = sanitize_text_field($invoicesNotes);

			$invoices['reference'] = (string) sanitize_text_field("$order->id");

			if(is_null($this->token) || empty($this->token)) {
				wc_add_notice( 'Token Inválido.', 'error' );
			} else {
				$response = wp_remote_post(
					"{$this->api}/invoices",
					array(
						'body' => json_encode($invoices),
						'headers' => array(
							'Authorization' => 'Basic ' . $this->token,
							'Content-type' => 'application/json'
						)
					)
				);

				if ((int) $response['response']['code'] === (int) 401) {
					wc_add_notice(  'Não foi possivel concluir seu pedido, Gateway não autorizado.', 'error' );
					return false;
				} elseif ((int) $response['response']['code'] === (int) 201) {
					update_post_meta($order->id, 'wc_easypag_boleto_data', $response['body']);
					return true;
				} else {
					wc_add_notice( 'Erro desconhecido, verifique se os campos estão corretos.', 'error' );
					return false;
				}
			}
		}

		function email_instructions($order, $sent_to_admin)
		{
			if ($sent_to_admin || 'on-hold' !== $order->status || 'boleto' !== $order->payment_method) {
				return;
			}

			$html = '<h2>' . __('Payment', 'easypag') . '</h2>';

			$html .= '<p class="order_details">';

			$message = sprintf(__('%Instruções de pagamento!%s You will not get the ticket by Correios.', 'easypag'), '<strong>', '</strong>') . '<br />';
			$message .= __('Please click the following button and pay the Ticket in your Internet Banking.', 'easypag') . '<br />';
			$message .= __('If you prefer, print and pay at any bank branch or lottery retailer.', 'easypag') . '<br />';

			$html .= apply_filters('wcboleto_email_instructions', $message);

			$html .= '<br />' . sprintf('<a class="button" href="%s" target="_blank">%s</a>', esc_url(wc_easypag_get_boleto_url($order->order_key)), __('Pay the Ticket &rarr;', 'easypag')) . '<br />';

			$html .= '<strong style="font-size: 0.8em">' . sprintf(__('Validity of the Ticket: %s.', 'easypag'), date('d/m/Y', time() + (absint($this->boleto_time) * 86400))) . '</strong>';

			$html .= '</p>';

			echo $html;
		}

		public function easypag_get_vars() {
			return array(
				'token' => esc_attr($this->token),
				'api'   => esc_attr($this->api)
			);
		}
	}

}
