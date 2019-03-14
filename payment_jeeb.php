<?php

// no direct access
defined('_JEXEC') or die('Restricted access');

//include necessary libraries for plugin
require_once JPATH_ADMINISTRATOR . '/components/com_j2store/library/plugins/payment.php';
require_once JPATH_ADMINISTRATOR . '/components/com_j2store/helpers/j2store.php';

/**
 * Class plgJ2StorePayment_jeeb
 */
class plgJ2StorePayment_jeeb extends J2StorePaymentPlugin {

    /**
     * Name of plugin
     * @var string
     */
    var $_element   = 'payment_jeeb';

    /**
     * Log errors
     * @var bool
     */
    var $_isLog     = false;

    /**
     * Status OK
     */
    const STATUS_OK = 'OK';
    /**
     * Status FAIL
     */
    const STATUS_FAIL = 'FAIL';

    /**
     * Default config for plugin
     * @var array
     */
    protected $_default = array(
        'api_version' => 'dev',
        'channel'   => '',
        'ch_lock'   => 0,
        'type'      => 0,
    );

    /**
     * @inheritdoc
     * @param object $subject
     * @param array $config
     */
    public function __construct($subject, $config) {
        parent::__construct($subject, $config);
        $this->loadLanguage( 'plg_j2store_payment_jeeb', JPATH_ADMINISTRATOR );
    }

    /**
     * @inheritdoc
     * Prepare for payment
     *
     * @param array $data
     * @return string
     */
    public function _prePayment( $data ) {
        $vars   = new JObject();
        $info   = $this->getOrderInformation($data);

        $baseCur      = $this->params->get('baseCur');
        $target_cur    = $this->params->get('targetCur');
        $lang         = $this->params->get('lang') =="none"? NULL : $this->params->get('lang') ;
        $price        = number_format($data['orderpayment_amount'], 2, '.', '');
        $baseUri      = "https://core.jeeb.io/api/";
        $signature    = $this->params->get('signature');
        $callBack     = $this->getBaseUrl(). "index.php?option=com_j2store&task=checkout.confirmPayment&orderpayment_type=payment_jeeb";
        $notification = $this->getBaseUrl(). "index.php?option=com_j2store&task=checkout.confirmPayment&orderpayment_type=payment_jeeb&notification=true";
        $order_total  = $price;

        error_log($vars->amount." ".$baseUri." ".$signature." ".$callBack." ".$notification);
        

        if($baseCur=='toman'){
          $baseCur='irr';
          $order_total *= 10;
        }
        
        error_log("Cost = ". $order_total);

        $amount = $this->convertIrrToBtc($baseUri, $order_total, $signature, $baseCur);

        $params = array(
          'orderNo'          => $data['order_id'],
          'value'            => (float) $amount,
          'webhookUrl'       => $notification,
          'callBackUrl'      => $callBack,
          'allowReject'      => $this->params->get('sandbox')==0 ? true : false,
          "coins"            => $target_cur,
          "allowTestNet"     => $this->params->get('sandbox')==1 ? true : false,
          "language"         => $lang
        );
        
        error_log(var_export($params, TRUE));

        $token = $this->createInvoice($baseUri, $amount, $params, $signature);

        //Needed for Jeeb
        $vars->baseUrl      = "https://core.jeeb.io/api/";
        $vars->token           = $token;


        $html = $this->_getLayout('prepayment', $vars);

        return $html;
    }

    /**
     * Get base url depend on option. This method override original base url
     * with https if it is necessary
     *
     * @return mixed|string
     */
    private function getBaseUrl()
    {
        if($this->params->get('ssl', 0) == 0){
            return JURI::base();
        }
        return  str_replace("http://", "https://", JURI::base());
    }

    /**
     * This method is responsible both form processing notification and
     *  generate 'thank you' message. Depend on notification parameter getting from
     *  $_GET it trigger processing notification or generateing 'thank you' message
     *
     * @inheritdoc
     * Response status
     * @param array $data
     * @throws Exception
     */
    public function _postPayment( $data ) {

        $app = JFactory::getApplication();
        $isNotification = $app->input->get->get('notification');

        if($isNotification){
            $this->processNotification($app->input);
            $app->close();
        }

        $status = $app->input->get->getString('status');

        error_log("Call back response => " . var_export($_REQUEST, TRUE) );
        $vars = new JObject();
        if($_REQUEST['stateId']==3)
          $vars->message = JText::_('J2STORE_CONFIRMED');
        else {
          $vars->message = JText::_('J2STORE_FAILED');
        }
        // $message =$this->createConfirmMessage($status);
        return  $this->_getLayout('postpayment', $vars);

    }

