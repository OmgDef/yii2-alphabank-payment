<?php
namespace omgdef\payment\alphabank\components;

use yii\base\Component;

/**
 * Class Payment
 * @package omgdef\payment\alphabank\components
 */
class Payment extends Component
{
    /**
     * @var string the merchant login
     */
    public $login;
    /**
     * @var string the merchant password
     */
    public $password;
    /**
     * @var string the gateway url
     */
    public $gatewayUrl;
    /**
     * @var integer number of the request attempts
     */
    public $retryCount = 5;
    /**
     * @var boolean use two-stage payment
     */
    public $twoStage = false;

    protected $_errors = [];
    protected $_remoteOrderID;
    protected $_bankFormUrl;

    /**
     * Registers the order in the payment system. If returns true, the bankOrderId and the bankFormUrl are ready to use.
     * @param $id
     * @param $amount
     * @param $returnUrl string URL on which to redirect at successful payment
     * @param $failUrl string URL on which to redirect at unsuccessful payment
     * @return bool
     */
    public function registerOrder($id, $amount, $returnUrl, $failUrl = null)
    {
        $data = [
            'userName' => $this->login,
            'password' => $this->password,
            'orderNumber' => urlencode($id),
            'amount' => $amount * 100,
            'returnUrl' => $returnUrl,
        ];

        if (!is_null($failUrl)) $data['failUrl'] = $failUrl;
        $response = $this->request($this->twoStage ? 'registerPreAuth.do' : 'register.do', $data);

        if (!$this->addError($response) && isset($response['orderId']) && isset($response['formUrl'])) {
            $this->_remoteOrderID = $response['orderId'];
            $this->_bankFormUrl = $response['formUrl'];
            return true;
        }

        return false;
    }

    /**
     * Inquiry of completion of payment of the order.
     * @param $remoteOrderID
     * @param $amount
     * @return bool
     */
    public function deposit($remoteOrderID, $amount = 0)
    {
        return !$this->addError($this->request('deposit.do', [
            'userName' => $this->login,
            'password' => $this->password,
            'orderId' => $remoteOrderID,
            'amount' => $amount * 100,
        ]));
    }

    /**
     * @param $remoteOrderID
     * @return bool
     */
    public function getOrderStatus($remoteOrderID)
    {
        $response = $this->request('getOrderStatus.do', [
            'userName' => $this->login,
            'password' => $this->password,
            'orderId' => $remoteOrderID
        ]);

        if (!$this->addError($response) && isset($response['OrderStatus'])) {
            return $response['OrderStatus'];
        }

        return false;
    }

    /**
     * @return string|null returns the order ID in the payment gateway. You should call the registerOrder and
     * make sure it returns true, before using this method.
     */
    public function getOrderID()
    {
        return $this->_remoteOrderID;
    }

    /**
     * @return string|null returns the payment form URL. You should call the registerOrder and make sure it returns
     * true, before using this method.
     */
    public function getFormUrl()
    {
        return $this->_bankFormUrl;
    }

    /**
     * Makes a request to the payment gateway
     * @param $method
     * @param $data
     * @return null
     */
    protected function request($method, $data)
    {
        $response = null;
        for ($i = 0; $i++ < $this->retryCount;) {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->gatewayUrl . $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data)
            ]);
            $response = curl_exec($curl);

            if (curl_getinfo($curl, CURLINFO_HTTP_CODE) === 200) {
                $response = json_decode($response, true);
                curl_close($curl);
                break;
            }
        }
        return $response;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->_errors;
    }
    
    /**
     * @param $response
     * @return bool
     */
    protected function addError($response)
    {
        if (isset($response['errorCode']) && ($response['errorCode'] > 0)) {
            $this->_errors[] = 'Error #' . $response['errorCode'] . ': ' .
                (isset($response['errorMessage']) ? $response['errorMessage'] : '');
            return true;
        }
        return false;
    }
}
