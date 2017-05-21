<?php

use ByjunoPayments\Components\ByjunoPayment\PaymentResponse;
use ByjunoPayments\Components\ByjunoPayment\InvoicePaymentService;

class Shopware_Controllers_Frontend_BasebyjunoController extends Shopware_Controllers_Frontend_Payment
{
    const PAYMENTSTATUSPAID = 12;
    const PAYMENTSTATUSOPEN = 17;
    const PAYMENTSTATUSVOID = 30;
    
    const ORDERSTATUSCANCEL = 4;
    const ORDERSTATUSINPROGRESS = 1;

    public $custom_birthday;
    public $custom_gender;
    protected $payment_plan;
    protected $payment_send;
    protected $payment_send_to;
    protected function saveTransactionPaymentData($orderId, $key, $paymentData)
    {
        $sql = 'UPDATE `s_order_attributes` SET `'.$key.'`=? WHERE orderID = ?';
        Shopware()->Db()->query($sql, array(serialize($paymentData), $orderId));
    }

    protected function getPaymentDataFromOrder($orderId, $key)
    {
        $sql = 'SELECT `'.$key.'` FROM `s_order_attributes` WHERE orderID = ?';
        $paymentData = Shopware()->Db()->fetchOne($sql, $orderId);

        return unserialize($paymentData);
    }

    protected function minMaxCheck()
    {
        $min = Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_minimum");
        $max = Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_maximum");
        $amount = $this->getAmount();
        if ($amount < $min || $amount > $max)
        {
            return false;
        }
        return true;
    }

    protected function CDPRequest($paymentMethod)
    {
        $statusCDP = 0;
        $mode = Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_mode");
        $b2b = Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_b2b");
        $user = $this->getUser();
        $billing = $user['billingaddress'];
        $shipping = $user['shippingaddress'];
        $request = CreateShopWareShopRequestUserBilling($user, $billing, $shipping, $this, $paymentMethod, "", "", "", "",  "NO");
        $statusLog = "CDP request";
        if ($request->getCompanyName1() != '' && $b2b == 'Enabled') {
            $statusLog = "CDP request for company";
            $xml = $request->createRequestCompany();
        } else {
            $xml = $request->createRequest();
        }
        $byjunoCommunicator = new \ByjunoCommunicator();
        if (isset($mode) && $mode == 'Live') {
            $byjunoCommunicator->setServer('live');
        } else {
            $byjunoCommunicator->setServer('test');
        }
        $response = $byjunoCommunicator->sendRequest($xml);
        if ($response) {
            $byjunoResponse = new ByjunoResponse();
            $byjunoResponse->setRawResponse($response);
            $byjunoResponse->processResponse();
            $statusCDP = (int)$byjunoResponse->getCustomerRequestStatus();
            $this->saveLog($request, $xml, $response, $statusCDP, $statusLog);
            if (intval($statusCDP) > 15) {
                $statusCDP = 0;
            }
        }
        return $this->isStatusOkCDP($statusCDP);
    }

    protected function isStatusOkS2($status) {
        try {
            $accepted_S2_ij = Shopware()->Config()->getByNamespace("ByjunoPayments", "allowed_s2");
            $accepted_S2_merhcant = Shopware()->Config()->getByNamespace("ByjunoPayments", "allowed_s2_merchant");
            $ijStatus = explode(",", $accepted_S2_ij);
            $merchantStatus = explode(",", $accepted_S2_merhcant);
            if (in_array($status, $ijStatus)) {
                return true;
            } else if (in_array($status, $merchantStatus)) {
                return true;
            }
            return false;

        } catch (Exception $e) {
            return false;
        }
    }

    protected function isStatusOkCDP($status) {
        try {
            $accepted_CDP = Shopware()->Config()->getByNamespace("ByjunoPayments", "allowed_cdp");
            $ijStatus = explode(",", $accepted_CDP);
            if (in_array($status, $ijStatus)) {
                return true;
            }
            return false;

        } catch (Exception $e) {
            return false;
        }
    }


    protected function isStatusOkS3($status) {
        try {
            $accepted_S3 = Shopware()->Config()->getByNamespace("ByjunoPayments", "allowed_s3");
            $ijStatus = explode(",", $accepted_S3);
            if (in_array($status, $ijStatus)) {
                return true;
            }
            return false;

        } catch (Exception $e) {
            return false;
        }
    }

