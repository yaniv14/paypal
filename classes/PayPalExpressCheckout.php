<?php
/**
 * 2017 Thirty Bees
 * 2007-2016 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 *  @author    Thirty Bees <modules@thirtybees.com>
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2017 Thirty Bees
 *  @copyright 2007-2016 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

require_once dirname(__FILE__).'/../paypal.php';

class PayPalExpressCheckout extends Paypal
{
    public $logs = array();

    public $method_version = '106';

    public $method;

    /** @var Currency $currency Currency used for the payment process **/
    public $currency;

    /** @var int $decimals Used to set prices precision **/
    public $decimals;

    /** @var string $result Contains the last request result **/
    public $result;

    /** @var string $token Contains the last token **/
    public $token;

    /* Depending of the type set, id_cart or id_product will be set */
    public $idCart;

    // Depending of the type set, id_cart or id_product will be set
    public $idProduct;

    public $idProductAttribute;

    public $quantity;

    public $payerId;

    public $availableTypes = array('cart', 'product', 'payment_cart');

    public $totalDifferentProduct;

    public $productList = array();

    /* Used to know if user can validated his payment after shipping / address selection */
    public $ready = false;

    /* Take for now cart or product value */
    public $type = false;

    public static $cookieName = 'express_checkout';

    public $cookieKey = array(
        'token',
        'id_product',
        'id_prodcut_attrbute',
        'quantity',
        'type',
        'total_different_product',
        'secure_key',
        'ready',
        'payer_id',
    );

    /**
     * PayPalExpressCheckout constructor.
     *
     * @param bool $type
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function __construct($type = false)
    {
        parent::__construct();

        // If type is sent, the cookie has to be delete
        if ($type) {
            unset($this->context->cookie->{self::$cookieName});
            $this->setExpressCheckoutType($type);
        }

        // Store back the PayPal data if present under the cookie
        if (isset($this->context->cookie->{self::$cookieName})) {
            $paypal = unserialize($this->context->cookie->{self::$cookieName});

            foreach ($this->cookieKey as $key) {
                $this->{$key} = $paypal[$key];
            }

        }
    }

    // Will build the product_list depending of the type
    /**
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function initParameters()
    {
        if (!$this->context->cart || !$this->context->cart->id) {
            return false;
        }

        $cartCurrency = new Currency((int) $this->context->cart->id_currency);
        $currencyModule = $this->getCurrency((int) $this->context->cart->id_currency);

        $this->currency = $cartCurrency;

        if (!Validate::isLoadedObject($this->currency)) {
            $this->errors[] = $this->l('Not a valid currency');
        }

        if (count($this->errors)) {
            return false;
        }

        $currencyDecimals = is_array($this->currency) ? (int) $this->currency['decimals'] : (int) $this->currency->decimals;
        $this->decimals = $currencyDecimals * _PS_PRICE_DISPLAY_PRECISION_;

        if ($cartCurrency !== $currencyModule) {
            $this->context->cart->id_currency = $currencyModule->id;
            $this->context->cart->update();
        }

        $this->context->currency = $currencyModule;
        $this->productList = $this->context->cart->getProducts(true);

        return (bool) count($this->productList);
    }

    /**
     * @param bool $accessToken
     *
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function setExpressCheckout($accessToken = false)
    {
        $this->method = 'SetExpressCheckout';
        $fields = array();
        $this->setCancelUrl($fields);

        // Only this call need to get the value from the $_GET / $_POST array
        if (!$this->initParameters() || !$fields['CANCELURL']) {
            return false;
        }

        // Set payment detail (reference)
        $this->setPaymentDetails($fields);
        $fields['SOLUTIONTYPE'] = 'Sole';
        $fields['LANDINGPAGE'] = 'Login';

        // Seller informations
        $fields['USER'] = Configuration::get('PAYPAL_API_USER');
        $fields['PWD'] = Configuration::get('PAYPAL_API_PASSWORD');
        $fields['SIGNATURE'] = Configuration::get('PAYPAL_API_SIGNATURE');

        if ($accessToken) {
            $fields['IDENTITYACCESSTOKEN'] = $accessToken;
        }

        if (Country::getIsoById(Configuration::get('PAYPAL_COUNTRY_DEFAULT')) == 'de') {
            $fields['BANKTXNPENDINGURL'] = Context::getContext()->link->getModuleLink('paypal', 'expresscheckoutpayment', array('banktxnpendingurl' => 'true'), Tools::usingSecureMode());
        }

        $this->callAPI($fields);
        $this->storeToken();
    }

    /**
     * @param $fields
     */
    public function setCancelUrl(&$fields)
    {
        $url = Context::getContext()->link->getModuleLink('paypal', 'expresscheckoutpayment', array(), Tools::usingSecureMode()).'?'.urldecode($_SERVER['QUERY_STRING']);
        $parsedData = parse_url($url);

        $parsedData['scheme'] .= '://';

        if (isset($parsedData['path'])) {
            $parsedData['path'] .= '?paypal_ec_canceled=1&';
            $parsedData['query'] = isset($parsedData['query']) ? $parsedData['query'] : null;
        } else {
            $parsedData['path'] = '?paypal_ec_canceled=1&';
            $parsedData['query'] = '/'.(isset($parsedData['query']) ? $parsedData['query'] : null);
        }

        $cancelUrl = implode($parsedData);

        if (!empty($cancelUrl)) {
            $fields['CANCELURL'] = $cancelUrl;
        }

    }

    /**
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function getExpressCheckout()
    {
        $this->method = 'GetExpressCheckoutDetails';
        $fields = array();
        $fields['TOKEN'] = $this->token;

        $this->initParameters();
        $this->callAPI($fields);

        // The same token of SetExpressCheckout
        $this->storeToken();
    }

    /**
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function doExpressCheckout()
    {
        $this->method = 'DoExpressCheckoutPayment';
        $fields = array();
        $fields['TOKEN'] = $this->token;
        $fields['PAYERID'] = $this->payerId;

        if (Configuration::get('PAYPAL_COUNTRY_DEFAULT') == 1) {
            $fields['BANKTXNPENDINGURL'] = '';
        }

        if (count($this->productList) <= 0) {
            $this->initParameters();
        }

        // Set payment detail (reference)
        $this->setPaymentDetails($fields);
        $this->callAPI($fields);

        $this->result += $fields;
    }

    /**
     * @param $fields
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function callAPI($fields)
    {
        $this->logs = array();
        $paypalLib = new PaypalLib();

        $this->result = $paypalLib->makeCall($this->getAPIURL(), $this->getAPIScript(), $this->method, $fields, $this->method_version);
        $this->logs = array_merge($this->logs, $paypalLib->getLogs());

        $this->storeToken();
    }

    /**
     * @param $fields
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function setPaymentDetails(&$fields)
    {
        // Required field
        $fields['RETURNURL'] = Context::getContext()->link->getModuleLink('paypal', 'expresscheckoutpayment', array(), Tools::usingSecureMode());
        $fields['NOSHIPPING'] = '1';
        $fields['BUTTONSOURCE'] = $this->getTrackingCode((int) Configuration::get('PAYPAL_PAYMENT_METHOD'));

        // Products
        $taxes = $total = 0;
        $index = -1;

        // Set cart products list
        $this->setProductsList($fields, $index, $total, $taxes);
        $this->setDiscountsList($fields, $index, $total, $taxes);
        $this->setGiftWrapping($fields, $index, $total);

        // Payment values
        $this->setPaymentValues($fields, $index, $total, $taxes);

        $idAddress = (int) $this->context->cart->id_address_delivery;
        if (($idAddress == 0) && ($this->context->customer)) {
            $idAddress = Address::getFirstCustomerAddressId($this->context->customer->id);
        }

        if ($idAddress && method_exists($this->context->cart, 'isVirtualCart') && !$this->context->cart->isVirtualCart()) {
            $this->setShippingAddress($fields, $idAddress);
        } else {
            $fields['NOSHIPPING'] = '0';
        }

        foreach ($fields as &$field) {
            if (is_numeric($field)) {
                $field = str_replace(',', '.', $field);
            }
        }

    }

    /**
     * @param $fields
     * @param $idAddress
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function setShippingAddress(&$fields, $idAddress)
    {
        $address = new Address($idAddress);

        //We allow address modification when using express checkout shortcut
        if ($this->type != 'payment_cart') {
            $fields['ADDROVERRIDE'] = '0';
            $fields['NOSHIPPING'] = '0';
        } else {
            $fields['ADDROVERRIDE'] = '1';
        }

        $fields['EMAIL'] = $this->context->customer->email;
        $fields['PAYMENTREQUEST_0_SHIPTONAME'] = $address->firstname.' '.$address->lastname;
        $fields['PAYMENTREQUEST_0_SHIPTOPHONENUM'] = (empty($address->phone)) ? $address->phone_mobile : $address->phone;
        $fields['PAYMENTREQUEST_0_SHIPTOSTREET'] = $address->address1;
        $fields['PAYMENTREQUEST_0_SHIPTOSTREET2'] = $address->address2;
        $fields['PAYMENTREQUEST_0_SHIPTOCITY'] = $address->city;

        if ($address->id_state) {
            $state = new State((int) $address->id_state);
            $fields['PAYMENTREQUEST_0_SHIPTOSTATE'] = $state->iso_code;
        }

        $country = new Country((int) $address->id_country);
        $fields['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'] = $country->iso_code;
        $fields['PAYMENTREQUEST_0_SHIPTOZIP'] = $address->postcode;
    }

    /**
     * @param $fields
     * @param $index
     * @param $total
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function setProductsList(&$fields, &$index, &$total)
    {
        foreach ($this->productList as $product) {
            $fields['L_PAYMENTREQUEST_0_NUMBER'.++$index] = (int) $product['id_product'];

            $fields['L_PAYMENTREQUEST_0_NAME'.$index] = $product['name'];

            if (isset($product['attributes']) && (empty($product['attributes']) === false)) {
                $fields['L_PAYMENTREQUEST_0_NAME'.$index] .= ' - '.$product['attributes'];
            }

            $fields['L_PAYMENTREQUEST_0_DESC'.$index] = Tools::substr(strip_tags($product['description_short']), 0, 50).'...';

            $fields['L_PAYMENTREQUEST_0_AMT'.$index] = Tools::ps_round($product['price_wt'], $this->decimals);
            $fields['L_PAYMENTREQUEST_0_QTY'.$index] = $product['quantity'];

            $total = $total + ($fields['L_PAYMENTREQUEST_0_AMT'.$index] * $product['quantity']);
        }
    }

    /**
     * @param $fields
     * @param $index
     * @param $total
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function setDiscountsList(&$fields, &$index, &$total)
    {
        $discounts = (_PS_VERSION_ < '1.5') ? $this->context->cart->getDiscounts() : $this->context->cart->getCartRules();

        if (count($discounts) > 0) {
            foreach ($discounts as $discount) {
                $fields['L_PAYMENTREQUEST_0_NUMBER'.++$index] = $discount['id_discount'];

                $fields['L_PAYMENTREQUEST_0_NAME'.$index] = $discount['name'];
                if (isset($discount['description']) && !empty($discount['description'])) {
                    $fields['L_PAYMENTREQUEST_0_DESC'.$index] = Tools::substr(strip_tags($discount['description']), 0, 50).'...';
                }

                /* It is a discount so we store a negative value */
                $fields['L_PAYMENTREQUEST_0_AMT'.$index] = -1 * Tools::ps_round($discount['value_real'], $this->decimals);
                $fields['L_PAYMENTREQUEST_0_QTY'.$index] = 1;

                $total = Tools::ps_round($total + $fields['L_PAYMENTREQUEST_0_AMT'.$index], $this->decimals);
            }
        }

    }

    /**
     * @param $fields
     * @param $index
     * @param $total
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function setGiftWrapping(&$fields, &$index, &$total)
    {
        if ($this->context->cart->gift == 1) {
            $giftWrappingPrice = $this->getGiftWrappingPrice();

            $fields['L_PAYMENTREQUEST_0_NAME'.++$index] = $this->l('Gift wrapping');

            $fields['L_PAYMENTREQUEST_0_AMT'.$index] = Tools::ps_round($giftWrappingPrice, $this->decimals);
            $fields['L_PAYMENTREQUEST_0_QTY'.$index] = 1;

            $total = Tools::ps_round($total + $giftWrappingPrice, $this->decimals);
        }
    }

    /**
     * @param $fields
     * @param $index
     * @param $total
     * @param $taxes
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function setPaymentValues(&$fields, &$index, &$total, &$taxes)
    {
        $shippingCostTaxIncl = $this->context->cart->getTotalShippingCost();

        if ((bool) Configuration::get('PAYPAL_CAPTURE')) {
            $fields['PAYMENTREQUEST_0_PAYMENTACTION'] = 'Authorization';
        } else {
            $fields['PAYMENTREQUEST_0_PAYMENTACTION'] = 'Sale';
        }

        $currency = new Currency((int) $this->context->cart->id_currency);
        $fields['PAYMENTREQUEST_0_CURRENCYCODE'] = $currency->iso_code;

        /**
         * If the total amount is lower than 1 we put the shipping cost as an item
         * so the payment could be valid.
         */
        if ($total <= 1) {
            $carrier = new Carrier($this->context->cart->id_carrier);
            $fields['L_PAYMENTREQUEST_0_NUMBER'.++$index] = $carrier->id_reference;
            $fields['L_PAYMENTREQUEST_0_NAME'.$index] = $carrier->name;
            $fields['L_PAYMENTREQUEST_0_AMT'.$index] = Tools::ps_round($shippingCostTaxIncl, $this->decimals);
            $fields['L_PAYMENTREQUEST_0_QTY'.$index] = 1;

            $fields['PAYMENTREQUEST_0_ITEMAMT'] = Tools::ps_round($total, $this->decimals) + Tools::ps_round($shippingCostTaxIncl, $this->decimals);
            $fields['PAYMENTREQUEST_0_AMT'] = $total + Tools::ps_round($shippingCostTaxIncl, $this->decimals);
        } else {
            if ($currency->iso_code == 'HUF') {
                $fields['PAYMENTREQUEST_0_SHIPPINGAMT'] = round($shippingCostTaxIncl);
                $fields['PAYMENTREQUEST_0_ITEMAMT'] = Tools::ps_round($total, $this->decimals);
                $fields['PAYMENTREQUEST_0_AMT'] = sprintf('%.2f', ($total + $fields['PAYMENTREQUEST_0_SHIPPINGAMT']));
            } else {
                $fields['PAYMENTREQUEST_0_SHIPPINGAMT'] = sprintf('%.2f', $shippingCostTaxIncl);
                $fields['PAYMENTREQUEST_0_ITEMAMT'] = Tools::ps_round($total, $this->decimals);
                $fields['PAYMENTREQUEST_0_AMT'] = sprintf('%.2f', ($total + $fields['PAYMENTREQUEST_0_SHIPPINGAMT']));
            }
        }
    }

    /**
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function rightPaymentProcess()
    {
        $total = $this->getTotalPaid();

        // float problem with php, have to use the string cast.
        if ((isset($this->result['AMT']) && ((string) $this->result['AMT'] != (string) $total)) ||
            (isset($this->result['PAYMENTINFO_0_AMT']) && ((string) $this->result['PAYMENTINFO_0_AMT'] != (string) $total))) {
            return false;
        }

        return true;
    }

    /**
     * @return mixed
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function getTotalPaid()
    {
        $total = 0.00;

        foreach ($this->productList as $product) {
            $price = Tools::ps_round($product['price_wt'], $this->decimals);
            $quantity = Tools::ps_round($product['quantity'], $this->decimals);
            $total = Tools::ps_round($total + ($price * $quantity), $this->decimals);
        }

        if ($this->context->cart->gift == 1) {
            $total = Tools::ps_round($total + $this->getGiftWrappingPrice(), $this->decimals);
        }

        if (version_compare(_PS_VERSION_, '1.5', '<')) {
            $discounts = $this->context->cart->getDiscounts();
            $shipping_cost = $this->context->cart->getOrderShippingCost();
        } else {
            $discounts = $this->context->cart->getCartRules();
            $shipping_cost = $this->context->cart->getTotalShippingCost();
        }

        if (count($discounts) > 0) {
            foreach ($discounts as $product) {
                $price = -1 * Tools::ps_round($product['value_real'], $this->decimals);
                $total = Tools::ps_round($total + $price, $this->decimals);
            }
        }

        return Tools::ps_round($shipping_cost, $this->decimals) + $total;
    }

    /**
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function storeToken()
    {
        if (is_array($this->result) && isset($this->result['TOKEN'])) {
            $this->token = (string) $this->result['TOKEN'];
        }

    }

    // Store data for the next reloading page
    /**
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function storeCookieInfo()
    {
        $tab = array();

        foreach ($this->cookieKey as $key) {
            $tab[$key] = $this->{$key};
        }

        $this->context->cookie->{self::$cookieName} = serialize($tab);
    }

    /**
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function displayPaypalInContextCheckout()
    {
        $this->secure_key = $this->getSecureKey();
        $this->storeCookieInfo();
        echo $this->token;
        die;
    }

    /**
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function hasSucceedRequest()
    {
        if (is_array($this->result)) {
            foreach (array('ACK', 'PAYMENTINFO_0_ACK') as $key) {
                if (isset($this->result[$key]) && Tools::strtoupper($this->result[$key]) == 'SUCCESS') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return string
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function getSecureKey()
    {
        if (!count($this->productList)) {
            $this->initParameters();
        }

        $key = array();

        foreach ($this->productList as $product) {
            $idProduct = $product['id_product'];
            $idProductAttribute = $product['id_product_attribute'];
            $quantity = $product['quantity'];

            $key[] = $idProduct.$idProductAttribute.$quantity._COOKIE_KEY_;
        }

        return md5(serialize($key));
    }

    /**
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function isProductsListStillRight()
    {
        return $this->secure_key == $this->getSecureKey();
    }

    /**
     * @param $type
     *
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function setExpressCheckoutType($type)
    {
        if (in_array($type, $this->availableTypes)) {
            $this->type = $type;

            return true;
        }

        return false;
    }

    /**
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function redirectToAPI()
    {
        $this->secure_key = $this->getSecureKey();
        $this->storeCookieInfo();

        if ($this->useMobile()) {
            $url = '/cgi-bin/webscr?cmd=_express-checkout-mobile';
        } else {
            $url = '/websc&cmd=_express-checkout';
        }

        if (($this->method == 'SetExpressCheckout') && (Configuration::get('PAYPAL_COUNTRY_DEFAULT') == 1) && ($this->type == 'payment_cart')) {
            $url .= '&useraction=commit';
        }

        Tools::redirectLink('https://'.$this->getPayPalURL().$url.'&token='.urldecode($this->token));
        exit(0);
    }

    /**
     * @param      $customer
     * @param bool $redirect
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function redirectToCheckout($customer, $redirect = false)
    {
        $this->ready = true;
        $this->storeCookieInfo();

        $this->context->cookie->id_customer = (int) $customer->id;
        $this->context->cookie->customer_lastname = $customer->lastname;
        $this->context->cookie->customer_firstname = $customer->firstname;
        $this->context->cookie->passwd = $customer->passwd;
        $this->context->cookie->email = $customer->email;
        $this->context->cookie->is_guest = $customer->isGuest();
        $this->context->cookie->logged = 1;

        if (version_compare(_PS_VERSION_, '1.5', '<')) {
            Module::hookExec('authentication');
        } else {
            Hook::exec('authentication');
        }

        if ($redirect) {
            $link = $this->context->link->getPageLink('order.php', false, null, array('step' => '1'));
            Tools::redirectLink($link);
            exit(0);
        }
    }
}