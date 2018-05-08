<?php

//no direct access
defined('_JEXEC') or die('Restricted access');

$config = JFactory::getConfig();

  // public function redirectPayment($url, $token) {
  //   error_log("Entered into auto submit-form");
  //   // Using Auto-submit form to redirect user with the token
  //   echo "<form id='form' method='post' action='".$url."invoice/payment'>".
  //           "<input type='hidden' autocomplete='off' name='token' value='".$token."'/>".
  //          "</form>".
  //          "<script type='text/javascript'>".
  //               "document.getElementById('form').submit();".
  //          "</script>";
  // }

?>
<style type="text/css">
    #jeeb_form { width: 100%; }
    #jeeb_form td { padding: 5px; }
    #jeeb_form .field_name { font-weight: bold; }
</style>
<form id="j2store_jeeb_form"
      action="<?php echo $vars->baseUrl."payments/invoice"; ?>"
      method="post"
      name="adminForm"
    >
    <input type='hidden' autocomplete='off' name='token' value='<?php echo $vars->token; ?>'/>

    <button type="submit" class="button btn btn-success"><?php echo JText::_('J2STORE_CHECKOUT_CONTINUE'); ?></button>
</form>