    protected function getStatusRisk($status) {
        try {
            $accepted_S2_ij = Shopware()->Config()->getByNamespace("ByjunoPayments", "allowed_s2");
            $accepted_S2_merhcant = Shopware()->Config()->getByNamespace("ByjunoPayments", "allowed_s2_merchant");
            $ijStatus = explode(",", $accepted_S2_ij);
            $merchantStatus = explode(",", $accepted_S2_merhcant);
            if (in_array($status, $ijStatus)) {
                return "IJ";
            } else if (in_array($status, $merchantStatus)) {
                return "CLIENT";
            }
            return "No owner";

        } catch (Exception $e) {
            return "INTERNAL ERROR";
        }
    }

    public function SaveLog(ByjunoRequest $request, $xml_request, $xml_response, $status, $type) {
        $sql     = '
            INSERT INTO s_plugin_byjuno_transactions (requestid, requesttype, firstname, lastname, ip, status, datecolumn, xml_request, xml_responce)
                    VALUES (?,?,?,?,?,?,?,?,?)
        ';
        Shopware()->Db()->query($sql, Array(
            $request->getRequestId(),
            $type,
            $request->getFirstName(),
            $request->getLastName(),
            $_SERVER['REMOTE_ADDR'],
            (($status != 0) ? $status : 'Error'),
            date('Y-m-d\TH:i:sP'),
            $xml_request,
            $xml_response
        ));
    }

    /**
     * Cancel action method
     */
    public function cancelcdpAction()
    {
        $snippets = Shopware()->Snippets()->getNamespace('frontend/byjuno/index');
        $_SESSION["byjuno"]["paymentMessage"] = $snippets->get('paymentcdp_canceled', "Byjuno invoice");
        $this->redirect(array(
            'controller' => 'checkout',
            'action' => 'payment'
        ));
    }

    /**
     * Cancel action method
     */
    public function cancelAction()
    {
        $snippets = Shopware()->Snippets()->getNamespace('frontend/byjuno/index');
        $_SESSION["byjuno"]["paymentMessage"] = $snippets->get('payment_canceled', "Byjuno invoice");
        $this->redirect(array(
            'controller' => 'checkout',
            'action' => 'payment'
        ));
    }
    /**
     * Cancel action method
     */
    public function cancelminmaxAction()
    {
        $snippets = Shopware()->Snippets()->getNamespace('frontend/byjuno/index');
        $_SESSION["byjuno"]["paymentMessage"] = $snippets->get('paymentminmax_canceled', "Byjuno invoice");
        $this->redirect(array(
            'controller' => 'checkout',
            'action' => 'payment'
        ));
    }

