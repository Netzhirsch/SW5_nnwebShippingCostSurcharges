{extends file="parent:frontend/checkout/cart_footer.tpl"}

{* Shipping costs *}
{block name='frontend_checkout_cart_footer_field_labels_shipping'}
    {$smarty.block.parent}

    {foreach item=nnwebSurcharge from=$nnwebSurcharges}
        <li class="list--entry block-group entry--shipping">
        
        	{block name='frontend_checkout_cart_footer_field_labels_shipping_label'}
				<div class="entry--label block">
					{$nnwebSurcharge.label}
				</div>
			{/block}
			
			{block name='frontend_checkout_cart_footer_field_labels_shipping_value'}
				<div class="entry--value block">
					{$nnwebSurcharge.value|currency}{s name="Star" namespace="frontend/listing/box_article"}{/s}
				</div>
			{/block}
        </li>
    {/foreach}
{/block}