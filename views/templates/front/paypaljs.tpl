{*
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
 *}
<script type="text/javascript">
	{if $use_paypal_in_context}
	window.paypalCheckoutReady = function () {
		paypal.checkout.setup("{$PayPal_in_context_checkout_merchant_id}", {
			environment: {if $PAYPAL_SANDBOX}"sandbox"{else}"production"{/if},
			click: function (event) {
				event.preventDefault();

				paypal.checkout.initXO();
				updateFormDatas();
				var str = '';
				if ($('#paypal_payment_form input[name="id_product"]').length > 0) {
					str += '&id_product=' + $('#paypal_payment_form input[name="id_product"]').val();
				}
				if ($('#paypal_payment_form input[name="quantity"]').length > 0) {
					str += '&quantity=' + $('#paypal_payment_form input[name="quantity"]').val();
				}
				if ($('#paypal_payment_form input[name="id_product_attribute"]').length > 0) {
					str += '&id_product_attribute=' + $('#paypal_payment_form input[name="id_product_attribute"]').val();
				}

				$.support.cors = true;

				$.ajax({
					url: "{$express_checkout_payment_link|escape:'javascript':'UTF-8'}",
					type: "GET",
					data: {
						ajax: 1,
						onlytoken: 1,
						express_checkout: $('input[name="express_checkout"]').val(),
						current_shop_url: $('input[name="current_shop_url"]').val(),
						bn: $('input[name="bn"]').val() + str,
					},
					async: true,
					crossDomain: true,
					success: function (token) {
						var url = paypal.checkout.urlPrefix + token;

						paypal.checkout.startFlow(url);
					},
					error: function (responseData, textStatus, errorThrown) {
						alert("Error in ajax post" + responseData.statusText);

						paypal.checkout.closeFlow();
					}
				});
			},
			button: ['paypal_process_payment', 'payment_paypal_express_checkout']
		});
	};
	{/if}

	function updateFormDatas() {
		var nb = $('#quantity_wanted').val();
		var id = $('#idCombination').val();

		$('#paypal_payment_form input[name=quantity]').val(nb);
		$('#paypal_payment_form input[name=id_product_attribute]').val(id);
	}

	$(document).ready(function () {

		if ($('#in_context_checkout_enabled').val() != 1) {
			$('#payment_paypal_express_checkout').click(function () {
				$('#paypal_payment_form').submit();
				return false;
			});
		}


		$('body').on('submit', "#paypal_payment_form", function () {
			updateFormDatas();
		});

		function displayExpressCheckoutShortcut() {
			var id_product = $('input[name="id_product"]').val();
			var id_product_attribute = $('input[name="id_product_attribute"]').val();
			$.ajax({
				type: "GET",
				url: '{Context::getContext()->link->getModuleLink('paypal', 'expresscheckoutajax', array(), Tools::usingSecureMode())|escape:'javascript':'UTF-8'}',
				data: {
					get_qty: "1",
					id_product: id_product,
					id_product_attribute: id_product_attribute,
				},
				cache: false,
				success: function (result) {
					if (result == '1') {
						$('#container_express_checkout').slideDown();
					} else {
						$('#container_express_checkout').slideUp();
					}
					return true;
				}
			});
		}

		$('select[name^="group_"]').change(function () {
			setTimeout(function () {
				displayExpressCheckoutShortcut()
			}, 500);
		});

		$('.color_pick').click(function () {
			setTimeout(function () {
				displayExpressCheckoutShortcut()
			}, 500);
		});

		if ($('body#product').length > 0)
			setTimeout(function () {
				displayExpressCheckoutShortcut()
			}, 500);


		{if isset($paypal_authorization)}
		/* 1.5 One page checkout*/
		var qty = $('.qty-field.cart_quantity_input').val();
		$('.qty-field.cart_quantity_input').after(qty);
		$('.qty-field.cart_quantity_input, .cart_total_bar, .cart_quantity_delete, #cart_voucher *').remove();

		var br = $('.cart > a').prev();
		br.prev().remove();
		br.remove();
		$('.cart.ui-content > a').remove();

		var gift_fieldset = $('#gift_div').prev();
		var gift_title = gift_fieldset.prev();
		$('#gift_div, #gift_mobile_div').remove();
		gift_fieldset.remove();
		gift_title.remove();


		{/if}
		{if isset($paypal_confirmation)}


		$('#container_express_checkout').hide();
		if (jquery_version[0] >= 1 && jquery_version[1] >= 7) {
			$('body').on('click', "#cgv", function () {
				if ($('#cgv:checked').length != 0)
					$(location).attr('href', '{$paypal_confirmation|escape:'javascript':'UTF-8'}');
			});
		} else {
			$('#cgv').live('click', function () {
				if ($('#cgv:checked').length != 0)
					$(location).attr('href', '{$paypal_confirmation|escape:'javascript':'UTF-8'}');
			});

			/* old jQuery compatibility */
			$('#cgv').click(function () {
				if ($('#cgv:checked').length != 0)
					$(location).attr('href', '{$paypal_confirmation|escape:'javascript':'UTF-8'}');
			});
		}


		{elseif isset($paypal_order_opc)}

		var jquery_version = $.fn.jquery.split('.');
		if (jquery_version[0] >= 1 && jquery_version[1] >= 7) {
			$('body').on('click', '#cgv', function () {
				if ($('#cgv:checked').length != 0) {
					checkOrder();
				}
			});
		} else {
			$('#cgv').live('click', function () {
				if ($('#cgv:checked').length != 0) {
					checkOrder();
				}
			});

			/* old jQuery compatibility */
			$('#cgv').click(function () {
				if ($('#cgv:checked').length != 0) {
					checkOrder();
				}
			});
		}


		{/if}

		var confirmTimer = false;

		if ($('form[target="hss_iframe"]').length == 0) {
			if ($('select[name^="group_"]').length > 0)
				displayExpressCheckoutShortcut();
			return false;
		} else {
			checkOrder();
		}

		function checkOrder() {
			if (confirmTimer == false)
				confirmTimer = setInterval(getOrdersCount, 1000);
		}

		{if isset($id_cart)}
		function getOrdersCount() {
			$.get(
				'{Context::getContext()->link->getModuleLink('paypal', 'confirm')|escape:'javascript':'UTF-8'}',
				{
					id_cart: '{$id_cart|intval}'
				},
				function (data) {
					if ((typeof(data) != 'undefined') && (data > 0)) {
						clearInterval(confirmTimer);
						window.location.replace('{Context::getContext()->link->getModuleLink('paypal', 'submit', array(), Tools::usingSecureMode())|escape:'javascript':'UTF-8'}?id_cart={$id_cart|intval}');
						$('p.payment_module, p.cart_navigation').hide();
					}
				}
			);
		}
		{/if}
	});
</script>