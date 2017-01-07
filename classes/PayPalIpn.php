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

namespace PayPalModule;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Instant payment notification class.
 * (wait for PayPal payment confirmation, then validate order)
 */
class PayPalIpn extends \PayPal
{
    /**
     * Amount of decimals for rounding
     *
     * @var int $decimals
     */
    public $decimals;

    /**
     * @param array $result
     *
     * @return array
     */
    public function getIPNTransactionDetails($result)
    {
        if (is_array($result) || (strcmp(trim($result), "VERIFIED") === false)) {
            $transactionId = pSQL($result['txn_id']);

            return [
                'id_transaction' => $transactionId,
                'transaction_id' => $transactionId,
                'id_invoice' => $result['invoice'],
                'currency' => pSQL($result['mc_currency']),
                'total_paid' => (float) $result['mc_gross'],
                'shipping' => (float) $result['mc_shipping'],
                'payment_date' => pSQL($result['payment_date']),
                'payment_status' => pSQL($result['payment_status']),
            ];
        } else {
            $transactionId = pSQL(\Tools::getValue('txn_id'));

            return [
                'id_transaction' => $transactionId,
                'transaction_id' => $transactionId,
                'id_invoice' => pSQL(\Tools::getValue('invoice')),
                'currency' => pSQL(\Tools::getValue('mc_currency')),
                'total_paid' => (float) \Tools::getValue('mc_gross'),
                'shipping' => (float) \Tools::getValue('mc_shipping'),
                'payment_date' => pSQL(\Tools::getValue('payment_date')),
                'payment_status' => pSQL(\Tools::getValue('payment_status')),
            ];
        }
    }

    /**
     * @param array $custom
     */
    public function confirmOrder($custom)
    {
        $result = $this->getResult();

        $paymentStatus = \Tools::getValue('payment_status');
        $mcGross = \Tools::getValue('mc_gross');
        $txnId = \Tools::getValue('txn_id');

        $idOrder = (int) PayPalOrder::getIdOrderByTransactionId($txnId);

        if ($idOrder != 0) {
            \Context::getContext()->cart = new \Cart((int) $idOrder);
        } elseif (isset($custom['id_cart'])) {
            \Context::getContext()->cart = new \Cart((int) $custom['id_cart']);
        }

        $address = new \Address((int) \Context::getContext()->cart->id_address_invoice);
        \Context::getContext()->country = new \Country((int) $address->id_country);
        \Context::getContext()->customer = new \Customer((int) \Context::getContext()->cart->id_customer);
        \Context::getContext()->language = new \Language((int) \Context::getContext()->cart->id_lang);
        \Context::getContext()->currency = new \Currency((int) \Context::getContext()->cart->id_currency);

        if (isset(\Context::getContext()->cart->id_shop)) {
            \Context::getContext()->shop = new \Shop(\Context::getContext()->cart->id_shop);
        }

        if (strcmp(trim($result), "VERIFIED") === false) {
            $details = $this->getIPNTransactionDetails($result);

            if ($idOrder != 0) {
                $history = new \OrderHistory();
                $history->id_order = (int) $idOrder;

                PayPalOrder::updateOrder($idOrder, $details);
                $history->changeIdOrderState((int) \Configuration::get('PS_OS_ERROR'), $history->id_order);

                $history->addWithemail();
                $history->save();
            }
        } elseif (strcmp(trim($result), "VERIFIED") === 0) {
            $details = $this->getIPNTransactionDetails($result);

            if (version_compare(_PS_VERSION_, '1.5', '<')) {
                $shop = null;
            } else {
                $idShop = \Context::getContext()->shop->id;
                $shop = new \Shop($idShop);
            }

            if ($idOrder != 0) {
                $order = new \Order((int) $idOrder);
                $values = $this->checkPayment($paymentStatus, $mcGross, false);

                if ((int) $order->current_state == (int) $values['payment_type']) {
                    return;
                }

                $history = new \OrderHistory();
                $history->id_order = (int) $idOrder;

                PayPalOrder::updateOrder($idOrder, $details);
                $history->changeIdOrderState($values['payment_type'], $history->id_order);

                $history->addWithemail();
                $history->save();
            } else {
                $values = $this->checkPayment($paymentStatus, $mcGross, true);
                $customer = new \Customer((int) \Context::getContext()->cart->id_customer);
                $this->validateOrder(\Context::getContext()->cart->id, $values['payment_type'], $values['total_price'], $this->displayName, $values['message'], $details, \Context::getContext()->cart->id_currency, false, $customer->secure_key, $shop);
            }
        }
    }

