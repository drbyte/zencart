<?php
if (defined('MODULE_PAYMENT_PAYPALWPP_MERCHANTID') && MODULE_PAYMENT_PAYPALWPP_MERCHANTID != '')
{
?>
<script>
  window.paypalCheckoutReady = function () {
  paypal.checkout.setup('<?php echo MODULE_PAYMENT_PAYPALWPP_MERCHANTID; ?>', {
<?php echo (MODULE_PAYMENT_PAYPALWPP_SERVER == 'live' ? '' : "    environment: 'sandbox'," . "\n"); ?>
    container: 'shoppingCartForm',
    button: 'PPECbutton'
  });
};
</script>
<script src="//www.paypalobjects.com/api/checkout.js" async></script>
<?php
}
?>
