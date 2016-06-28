{*
* 2007-2014 PrestaShop
*
* NOTICE OF LICENSE
*
*  @author Kenneth Onah <kenneth@netcraft-devops.com>
*  @copyright  2015 NetCraft DevOps
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  Property of NetCraft DevOps
*}

{capture name=path}{l s='PayU secure payments' mod='payu'}{/capture}

<p>
	<p>{l s='Reason: ' mod='payu'}{$reason}</p>
	<br /><br />
	- {l s='Transaction state: ' mod='payu'}  <strong>{if $state}{$state}{else}___________{/if}</strong>
	<br /><br />
	- {l s='PayU Reference: ' mod='payu'}  <strong>{if $payu_ref}{$payu_ref}{else}___________{/if}</strong>
	{l s='If you think this is an error please contact our ' mod='payu'}
	<a href="{$link->getPageLink('contact', true)|escape:'html'}" style="color:#317fd8">{l s='CUSTOMER CARE.' mod='payu'}</a>
</p>
