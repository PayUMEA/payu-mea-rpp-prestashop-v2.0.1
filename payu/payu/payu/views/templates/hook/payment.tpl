{*
 * Prestashop PayU Plugin
 *
 * @category   Modules
 * @package    PayU
 * @copyright  Copyright (c) 2015 Netcraft Devops (Pty) Limited
 *             http://www.netcraft-devops.com
 * @author     Kenneth Onah <kenneth@netcraft-devops.com>
 *}

<p class="payment_module">
	<a href="{$link->getModuleLink('payu', 'payment', [], true)}" title="{l s='Pay with PayU' mod='payu'}">
		<img src="{$this_path_pu}payu_logo.png" alt="{l s='Pay with PayU wallet' mod='payu'}" />
		<img src="{$this_path_pu}mastercard_logo.png" alt="{l s='Pay with Mastercard Credit/Debit Card' mod='payu'}" />
		<img src="{$this_path_pu}visa_logo.png" alt="{l s='Pay with Visa Credit/Debit Card' mod='payu'}" />
		<img src="{$this_path_pu}eb_logo.png" alt="{l s='Pay with eBucks' mod='payu'}" />
	</a>
</p>
