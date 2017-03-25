<?php

function getClientIp() {
    $ipaddress = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    } else if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else if(!empty($_SERVER['HTTP_X_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    } else if(!empty($_SERVER['HTTP_FORWARDED_FOR'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    } else if(!empty($_SERVER['HTTP_FORWARDED'])) {
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    } else if(!empty($_SERVER['REMOTE_ADDR'])) {
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    } else {
        $ipaddress = 'UNKNOWN';
    }
    return $ipaddress;
}

function mapPaymentMethodToSpecs($ShopwarePaymentName) {

    $method = strtolower(str_replace(" ", "", $ShopwarePaymentName));
    $IntrumMapping = array(
        'cashondelivery'	=> 'CASH-ON-DELIVERY',
        'banktransfer'		=> 'PRE-PAY',
        'ccsave'			=> 'CREDIT-CARD',
        'paypal'			=> 'E-PAYMENT',
        'bankwire'			=> 'INVOICE',
        'bill'			    => 'INVOICE',
        'invoice'			=> 'INVOICE',
        'invoicepayment'	=> 'INVOICE',
        'visa'	            => 'CREDIT-CARD',
        'maestro'	        => 'CREDIT-CARD',
        'mastercard'	    => 'CREDIT-CARD',
    );

    if(strpos($method, 'paypal')!==false){
        if(array_key_exists('paypal', $IntrumMapping)){
            return $IntrumMapping['paypal'];
        }
    }
    if(strpos($method, 'invoice')!==false){
        return $IntrumMapping['invoice'];
    }
    if(strpos($method, 'maestro')!==false){
        return $IntrumMapping['maestro'];
    }
    if(strpos($method, 'mastercard')!==false){
        return $IntrumMapping['mastercard'];
    }
    if(strpos($method, 'visa')!==false){
        return $IntrumMapping['visa'];
    }
    if(array_key_exists($method, $IntrumMapping)){
        return $IntrumMapping[$method];
    }
    return $method;
}

/* @var $controller \Shopware_Controllers_Frontend_PaymentInvoice  */
function CreateShopWareShopRequestUserBilling($user, $billing, $shipping, $controller) {

    $sql     = 'SELECT `countryiso` FROM s_core_countries WHERE id = ' . intval($billing["countryID"]);
    $countryBilling = Shopware()->Db()->fetchOne($sql);
    $sql     = 'SELECT `countryiso` FROM s_core_countries WHERE id = ' . intval($shipping["countryID"]);
    $countryShipping = Shopware()->Db()->fetchOne($sql);
    $request = new \ByjunoRequest();
    $request->setClientId(Shopware()->Config()->get("ByjunoPayments", "client_id"));
    $request->setUserID(Shopware()->Config()->get("ByjunoPayments", "user_id"));
    $request->setPassword(Shopware()->Config()->get("ByjunoPayments", "password"));
    $request->setVersion("1.00");
    $request->setRequestEmail(Shopware()->Config()->get("ByjunoPayments", "technical_contact"));


    $sql     = 'SELECT `language` FROM s_core_locales WHERE id = ' . intval($user["additional"]["user"]["language"]);
    $langName = Shopware()->Db()->fetchRow($sql);
    $lang = 'de';
    if (!empty($langName) &&  strlen($langName) > 4) {
        $lang = substr($langName, 0, 2);
    }
    $request->setLanguage($lang);

    $request->setRequestId(uniqid((String)$user['billingaddress']['customerBillingId']."_"));
    $reference = $user['billingaddress']['customerBillingId'];
    if (empty($reference)) {
        $request->setCustomerReference("guest_".$user['billingaddress']['customerBillingId']);
    } else {
        $request->setCustomerReference($user['billingaddress']['customerBillingId']);
    }
    $request->setFirstName((String)$billing['firstname']);
    $request->setLastName((String)$billing['lastname']);
    $request->setFirstLine(trim((String)$billing['street'].' '.$billing['streetnumber']));
    $request->setCountryCode(strtoupper((String)$countryBilling));
    $request->setPostCode((String)$billing['zipcode']);
    $request->setTown((String)$billing['city']);
    $request->setFax((String)$billing['fax']);

    if (!empty($billing['birthday']) && substr($billing['birthday'], 0, 4) != '0000') {
        $request->setDateOfBirth((String)$billing['birthday']);
    }

    $request->setTelephonePrivate((String)$billing['phone']);
    $request->setEmail((String)$user["additional"]["user"]["email"]);

    $extraInfo["Name"] = 'ORDERCLOSED';
    $extraInfo["Value"] = 'NO';
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERAMOUNT';
    $extraInfo["Value"] = $controller->getAmount();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERCURRENCY';
    $extraInfo["Value"] = $controller->getCurrencyShortName();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'IP';
    $extraInfo["Value"] = getClientIp();
    $request->setExtraInfo($extraInfo);

    $tmx_enable = Shopware()->Config()->get("ByjunoPayments", "tmx_enable");
    $tmxorgid = Shopware()->Config()->get("ByjunoPayments", "tmxorgid");
    if (isset($tmx_enable) && $tmx_enable == 'enable' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["intrum_tmx"])) {
        $extraInfo["Name"] = 'DEVICE_FINGERPRINT_ID';
        $extraInfo["Value"] = $_SESSION["intrum_tmx"];
        $request->setExtraInfo($extraInfo);
    }

    /* shipping information */
    $extraInfo["Name"] = 'DELIVERY_FIRSTNAME';
    $extraInfo["Value"] = $shipping['firstname'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_LASTNAME';
    $extraInfo["Value"] = $shipping['lastname'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_FIRSTLINE';
    $extraInfo["Value"] = trim($shipping['street'].' '.$shipping['streetnumber']);
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_HOUSENUMBER';
    $extraInfo["Value"] = '';
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_COUNTRYCODE';
    $extraInfo["Value"] = $countryShipping;
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_POSTCODE';
    $extraInfo["Value"] = $shipping['zipcode'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_TOWN';
    $extraInfo["Value"] = $shipping['city'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'CONNECTIVTY_MODULE';
    $extraInfo["Value"] = 'Byjuno ShopWare module 1.0.0';
    $request->setExtraInfo($extraInfo);

    return $request;

}

function CreateShopWareShopRequest(\Shopware_Controllers_Frontend_PaymentInvoice $order)
{
    /* @var \Shopware\Models\Order\Billing $billing */
    $billing = $order->getBilling();
    /* @var \Shopware\Models\Order\Shipping $shipping */
    $shipping = $order->getShipping();
    $request = new \ByjunoRequest();
    $request->setClientId(Shopware()->Config()->get("ByjunoPayments", "client_id"));
    $request->setUserID(Shopware()->Config()->get("ByjunoPayments", "user_id"));
    $request->setPassword(Shopware()->Config()->get("ByjunoPayments", "password"));
    $request->setVersion("1.00");
    $request->setRequestEmail(Shopware()->Config()->get("ByjunoPayments", "technical_contact"));
	
	
    $sql     = 'SELECT `locale` FROM s_core_locales WHERE id = ' . intval($order->getLanguageIso());
    $langName = Shopware()->Db()->fetchRow($sql);
    $lang = 'de';
    if (!empty($langName) &&  strlen($langName) > 4) {
        $lang = substr($langName, 0, 2);
    }
    $request->setLanguage($lang);

    $request->setRequestId(uniqid((String)$billing->getId()));
    $reference = $billing->getCustomer();
    if (empty($reference)) {
        $request->setCustomerReference("guest_".$billing->getId());
    } else {
        $request->setCustomerReference($billing->getCustomer()->getId());
    }
    $request->setFirstName((String)$billing->getFirstName());
    $request->setLastName((String)$billing->getLastName());
    $request->setFirstLine(trim((String)$billing->getStreet().' '.$billing->getAdditionalAddressLine1().' '.$billing->getAdditionalAddressLine1()));
    $request->setCountryCode(strtoupper((String)$billing->getCountry()->getIso()));
    $request->setPostCode((String)$billing->getZipCode());
    $request->setTown((String)$billing->getCity());

	if (!empty($reference) && !empty($billing->getCustomer()->getBirthday()) && substr($billing->getCustomer()->getBirthday(), 0, 4) != '0000') {
		$request->setDateOfBirth((String)$billing->getCustomer()->getBirthday());
	}

    $request->setTelephonePrivate((String)$billing->getPhone());
    if (!empty($reference)) {
        $request->setEmail((String)$billing->getCustomer()->getEmail());
    }

    $extraInfo["Name"] = 'ORDERCLOSED';
    $extraInfo["Value"] = 'NO';
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERAMOUNT';
    $extraInfo["Value"] = $order->getInvoiceAmount();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERCURRENCY';
    $extraInfo["Value"] = $order->getCurrency();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'IP';
    $extraInfo["Value"] = getClientIp();
    $request->setExtraInfo($extraInfo);

    $tmx_enable = Shopware()->Config()->get("ByjunoPayments", "tmx_enable");
    $tmxorgid = Shopware()->Config()->get("ByjunoPayments", "tmxorgid");
    if (isset($tmx_enable) && $tmx_enable == 'enable' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["intrum_tmx"])) {
        $extraInfo["Name"] = 'DEVICE_FINGERPRINT_ID';
        $extraInfo["Value"] = $_SESSION["intrum_tmx"];
        $request->setExtraInfo($extraInfo);
    }

    /* shipping information */
    $extraInfo["Name"] = 'DELIVERY_FIRSTNAME';
    $extraInfo["Value"] = $shipping->getFirstName();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_LASTNAME';
    $extraInfo["Value"] = $shipping->getLastName();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_FIRSTLINE';
    $extraInfo["Value"] = trim((String)$shipping->getStreet().' '.$shipping->getAdditionalAddressLine1().' '.$shipping->getAdditionalAddressLine1());
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_HOUSENUMBER';
    $extraInfo["Value"] = '';
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_COUNTRYCODE';
    $extraInfo["Value"] = $shipping->getCountry()->getIso();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_POSTCODE';
    $extraInfo["Value"] = $shipping->getZipCode();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_TOWN';
    $extraInfo["Value"] = $shipping->getCity();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'CONNECTIVTY_MODULE';
    $extraInfo["Value"] = 'Byjuno ShopWare module 1.3.0';
    $request->setExtraInfo($extraInfo);
    return $request;

}


function CreateShopWareOrderRequest($user, $billing, $shipping, \Shopware\Models\Order\Order $order, Enlight_Config $config) {

    $sql     = 'SELECT `countryiso` FROM s_core_countries WHERE id = ' . intval($billing["countryID"]);
    $countryBilling = Shopware()->Db()->fetchOne($sql);
    $sql     = 'SELECT `countryiso` FROM s_core_countries WHERE id = ' . intval($shipping["countryID"]);
    $countryShipping = Shopware()->Db()->fetchOne($sql);

    $request = new IntrumRequest();
    $request->setClientId($config->get("client_id"));
    $request->setUserID($config->get("user_id"));
    $request->setPassword($config->get("password"));
    $request->setVersion("1.00");
    $request->setRequestEmail($config->get("technical_contact"));
	
	$sql     = 'SELECT `language` FROM s_core_locales WHERE id = ' . intval($order->getLanguageIso());
    $langName = Shopware()->Db()->fetchOne($sql);	
	if (isset($langName) && $langName == 'English') {
		$request->setLanguage('en');
	} else if (isset($langName) && $langName == 'German') {
		$request->setLanguage('de');	
	} else {
		$request->setLanguage('de');	
	}

    $request->setRequestId(uniqid((String)$user['billingaddress']['customerBillingId']."_"));
    $reference = $user['billingaddress']['customerBillingId'];
    if (empty($reference)) {
        $request->setCustomerReference("guest_".$user['billingaddress']['customerBillingId']);
    } else {
        $request->setCustomerReference($user['billingaddress']['customerBillingId']);
    }
    $request->setFirstName((String)$billing['firstname']);
    $request->setLastName((String)$billing['lastname']);
    $request->setFirstLine(trim((String)$billing['street'].' '.$billing['streetnumber']));
    $request->setCountryCode(strtoupper((String)$countryBilling));
    $request->setPostCode((String)$billing['zipcode']);
    $request->setTown((String)$billing['city']);
    $request->setFax((String)$billing['fax']);
	
	if (!empty($billing['birthday']) && substr($billing['birthday'], 0, 4) != '0000') {
		$request->setDateOfBirth((String)$billing['birthday']);		
	}

    $request->setTelephonePrivate((String)$billing['phone']);
    $request->setEmail((String)$user["additional"]["user"]["email"]);

    $extraInfo["Name"] = 'ORDERCLOSED';
    $extraInfo["Value"] = 'YES';
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERAMOUNT';
    $extraInfo["Value"] = $order->getInvoiceAmount();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERCURRENCY';
    $extraInfo["Value"] = $order->getCurrency();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'IP';
    $extraInfo["Value"] = getClientIp();
    $request->setExtraInfo($extraInfo);

    $tmx_enable = $config->get("tmx_enable");
    $tmxorgid = $config->get("tmxorgid");
    if (isset($tmx_enable) && $tmx_enable == 'enable' && isset($tmxorgid) && $tmxorgid != '' && !empty($_SESSION["intrum_tmx"])) {
        $extraInfo["Name"] = 'DEVICE_FINGERPRINT_ID';
        $extraInfo["Value"] = $_SESSION["intrum_tmx"];
        $request->setExtraInfo($extraInfo);
    }

    /* shipping information */
    $extraInfo["Name"] = 'DELIVERY_FIRSTNAME';
    $extraInfo["Value"] = $shipping['firstname'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_LASTNAME';
    $extraInfo["Value"] = $shipping['lastname'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_FIRSTLINE';
    $extraInfo["Value"] = trim($shipping['street'].' '.$shipping['streetnumber']);
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_HOUSENUMBER';
    $extraInfo["Value"] = '';
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_COUNTRYCODE';
    $extraInfo["Value"] = $countryShipping;
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_POSTCODE';
    $extraInfo["Value"] = $shipping['zipcode'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'DELIVERY_TOWN';
    $extraInfo["Value"] = $shipping['city'];
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'ORDERID';
    $extraInfo["Value"] = $order->getNumber();
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'PAYMENTMETHOD';
    $extraInfo["Value"] = mapPaymentMethodToSpecs($order->getPayment()->getName());
    $request->setExtraInfo($extraInfo);

    $extraInfo["Name"] = 'CONNECTIVTY_MODULE';
    $extraInfo["Value"] = 'Intrum ShopWare module 1.3.0';
    $request->setExtraInfo($extraInfo);	

    return $request;

}