    /**
     * This method process notification from jeeb. First it trigger few validation
     * and then based on validation status change status of order
     *
     * @param $data
     */
    private function processNotification($data)
    {
      $postdata = file_get_contents("php://input");
      $json = json_decode($postdata, true);

      if($json['signature']==$this->params->get('signature')){
        if($json['orderNo']){
          error_log("hey".$json['orderNo']);
          error_log(print_r($json, true));

          $orderId = $json['orderNo'];

          // Call Jeeb
          $network_uri = "https://core.jeeb.io/api/";


          error_log("Entered Jeeb-Notification");
          if ( $json['stateId']== 2 ) {
            error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
            error_log('Object : '.print_r($json, true));
            $this->setWrongStatus($orderId, 5);
          }
          else if ( $json['stateId']== 3 ) {
            error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
            error_log('Object : '.print_r($json, true));
            $this->setWrongStatus($orderId, 4);
          }
          else if ( $json['stateId']== 4 ) {
            error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
            $data = array(
              "token" => $json["token"]
            );

            $data_string = json_encode($data);
            $api_key = $this->params->get('signature');
            $url = $network_uri.'payments/' . $api_key . '/confirm';
            error_log("Signature:".$api_key." Base-Url:".$network_uri." Url:".$url);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string))
            );

            $result = curl_exec($ch);
            $data = json_decode( $result , true);
            error_log("data = ".var_export($data, TRUE));


            if($data['result']['isConfirmed']){
              error_log('Payment confirmed by jeeb');
              $this->setCompleteStatus($orderId);
            }
            else {
              error_log('Payment confirmation rejected by jeeb');
            }
          }
          else if ( $json['stateId']== 5 ) {
            error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
            $this->setWrongStatus($orderId, 6);

          }
          else if ( $json['stateId']== 6 ) {
            error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
            $this->setWrongStatus($orderId, 6);

          }
          else if ( $json['stateId']== 7 ) {
            error_log('Order Id received = '.$json['orderNo'].' stateId = '.$json['stateId']);
            $this->setWrongStatus($orderId, 6);
          }
          else{
            error_log('Cannot read state id sent by Jeeb');
          }
      }
  }
}

    /**
     * Set complete status. This method is internal j2store method which should
     * set everything what is necessary after complete payment
     *
     * @param $orderId
     */
    private function setCompleteStatus($orderId)
    {
        $order = $this->getOrder($orderId);
        $order->payment_complete();
        $this->save($order);
    }


    /**
     * This method change order status. Status is defined as $order_state_id
     * allowed id are:
     *  1 confirmed
     *  2 processing
     *  3 something goes wrong
     *  4 pending
     *  5 new
     *  6 canceled
     *
     * @param $orderId
     * @param $order_state_id
     */
    private function setWrongStatus($orderId , $order_state_id)
    {
        $order = $this->getOrder($orderId);
        $order->update_status( $order_state_id, true );
        $order->reduce_order_stock();
        $this->save($order);
    }

    /**
     * Saveing order model and trigger remove item from card if everything goes ok
     *
     * @param $order
     */
    private function save($order)
    {
        if($order->store()){
            $order->empty_cart();
        }
    }

    /**
     * Get order object from  model
     *
     * @param $orderId
     * @return static
     */
    private function getOrder($orderId)
    {
        F0FTable::addIncludePath ( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
        $order = F0FTable::getInstance ( 'Order', 'J2StoreTable' )->getClone();
        $order->load(array('order_id' => $orderId));
        return $order;
    }

    /**
     * Based on status set error or ok message displaying to customer
     *
     * @param $status
     * @return JObject
     */
    private function createConfirmMessage($status)
    {
        $vars = new JObject();

        switch ($status){
            case self::STATUS_OK:
                $vars->message = JText::_('J2STORE_CONFIRMED');
                break;
            default:
                $vars->message = JText::_('J2STORE_FAILED');
        }
        return $vars;
    }

    /**
     * Get actual language for page
     * @return mixed
     */
    private function getLanguage() {
        $lang   = JFactory::getLanguage();
        $lang   = explode( '-', $lang->getTag() );
        return $lang[0];
    }

    /**
     * Get order information
     * @param $data
     * @return mixed
     */
    private function getOrderInformation( $data ) {
        $order = $data['order']->getOrderInformation();
        return $order;
    }

    /**
     * Get price from order
     * @param $order_id
     * @return int
     */
    private function getPrice($order_id) {
        $order = $this->getOrder($order_id);
        if($order){
            return $order->order_total;
        }
        return 0;
    }

    public function convertIrrToBtc($url, $amount, $signature, $baseCur) {

        // return Jeeb::convert_irr_to_btc($url, $amount, $signature);
        $ch = curl_init($url.'currency?'.$signature.'&value='.$amount.'&base='.$baseCur.'&target=btc');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json')
      );

      $result = curl_exec($ch);
      $data = json_decode( $result , true);
      error_log("Response => ".var_export($data, TRUE));;
      // Return the equivalent bitcoin value acquired from Jeeb server.
      return (float) $data["result"];

      }


      public function createInvoice($url, $amount, $options = array(), $signature) {

          $post = json_encode($options);

          $ch = curl_init($url.'payments/' . $signature . '/issue/');
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
          curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
              'Content-Type: application/json',
              'Content-Length: ' . strlen($post))
          );

          $result = curl_exec($ch);
          $data = json_decode( $result , true);
          error_log("data = ".var_export($data, TRUE));

          return $data['result']['token'];

      }
    }
