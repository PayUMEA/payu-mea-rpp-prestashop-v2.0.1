<?php
/**
 * Prestashop PayU Plugin
 *
 * @category   Modules
 * @package    PayU
 * @copyright  Copyright (c) 2015 Netcraft Devops (Pty) Limited
 *             http://www.netcraft-devops.com
 * @author     Kenneth Onah <kenneth@netcraft-devops.com>
 */

//define('PS_OS_AWAITING_PAYMENT', 21);

require_once(dirname(__FILE__).'/../../payu.php');

class PayuPaymentModuleFrontController extends ModuleFrontController
{
	/*
	 * @see FrontController::postProcess()
	*/
	public function postProcess()
	{
		$payuModule = $this->module;
		$context = $payuModule->getCurrentContext();
		
		if(!empty($context) && !empty($context)) {
			$cart = $context->cart;
			if (!$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice|| !$this->module->active)
				Tools::redirect('index.php?controller=order&step=1');
			
			// Check that this payment option is still available in case the customer changed 
			// his address just before the end of the checkout process
			$authorized = false;
			foreach (Module::getPaymentModules() as $module) {
				if ($module['name'] == 'payu') {
					$authorized = true;
					break;
				}
			}
			
			if (!$authorized) {
				Tools::redirect('index.php?controller=order&step=1');
			}
			$customer = new Customer($cart->id_customer);
			if (!Validate::isLoadedObject($customer))
				Tools::redirect('index.php?controller=order&step=1');
			
			$currency = $context->currency;
			$total = (float)$cart->getOrderTotal(true, Cart::BOTH);
			$payuModule->validateOrder(
				$cart->id,
				Configuration::get('PS_OS_AWAITING_PAYMENT'),
				$total,
				$payuModule->displayName, null, null,
				(int)$currency->id, false, $customer->secure_key
			);
			$payuReference = $payuModule->doSetTransaction($cart);

			if(null !== $payuReference && is_numeric($payuReference)) {

				$order = new Order($payuModule->currentOrder);
				$msg = new Message();
				$msg->message = 'Redirecting to PayU, PayU reference: ' . $payuReference;
				$msg->id_cart = (int)$cart->id;
				$msg->id_customer = intval($order->id_customer);
				$msg->id_order = intval($order->id);
				$msg->private = 1;
				$msg->add();

				$redirectUrl = $payuModule->getTransactionServer() . '/rpp.do?PayUReference=' . $payuReference;

				Tools::redirect($redirectUrl);
				exit;
			} else {
				Tools::redirect('index.php?controller=order&step=1');
			}
		}
	}
}
