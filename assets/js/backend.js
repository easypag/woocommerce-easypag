jQuery( document ).ready( function( $ ) {

  var order_status = $( '#order_status' ),
      order_value  = order_status.val()

  if(order_status.length >= 1) {

    if (
      order_status.val() === 'wc-completed' ||
      order_status.val() === 'wc-cancelled' ||
      order_status.val() === 'wc-marked'
    ) { 
      order_status.prop( 'disabled', false);
    }
  
    $( '#order_status' ).change( function () {
      order_value = $(this).val()
    })

    $('button[type="submit"]').on('click', function (e) {
      
      $('#message').remove();

      var data = {
        action    : 'easypag_update_order',
        order_id  : $('form').find($('#post_ID')).val(),
        status    : order_value
      };

      $.post(easypag_globals.ajaxurl, data, function(r) {
      
        if( r == 1 ) {
          $('#post').submit();
        } else {
          $('#post').prepend('<div id="message" class="error inline"><p><strong>Cobrança Paga, Marcada como paga e Cancelada não podem ser atualizadas.</strong></p></div>');
          return false;
        }

      });

      return false;
    })
  }

});