    /**
     * @param string $paymentStatus
     * @param float  $mcGrossNotRounded
     * @param bool   $newOrder
     *
     * @return array
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function checkPayment($paymentStatus, $mcGrossNotRounded, $newOrder)
    {
        $currencyDecimals = is_array(\Context::getContext()->currency) ? (int) \Context::getContext()->currency['decimals'] : (int) \Context::getContext()->currency->decimals;
        $this->decimals = $currencyDecimals * _PS_PRICE_DISPLAY_PRECISION_;

        $mcGross = \Tools::ps_round($mcGrossNotRounded, $this->decimals);

        $cartDetails = \Context::getContext()->cart->getSummaryDetails(null, true);
        $cartHash = sha1(serialize(\Context::getContext()->cart->nbProducts()));
        $custom = json_decode(\Tools::getValue('custom'), true);

        $shipping = $cartDetails['total_shipping_tax_exc'];
        $subtotal = $cartDetails['total_price_without_tax'] - $cartDetails['total_shipping_tax_exc'];
        $tax = $cartDetails['total_tax'];

        $totalPrice = \Tools::ps_round($shipping + $subtotal + $tax, $this->decimals);

        if (($newOrder == true) && (bccomp($mcGross, $totalPrice, 2) !== 0)) {
            $paymentType = (int) \Configuration::get('PS_OS_ERROR');
            $message = $this->l('Price paid on paypal is not the same that on Thirty Bees.').'<br />';
        } elseif (($newOrder == true) && ($custom['hash'] != $cartHash)) {
            $paymentType = (int) \Configuration::get('PS_OS_ERROR');
            $message = $this->l('Cart changed, please retry.').'<br />';
        } else {
            return $this->getDetails($paymentStatus) + [
                'payment_status' => $paymentStatus,
                'total_price' => $totalPrice,
                ];
        }

        return [
            'message' => $message,
            'payment_type' => $paymentType,
            'payment_status' => $paymentStatus,
            'total_price' => $totalPrice,
        ];
    }

    /**
     * @param string $paymentStatus
     *
     * @return array
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function getDetails($paymentStatus)
    {
        if ((bool) \Configuration::get('PAYPAL_CAPTURE')) {
            $paymentType = (int) \Configuration::get('PS_OS_WS_PAYMENT');
            $message = $this->l('Pending payment capture.').'<br />';
        } else {
            if (strcmp($paymentStatus, 'Completed') === 0) {
                $paymentType = (int) \Configuration::get('PS_OS_PAYMENT');
                $message = $this->l('Payment accepted.').'<br />';
            } elseif (strcmp($paymentStatus, 'Pending') === 0) {
                $paymentType = (int) \Configuration::get('PS_OS_PAYPAL');
                $message = $this->l('Pending payment confirmation.').'<br />';
            } else {
                $paymentType = (int) \Configuration::get('PS_OS_ERROR');
                $message = $this->l('Cart changed, please retry.').'<br />';
            }
        }

        return [
            'message' => $message,
            'payment_type' => (int) $paymentType,
        ];
    }

    /**
     * @return mixed
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function getResult()
    {
        if (\Configuration::get('PAYPAL_SANDBOX')) {
            $actionUrl = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_notify-validate';
        } else {
            $actionUrl = 'https://www.paypal.com/cgi-bin/webscr?cmd=_notify-validate';
        }

        $request = '';
        foreach ($_POST as $key => $value) {
            $value = urlencode(\Tools::stripslashes($value));
            $request .= "&{$key}={$value}";
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_URL, $actionUrl.$request);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);

        $content = curl_exec($curl);
        curl_close($curl);

        return $content;
    }
}