    /**
     * Gateway action method.
     *
     * Collects the payment information and transmit it to the payment provider.
     */
    protected function gatewayAction($paymentMethod)
    {
        $mode = Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_mode");
        $b2b = Shopware()->Config()->getByNamespace("ByjunoPayments", "byjuno_b2b");
        $user = $this->getUser();
        $billing = $user['billingaddress'];
        $shipping = $user['shippingaddress'];
        $statusS1 = 0;
        $statusS3 = 0;
        $request = CreateShopWareShopRequestUserBilling($user, $billing, $shipping, $this, $paymentMethod, $this->payment_plan, $this->payment_send, "", "",  "NO");
        $statusLog = "Order request (S1)";
        if ($request->getCompanyName1() != '' && $b2b == 'Enabled') {
            $statusLog = "Order request for company (S1)";
            $xml = $request->createRequestCompany();
        } else {
            $xml = $request->createRequest();
        }
        $byjunoCommunicator = new \ByjunoCommunicator();
        if (isset($mode) && $mode == 'Live') {
            $byjunoCommunicator->setServer('live');
        } else {
            $byjunoCommunicator->setServer('test');
        }
        $response = $byjunoCommunicator->sendRequest($xml);
        if ($response) {
            $byjunoResponse = new ByjunoResponse();
            $byjunoResponse->setRawResponse($response);
            $byjunoResponse->processResponse();
            $statusS1 = (int)$byjunoResponse->getCustomerRequestStatus();
            $this->saveLog($request, $xml, $response, $statusS1, $statusLog);
            if (intval($statusS1) > 15) {
                $statusS1 = 0;
            }
        }
        $order = null;
        if ($this->isStatusOkS2($statusS1)) {
            $this->saveOrder(1, uniqid("byjuno_"), self::PAYMENTSTATUSOPEN);
            /* @var $order \Shopware\Models\Order\Order */
            $order = Shopware()->Models()->getRepository('Shopware\Models\Order\Order')
                ->findOneBy(array('number' => $this->getOrderNumber()));

            $risk = $this->getStatusRisk($statusS1);
            $request = CreateShopWareShopRequestUserBilling($user, $billing, $shipping, $this, $paymentMethod, $this->payment_plan, $this->payment_send, $risk, $order->getNumber(), "YES");
            $statusLog = "Order complete (S3)";
            if ($request->getCompanyName1() != '' && $b2b == 'Enabled') {
                $statusLog = "Order complete for company (S3)";
                $xml = $request->createRequestCompany();
            } else {
                $xml = $request->createRequest();
            }
            $byjunoCommunicator = new \ByjunoCommunicator();
            if (isset($mode) && $mode == 'Live') {
                $byjunoCommunicator->setServer('live');
            } else {
                $byjunoCommunicator->setServer('test');
            }
            $response = $byjunoCommunicator->sendRequest($xml);
            if ($response) {
                $byjunoResponse = new ByjunoResponse();
                $byjunoResponse->setRawResponse($response);
                $byjunoResponse->processResponse();
                $statusS3 = (int)$byjunoResponse->getCustomerRequestStatus();
                $this->saveLog($request, $xml, $response, $statusS3, $statusLog);
                if (intval($statusS3) > 15) {
                    $statusS3 = 0;
                }
            }
        } else {
            return false;
        }
        if ($order == null) {
            return false;
        }
        $orderModule = Shopware()->Modules()->Order();
        if ($this->isStatusOkS2($statusS1) && $this->isStatusOkS3($statusS3)) {
            $orderModule->setPaymentStatus($order->getId(), self::PAYMENTSTATUSPAID, false);
            $orderModule->setOrderStatus($order->getId(), self::ORDERSTATUSINPROGRESS, false);
            $mail = $orderModule->createStatusMail($order->getId(), self::PAYMENTSTATUSPAID);
            $mail->clearRecipients();
            $mail->addTo(Shopware()->Config()->get("ByjunoPayments", "byjuno_email"));
            $orderModule->sendStatusMail($mail);
            $this->saveTransactionPaymentData($order->getId(), 'payment_plan', $this->payment_plan);
            return true;
        } else {
            $orderModule->setPaymentStatus($order->getId(), self::PAYMENTSTATUSVOID, false);
            $orderModule->setOrderStatus($order->getId(), self::ORDERSTATUSCANCEL, false);
        }
        return false;
    }

    protected function baseConfirmActions()
    {
        /**
         * Check if one of the payment methods is selected. Else return to default controller.
         */
        if ($this->Request()->isPost()) {
            $this->payment_plan = $this->Request()->getParam('payment_plan');
            $this->payment_send = $this->Request()->getParam('invoice_send');
            $user = $this->getUser();
            if ($this->payment_send == "email") {
                $this->payment_send_to = (String)$user["additional"]["user"]["email"];
            } else {
                $billing = $user['billingaddress'];
                $address = trim(trim((String)$billing['street'].' '.$billing['streetnumber']).', '.(String)$billing['city'].', '.(String)$billing['zipcode']);
                $this->payment_send_to = $address;
            }
            $custom_gender = $this->Request()->getParam('custom_gender');
            if ($custom_gender != null) {
                $this->custom_gender = $custom_gender;
            }
            $custom_birthday = $this->Request()->getParam('custom_birthday');

            if ($custom_birthday != null && isset($custom_birthday["day"]) && isset($custom_birthday["month"]) && isset($custom_birthday["year"])) {
                $this->custom_birthday = $custom_birthday["year"]."-".$custom_birthday["month"]."-".$custom_birthday["day"];
            }
        }
    }


}