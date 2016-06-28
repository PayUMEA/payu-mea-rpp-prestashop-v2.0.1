<?php
/**
 * 2007-2014 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 *  @author Kenneth Onah <kenneth@netcraft-devops.com>
 *  @copyright  2015 NetCraft DevOps
 *  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  Property of NetCraft DevOps
 */

class PayuValidationModuleFrontController extends ModuleFrontController
{
	/*
	 * @see FrontController::postProcess()
	*/
	public function postProcess()
	{
		$reference = Tools::getValue('PayUReference');
		$action = Tools::getValue('action');
		$payuModule = $this->module;

		if(!empty($action) && $action == 'cancel') {
			$reference = Tools::getValue('payUReference');
			$returnData = $payuModule->doGetTransaction($reference);
			if(empty($returnData['return']))
				return;

			$result = $returnData['return'];
			$order =  new Order($result['merchantReference']);

			// cancelled payment
			$message = "PayU Reference: " . $result['payUReference']
				.", Point Of Failure: " . $result['pointOfFailure']
				.", Result Code: " . $result['resultCode']
				.", Result Message: " . $result['resultMessage'];
			$order->setCurrentState(Configuration::get('PS_OS_CANCELED'));
			$order->save();

			$msg = new Message();
			$msg->message = $message;
			$msg->id_cart = (int)$order->id_cart;
			$msg->id_customer = intval($order->id_customer);
			$msg->id_order = intval($order->id);
			$msg->private = 1;
			$msg->add();

			$this->display_column_left = true;
			$this->context->smarty->assign(array(
				'hide_left_column' => $this->display_column_left,
				'reason' => $result['displayMessage'],
				'state' => $result['transactionState'],
				'payu_ref' => $result['payUReference'],
			));
			return $this->setTemplate('cancel.tpl');
		} elseif((isset($reference) && $reference)) {
			$returnData = $payuModule->doGetTransaction($reference);
			$order =  new Order($returnData['return']['merchantReference']);
			$currency = new Currency($order->id_currency);

			if(isset($returnData['return']) && is_array($returnData['return'])) {
				$result = $returnData['return'];
				$transaction_state = !empty($result['transactionState']) ? $result['transactionState'] : '';

				if(!empty($result['successful']) && $result['successful'] === true) {
					//Successfull Payment
					$total_to_pay = Tools::ps_round($order->total_paid, 2);
					$amount_paid = Tools::ps_round(Tools::convertPrice($result['paymentMethodsUsed']['amountInCents'] / 100, $currency->id, false), 2);

					if($transaction_state === 'SUCCESSFUL')
					{
						if($total_to_pay == $amount_paid)
						{
							$total = $total_to_pay;
						}
						else
						{
							$total = $amount_paid;
						}
						$message = "PayU Reference: " . $result['payUReference'];
						$message .= ", Gateway Reference: " . $result['paymentMethodsUsed']['gatewayReference'];
						if(isset($result['paymentMethodsUsed'])) {
							if(is_array($result['paymentMethodsUsed'])) {
								$message .= ", Payment Method Details: ";
								foreach($result['paymentMethodsUsed'] as $key => $value) {
									$message .= $key.":".$value.", ";
								}
							}
						}
						//$order->addOrderPayment($total, null, $result['payUReference'], $currency);
						$order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
						$order->save();

						$msg = new Message();
						$msg->message = $message;
						$msg->id_cart = (int)$order->id_cart;
						$msg->id_customer = intval($order->id_customer);
						$msg->id_order = intval($order->id);
						$msg->private = 1;
						$msg->add();

						$this->display_column_left = true;
						$this->context->smarty->assign(array(
							'hide_left_column' => $this->display_column_left,
							'total_paid' => $total,
							'cardInfo' => $result['paymentMethodsUsed']['information'],
							'name_on_card' => $result['paymentMethodsUsed']['nameOnCard'],
							'card_number' => $result['paymentMethodsUsed']['cardNumber'],
							'state' => $result['transactionState'],
							'payu_ref' => $result['payUReference'],
						));
						return $this->setTemplate('confirmation.tpl');
					} 
				} else {
					// Failed or payment timeout
					$message = "PayU Reference: " . $result['payUReference']
						.", Point Of Failure: " . $result['pointOfFailure']
						.", Result Code: " . $result['resultCode']
						.", Result Message: " . $result['resultMessage'];
					$order->setCurrentState(Configuration::get('PS_OS_ERROR'));
					$order->save();

					$msg = new Message();
					$msg->message = $message;
					$msg->id_cart = (int)$order->id_cart;
					$msg->id_customer = intval($order->id_customer);
					$msg->id_order = intval($order->id);
					$msg->private = 1;
					$msg->add();

					$this->context->smarty->assign(array(
						'hide_left_column' => $this->display_column_left,
						'total_paid' => Tools::ps_round(Tools::convertPrice($result['paymentMethodsUsed']['amountInCents'] / 100, $currency->id, false), 2),
						'cardInfo' => $result['paymentMethodsUsed']['information'],
						'name_on_card' => $result['paymentMethodsUsed']['nameOnCard'],
						'card_number' => $result['paymentMethodsUsed']['cardNumber'],
						'state' => $result['transactionState'],
						'payu_ref' => $result['payUReference'],
					));
				}
			}
		} else {
			// IPN callback
			$postData  = file_get_contents("php://input");
			$xml = simplexml_load_string($postData);
			$returnData = $payuModule->parseXMLToArray($xml);

			if(false === $returnData)
				return;

			$order_id = $returnData['MerchantReference'];
			if(!empty($order_id) && is_numeric($order_id)) {
				$payUReference = $returnData['PayUReference'];
				$order =  new Order($order_id);
			}
			$returnData = $payuModule->doGetTransaction($payUReference);
			$result = !empty($returnData['return']) ? $returnData['return'] : array();
			if(empty($result))
				return;
			
			$amountToPay = $result['basket']['amountInCents'] / 100;
			$amountPaid = $result['paymentMethodsUsed']['amountInCents'] / 100;

			if(!empty($result['successful']) && $result['successful'] === true) {
				
				$message = "";
				$message .= "----------PAYU IPN RECEIVED----------";
				$message .= "Amount to Pay: ".$amountToPay."\r\n";
				$message .= "Amount Paid: ".$amountPaid."\r\n";
				$message .= "Merchant Reference : ".$result['merchantReference']."\r\n";
				$message .= "PayU Reference: ".$result['payUReference']."\r\n\r\n";
				$message .= "PayU Payment Status: ". $result["transactionState"]."\r\n\r\n";

				$order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
				$order->save();
				
				$msg = new Message();
				$msg->message = $message;
				$msg->id_cart = (int)$order->id_cart;
				$msg->id_customer = intval($order->id_customer);
				$msg->id_order = intval($order->id);
				$msg->private = 1;
				$msg->add();
				exit;
			} else {
				$message = "PayU Reference: " . $result['payUReference']
					.", Point Of Failure: " . $result['pointOfFailure']
					.", Result Code: " . $result['resultCode']
					.", Result Message: " . $result['resultMessage'];
				$order->setCurrentState(Configuration::get('PS_OS_ERROR'));
				$order->save();

				$msg = new Message();
				$msg->message = $message;
				$msg->id_cart = (int)$order->id_cart;
				$msg->id_customer = intval($order->id_customer);
				$msg->id_order = intval($order->id);
				$msg->private = 1;
				$msg->add();
				exit;
			}
		}
		return $this->setTemplate('failed.tpl');
	}
}
