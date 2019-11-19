<?php

function easypag_settle_order()
{

  $order_id = (int) $_POST['order_id'];
  $order    = new WC_Order($order_id);
  $payload  = json_decode(get_post_meta($order->id, 'wc_easypag_boleto_data')[0], true);

  $x = new WP_Ajax_Response();
  $gateway = new WC_EasyPag_Gateway();

  if (!empty($payload['id'])) {
    
    $response = 
      wp_remote_post(
        $gateway->easypag_get_vars()['api'] . "/invoices/{$payload['id']}/settle",
        array(
          'headers' => array(
            'Authorization' => 'Basic ' . $gateway->easypag_get_vars()['token']
          )
        )
    );

    if ( (int) $response['response']['code'] === (int) 200 ) {
      wp_die(1);
    } elseif ( (int) $response['response']['code'] === (int) 422 ) {
      wp_die(0);
    } else {
      wp_die(-1);
    }
  }
}
add_action('wp_ajax_easypag_settle_order', 'easypag_settle_order');

function easypag_calcel_order()
{

  $order_id = (int) $_POST['order_id'];
  $order    = new WC_Order($order_id);
  $gateway  = new WC_EasyPag_Gateway();
  $payload  = json_decode(get_post_meta($order->id, 'wc_easypag_boleto_data')[0], true);

  if (!empty($payload['id'])) {
    
    $response = 
      wp_remote_post(
        $gateway->easypag_get_vars()['api'] . "/invoices/{$payload['id']}/cancel",
        array(
          'headers' => array(
            'Authorization' => 'Basic ' . $gateway->easypag_get_vars()['token']
          )
        )
    );

    if ( (int) $response['response']['code'] === (int) 200 ) {
      wp_die(1);
    } elseif ( (int) $response['response']['code'] === (int) 422 ) {
      wp_die(0);
    } else {
      wp_die(-1);
    }
    
  }
}
add_action('wp_ajax_easypag_calcel_order', 'easypag_calcel_order');

function easypag_update_order()
{
  $order_id       = (int) $_POST['order_id'];
  $order          = new WC_Order($order_id);
  $payload        = json_decode(get_post_meta($order->id, 'wc_easypag_boleto_data')[0], true);
  $gateway        = new WC_EasyPag_Gateway();
 
  $order_status   = easypage_order_status($payload['payment']['status']);

  $new_status     = (string) sanitize_text_field($_POST['status']);
  
  if (!empty($payload['id'])) {
  
   
    if ($order_status != $new_status) {
      if ($new_status === 'wc-cancelled' && $order_status !== 'wc-marked') {

        $response = 
        wp_remote_post(
          $gateway->easypag_get_vars()['api'] . "/invoices/{$payload['id']}/cancel",
          array(
            'headers' => array(
              'Authorization' => 'Basic ' . $gateway->easypag_get_vars()['token']
            )
          )
        );

        if ( (int) $response['response']['code'] === (int) 200 ) {
          wp_die(1);
        } elseif ( (int) $response['response']['code'] === (int) 422 ) {
          wp_die(0);
        } else {
          wp_die(-1);
        }

      } elseif ($new_status === 'wc-marked' && $order_status !== 'wc-cancelled') {

        $response = 
        wp_remote_post(
          $gateway->easypag_get_vars()['api'] . "/invoices/{$payload['id']}/settle",
          array(
            'headers' => array(
              'Authorization' => 'Basic ' . $gateway->easypag_get_vars()['token']
            )
          )
        );

        if ( (int) $response['response']['code'] === (int) 200 ) {
          wp_die(1);
        } elseif ( (int) $response['response']['code'] === (int) 422 ) {
          wp_die(0);
        } else {
          wp_die(-1);
        }
      }
    }
  }
}
add_action('wp_ajax_easypag_update_order', 'easypag_update_order');

function easypage_order_status($status) {
  switch($status) {
    case '1':
      return (string) 'on-hold';
    case '2':
      return (string) 'wc-completed';
    case '3':
      return (string) 'wc-marked';
    case '4':
      return (string) 'wc-late';
    case '5':
      return (string) 'wc-cancelled';
    case '6':
      return (string) 'wc-expired';
  }
}