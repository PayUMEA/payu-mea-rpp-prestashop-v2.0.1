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
 
if(!defined('_PS_VERSION_'))
	exit;

class payu extends PaymentModule
{
	const API_VERSION = 'ONE_ZERO';
	const NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

	private	$_html = '';
	private $_postErrors = array();
	private $soapClient = null;

	public function __construct()
	{
		$this->name = 'payu';
		$this->tab = 'payments_gateways';
		$this->version = '2.0.1';
		$this->author = 'Kenneth Onah';
		$this->currencies = true;
		$this->currencies_mode = 'radio';

		if (!extension_loaded('soap'))
			$this->warning = $this->l('SOAP extension must be enabled on your server to use this module.');
		
		$this->bootstrap = true;
		parent::__construct();

		$this->secure_key = Tools::encrypt($this->name);
		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
		$this->displayName = $this->l('PayU secure payments');
		$this->description = $this->l('Secure payments by PayU MEA');
		$this->confirmUninstall = $this->l('Are you sure you want to delete your details ?');
		if (!count(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('No currency has been set for this module.');
	}

	public function install()
	{
		if (!parent::install()
		OR !$this->installCurrency()
		OR !$this->installOrderState()
		OR !Configuration::updateValue('PAYU_SAFE_KEY', '{CE62CE80-0EFD-4035-87C1-8824C5C46E7F}')
		OR !Configuration::updateValue('PAYU_API_USERNAME', '100032')
		OR !Configuration::updateValue('PAYU_API_PASSWORD', 'PypWWegU')
		OR !Configuration::updateValue('PAYU_SHOW_BUDGET', true)
		OR !Configuration::updateValue('PAYU_PAYMENT_METHODS', 'CREDITCARD')
		OR !Configuration::updateValue('PAYU_CURRENCY', 'ZAR')
		OR !Configuration::updateValue('PAYU_SERVER_MODE', 'Sandbox')
		OR !Configuration::updateValue('PAYU_TRANSACTION_TYPE', 'PAYMENT')
		OR !$this->registerHook('payment'))
			return false;
		return true;
	}

	public function uninstall()
	{
		if (!Configuration::deleteByName('PAYU_SAFE_KEY')
		OR !Configuration::deleteByName('PAYU_API_USERNAME')
		OR !Configuration::deleteByName('PAYU_API_PASSWORD')
		OR !Configuration::deleteByName('PAYU_SHOW_BUGET')
		OR !Configuration::deleteByName('PAYU_SERVER_MODE')
		OR !Configuration::deleteByName('PAYU_PAYMENT_METHODS')
		OR !Configuration::deleteByName('PAYU_CURRENCY')
		OR !Configuration::deleteByName('PAYU_TRANSACTION_TYPE')
		OR !Configuration::deleteByName('PS_OS_AWAITING_PAYMENT')
		OR !$this->deleteOrderState()
		OR !$this->unregisterHook('payment')
		OR !parent::uninstall())
			return false;
		return true;
	}

	public function installCurrency()
	{
		//Check if rands are installed and install and refresh if not
		$currency = new Currency();
		$currency_rand_id  = $currency->getIdByIsoCode('ZAR');

		if(is_null($currency_rand_id)){
			$currency->name = "South African Rand";
			$currency->iso_code = "ZAR";
			$currency->sign = "R";
			$currency->format = 1;
			$currency->blank = 1;
			$currency->decimals = 1;
			$currency->deleted = 0;
			// set it to an arbitrary value, also you can update currency rates to set correct value
			$currency->conversion_rate = 0.45; 
			$currency->add();
			$currency->refreshCurrencies();
		}

		return true;
	}

	protected function installOrderState()
	{
		$data = array(
			'invoice' => '0',
			'send_email' => '0',
			'module_name' => $this->name,
			'color' => '#FF6600',
			'unremovable' => '1',
			'hidden' => '0',
			'logable' => '0',
			'delivery' => '0',
			'shipped' => '0',
			'paid' => '0',
			'pdf_delivery' => '0',
			'deleted' => '0',
		);
		$db = Db::getInstance(_PS_USE_SQL_SLAVE_);
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		
		if(!(Configuration::get('PS_OS_AWAITING_PAYMENT') > 0)) {
			if($db->insert('order_state', $data)) {
				$id = $db->Insert_ID();
				$data = array(
					'id_order_state' => $id,
					'id_lang' => $lang->id,
					'name' => 'Awaiting payment confirmation',
					'template' => '',
				);
				if($db->insert('order_state_lang', $data)) {
					Configuration::updateValue('PS_OS_AWAITING_PAYMENT', (int)$id);
					return true;
				} 
			}
			return false;
		}
	}
	
	protected function deleteOrderState() 
	{
		$id_order_state = (int)Configuration::get('PS_OS_AWAITING_PAYMENT');
		$db = Db::getInstance(_PS_USE_SQL_SLAVE_);
		if($db->delete('order_state', "id_order_state = {$id_order_state}", 0) 
				&& $db->delete('order_state_lang', "id_order_state = {$id_order_state}", 0)) {
			return true;
		}
		return false;
	}
	
	protected function _displayPayU()
	{
		return $this->display(__FILE__, 'infos.tpl');
	}
	
	protected function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			if (!Tools::getValue('PAYU_SAFE_KEY'))
				$this->_postErrors[] = $this->l('Safe key is required.');
			elseif (!Tools::getValue('PAYU_API_USERNAME'))
				$this->_postErrors[] = $this->l('API username is required.');
			elseif (!Tools::getValue('PAYU_API_PASSWORD'))
				$this->_postErrors[] = $this->l('API password is required.');
			elseif (!Tools::getValue('PAYU_PAYMENT_METHODS'))
				$this->_postErrors[] = $this->l('Payment method is required.');
			elseif (!Tools::getValue('PAYU_CURRENCY'))
				$this->_postErrors[] = $this->l('Currency is required.');
			elseif(!Tools::getValue('PAYU_TRANSACTION_TYPE'))
				$this->_postErrors[] = $this->l('Transaction type is required');
		}
	}
	
	protected function _postProcess()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			Configuration::updateValue('PAYU_SAFE_KEY', Tools::getValue('PAYU_SAFE_KEY'));
			Configuration::updateValue('PAYU_API_USERNAME', Tools::getValue('PAYU_API_USERNAME'));
			Configuration::updateValue('PAYU_API_PASSWORD', Tools::getValue('PAYU_API_PASSWORD'));
			Configuration::updateValue('PAYU_SHOW_BUDGET', Tools::getValue('PAYU_SHOW_BUDGET'));
			Configuration::updateValue('PAYU_PAYMENT_METHODS', @serialize(Tools::getValue('PAYU_PAYMENT_METHODS')));
			Configuration::updateValue('PAYU_CURRENCY', Tools::getValue('PAYU_CURRENCY'));
			Configuration::updateValue('PAYU_SERVER_MODE', Tools::getValue('PAYU_SERVER_MODE'));
			Configuration::updateValue('PAYU_TRANSACTION_TYPE', Tools::getValue('PAYU_TRANSACTION_TYPE'));
		}
		$this->_html .= $this->displayConfirmation($this->l('Settings updated'));
	}
	
	public function getContent() 
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			$this->_postValidation();
			if (!count($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->_html .= $this->displayError($err);
		}
		else
			$this->_html .= '<br />';
		
		$this->_html .= $this->_displayPayU();
		$this->_html .= $this->renderForm();
		
		return $this->_html;
	}
	
	public function renderForm()
	{
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Settings'),
					'icon' => 'icon-cogs'
				),
				'input' => array(
					array(
						'type' => 'text',
						'label' => $this->l('Safe key'),
						'name' => 'PAYU_SAFE_KEY',
						'required' => true,
						'class' => 'col-sm-8',
					),
					array(
						'type' => 'text',
						'label' => $this->l('API username'),
						'name' => 'PAYU_API_USERNAME',
						'required' => true,
						'class' => 'col-sm-8',
					),
					array(
						'type' => 'text',
						'label' => $this->l('API password'),
						'name' => 'PAYU_API_PASSWORD',
						'required' => true,
						'class' => 'col-sm-8',
					),
					array(
						'type' => 'switch',
						'label' => $this->l('Allow budget payment'),
						'desc'	=> $this->l('Applicable to credit card payments'),
						'name' => 'PAYU_SHOW_BUDGET',
						'is_bool' => true,
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => 1,
								'label' => $this->l('Yes')
							),
							array(
								'id' => 'active_off',
								'value' => 0,
								'label' => $this->l('No')
							)
						),
					),
					array(
						'type' => 'select',
						'cols' => 8,
						'label' => $this->l('Payment methods'),
						'name' => 'PAYU_PAYMENT_METHODS[]',
						'desc'    => $this->l('Please confirm with PayU before choosing payment methods.'),
						'required' => true,
						'multiple' => true,
						'options' => array(
							'query' => $this->getPaymentMethods(),
							'id' => 'id_option',
							'name' => 'name',
						),
					),
					array(
						'type' => 'select',
						'label' => $this->l('Transaction type'),
						'name' => 'PAYU_TRANSACTION_TYPE',
						'required' => true,
						'desc' => $this->l('This determines how payment processing will be handled on PayU'),
						'options' => array(
							'default' => array(
								'value' => 0, 
								'label' => $this->l('Choose transaction type')
							),
							'query' => array(
								array(
									'id_option' => 'RESERVE',
									'name' => $this->l('RESERVE'),
								),
								array(
									'id_option' => 'PAYMENT',
									'name' => $this->l('PAYMENT'),
								)
							),
							'id' => 'id_option',
							'name' => 'name',
						),
					),
					array(
						'type' 		=> 'select',
						'label' 	=> $this->l('Currency'),
						'name' 		=> 'PAYU_CURRENCY',
						'required' 	=> true,
						'options' 	=> array(
							'default' => array(
								'value' => 0,
								'label' => $this->l('Choose currency')
							),
							'query' => array(
								array(
									'id_option' => 'NGN',
									'name' => $this->l('Nigerian Naira'),
								),
								array(
									'id_option' => 'ZAR',
									'name' => $this->l('South African Rand'),
								),
							),
							'id' => 'id_option',
							'name' => 'name',
						),
					),
					array(
						'type' => 'radio',
						'label' => $this->l('Transaction server'),
						'name' => 'PAYU_SERVER_MODE',
						'desc' => $this->l('Remember to switch to Live Server before accepting real transactions.'),
						'class'		=> 't',
						'is_bool'	=> true,
						'values'	=> array(
							array(
								'id'	=> 'active_on',
								'value'	=> 'Live',
								'label' => 'Live Server',
							),
							array (
								'id'	=> 'active_off',
								'value'	=> 'Sandbox',
								'label' => 'Sandbox (Testing Server)',
							),
						),
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
				)
			),
		);
		
		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = array();
		$helper->id = (int)Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
				'fields_value' => $this->getConfigFieldsValues(),
				'languages' => $this->context->controller->getLanguages(),
				'id_language' => $this->context->language->id
		);
		
		return $helper->generateForm(array($fields_form));
	}	

	public function hookPayment($params)
	{
		if (!$this->active)
			return ;

		if($params['cart']->getOrderTotal(true, Cart::BOTH) < 50)
			return;
		
		$this->smarty->assign(array(
			'this_path_pu' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/',
		));

		return $this->display(__FILE__, 'payment.tpl');
	}

	public function getTransactionServer()
	{
		if(Configuration::get('PAYU_SERVER_MODE') == 'Sandbox') {
			$baseUri = 'https://staging.payu.co.za';
		} else {
			$baseUri = 'https://secure.payu.co.za';
		}
		return $baseUri;
	}

	private function getSoapHeaderXml()
	{
		$headerXml  = '<wsse:Security SOAP-ENV:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">';
		$headerXml .= '<wsse:UsernameToken wsu:Id="UsernameToken-9" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">';
		$headerXml .= '<wsse:Username>'. Configuration::get('PAYU_API_USERNAME') .'</wsse:Username>';
		$headerXml .= '<wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">'. Configuration::get('PAYU_API_PASSWORD') .'</wsse:Password>';
		$headerXml .= '</wsse:UsernameToken>';
		$headerXml .= '</wsse:Security>';

		return $headerXml;
	}

	public function doGetTransaction($payUReference)
	{
		$getDataArray = array();
		$getDataArray['Api'] = self::API_VERSION;
		$getDataArray['Safekey'] = Configuration::get('PAYU_SAFE_KEY');
		$getDataArray['AdditionalInformation']['payUReference'] = $payUReference;

		$soapCallResult = $this->getSoapSingleton()->getTransaction($getDataArray);
		return json_decode(json_encode($soapCallResult), true);
	}

	private function setSoapTransaction(array $setTransactionData)
	{
		$soapCallResult = $this->getSoapSingleton()->setTransaction($setTransactionData);

		return json_decode(json_encode($soapCallResult), true);
	}

	private function getSoapSingleton()
	{
		if(is_null($this->soapClient)) {
			$headerXml = $this->getSoapHeaderXml();
			$baseUrl = $this->getTransactionServer();
			$soapWsdlUrl = $baseUrl.'/service/PayUAPI?wsdl';

			$headerbody = new \SoapVar($headerXml, XSD_ANYXML, null, null, null);
			$soapHeader = new \SOAPHeader(self::NS, 'Security', $headerbody, true);

			$soap_client = new \SoapClient($soapWsdlUrl, array('trace' => 1, 'exception' => 0));
			$soap_client->__setSoapHeaders($soapHeader);
			
			$this->soapClient = $soap_client;
		}

		return $this->soapClient;
	}
	
	public function getConfigFieldsValues()
	{
		$config = array(
			'PAYU_SAFE_KEY' => Tools::getValue('PAYU_SAFE_KEY', Configuration::get('PAYU_SAFE_KEY')),
			'PAYU_API_USERNAME' => Tools::getValue('PAYU_API_USERNAME', Configuration::get('PAYU_API_USERNAME')),
			'PAYU_API_PASSWORD' => Tools::getValue('PAYU_API_PASSWORD', Configuration::get('PAYU_API_PASSWORD')),
			'PAYU_SHOW_BUDGET'	=> Tools::getValue('PAYU_SHOW_BUDGET', Configuration::get('PAYU_SHOW_BUDGET')),
			'PAYU_CURRENCY' => Tools::getValue('PAYU_CURRENCY', Configuration::get('PAYU_CURRENCY')),
			'PAYU_SERVER_MODE' => Tools::getValue('PAYU_SERVER_MODE', Configuration::get('PAYU_SERVER_MODE')),
			'PAYU_TRANSACTION_TYPE' => Tools::getValue('PAYU_TRANSACTION_TYPE', Configuration::get('PAYU_TRANSACTION_TYPE')),
		);
		
		$paymentMethods = Tools::getValue('PAYU_PAYMENT_METHODS', @unserialize(Configuration::get('PAYU_PAYMENT_METHODS')));
		if ($paymentMethods === false && $paymentMethods !== 'b:0;') {
            $paymentMethods = array();
        }
		$config['PAYU_PAYMENT_METHODS[]'] = $paymentMethods;

		return $config;
	}
	
	protected function getPaymentMethods()
	{
		$paymentMethods = array(
			'CREDITCARD' => 'Credit card',
			'DISCOVERYMILES' => 'Discovery Miles',
			'GLOBALPAY' => 'Global Pay',
			'DEBITCARD' => 'Debit card',
			'EBUCKS' => 'eBucks',
			'EFT' => 'Electronic Funds Transfer (EFT)'
		);

		$options = array();
		$option = array();
		
		foreach($paymentMethods as $key => $value) {
			$option['id_option'] = $key;
			$option['name'] = $value;
			$options[] = $option;
		}
		
		return $options;
	}

	public function getCurrentContext()
	{
		if (!isset($this->context)) {
			return Context::getContext();
		}
		return $this->context;
	}
	
	public function doSetTransaction($cart)
	{
		$context = $this->getCurrentContext();
		$customer = new Customer(intval($cart->id_customer));
		$id_address = Address::getFirstCustomerAddressId(intval($customer->id));
		$address = new Address($id_address);
		$shop = new Shop($cart->id_shop);

		$showBudget = Configuration::get('PAYU_SHOW_BUDGET');
		$currency = $this->getCurrency();

		if (!Validate::isLoadedObject($customer)
			OR !Validate::isLoadedObject($currency)
			OR !Validate::isLoadedObject($address)
			OR !Validate::isLoadedObject($shop)
		) {
			return $this->l('Invalid customer, address or currency object');
		}

		$phone = isset($address->phone_mobile) ? Tools::stripslashes($address->phone_mobile) : (isset($address->phone) ?
			Tools::stripslashes($address->phone) : '');

		$txnData = array();
		$txnData['Api'] = self::API_VERSION;
		$txnData['Safekey'] = Configuration::get('PAYU_SAFE_KEY');
		$txnData['TransactionType'] = Configuration::get('PAYU_TRANSACTION_TYPE');
		$txnData['AdditionalInformation']['merchantReference'] = $this->currentOrder;
		$txnData['AdditionalInformation']['secure3d'] = 'true';

		if($showBudget) {
			$txnData['AdditionalInformation']['ShowBudget'] = 'true';
		} else {
			$txnData['AdditionalInformation']['ShowBudget'] = 'false';
		}
		
		$txnData['AdditionalInformation']['notificationUrl'] = $context->link->getModuleLink('payu', 'validation');
		$txnData['AdditionalInformation']['cancelUrl'] = $context->link->getModuleLink('payu', 'validation', ['action' => 'cancel']);
		$txnData['AdditionalInformation']['returnUrl'] = $context->link->getModuleLink('payu', 'validation');
		$txnData['AdditionalInformation']['supportedPaymentMethods'] = implode(',', @unserialize(Configuration::get('PAYU_PAYMENT_METHODS')));

		$txnData['Basket']['description'] = $shop->name . ' Online Sale';
		$txnData['Basket']['amountInCents'] =(int)((number_format(Tools::convertPrice($cart->getOrderTotal(true, Cart::BOTH), $currency), 2, '.', '')) * 100);
		$txnData['Basket']['currencyCode'] = Configuration::get('PAYU_CURRENCY');

		$txnData['Customer']['merchantUserId'] = Tools::stripslashes($customer->id);
		$txnData['Customer']['email'] = Tools::stripslashes($customer->email);
		$txnData['Customer']['firstName'] = Tools::stripslashes($customer->firstname);
		$txnData['Customer']['ip'] = Tools::getRemoteAddr();
		$txnData['Customer']['lastName'] = Tools::stripslashes($customer->lastname);
		$txnData['Customer']['mobile'] = $phone;
		$txnData['Customer']['regionalId'] = Tools::stripslashes($address->city . '_' . $address->postcode);
		
		$returnData = $this->setSoapTransaction($txnData);
		$payUReference = isset($returnData['return']['payUReference']) ? $returnData['return']['payUReference'] : null;

		return $payUReference;
	}

	public function parseXMLToArray($xml) {
		if($xml->count() <= 0)
			return false;

		$data = array();
		foreach ($xml as $element) {
			if($element->children()) {
				foreach ($element as $child) {
					if($child->attributes()) {
						foreach ($child->attributes() as $key => $value) {
							$data[$element->getName()][$child->getName()][$key] = $value->__toString();
						}
					} else {
						$data[$element->getName()][$child->getName()] = $child->__toString();
					}
				}
			} else {
				$data[$element->getName()] = $element->__toString();
			}
		}
		return $data;
	}
}
?>
