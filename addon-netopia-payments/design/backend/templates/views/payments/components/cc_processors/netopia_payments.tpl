{**
 * NETOPIA Payments processor configuration template.
 * Displayed in the admin panel when editing a payment method that uses this processor.
 *}

{* Ensure parent form supports file uploads *}
{literal}
<script>
(function() {
    var form = document.querySelector('form[name="payments_form"], form.cm-ajax-content-input');
    if (!form) {
        form = document.getElementById('netopia_mode');
        if (form) form = form.closest('form');
    }
    if (form) {
        form.setAttribute('enctype', 'multipart/form-data');
        form.encoding = 'multipart/form-data';
    }
})();
</script>
{/literal}

{* ---- Mode (Sandbox / Live) ---- *}
<div class="control-group">
    <label class="control-label" for="netopia_mode">{__("netopia_mode")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][mode]" id="netopia_mode">
            <option value="sandbox" {if $processor_params.mode == "sandbox"}selected="selected"{/if}>{__("netopia_sandbox")}</option>
            <option value="live" {if $processor_params.mode == "live"}selected="selected"{/if}>{__("netopia_live")}</option>
        </select>
    </div>
</div>

{* ---- POS Signature ---- *}
<div class="control-group">
    <label class="control-label" for="netopia_pos_signature">{__("netopia_pos_signature")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][pos_signature]" id="netopia_pos_signature" value="{$processor_params.pos_signature|escape:"html"}" size="60" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" />
        <p class="muted description">{__("netopia_pos_signature_description")}</p>
    </div>
</div>

{* ---- API Key ---- *}
<div class="control-group">
    <label class="control-label" for="netopia_api_key">{__("netopia_api_key")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][api_key]" id="netopia_api_key" value="{$processor_params.api_key|escape:"html"}" size="60" placeholder="ApiKey_XXXXXXXX" />
        <p class="muted description">{__("netopia_api_key_description")}</p>
    </div>
</div>

{* ---- Currency ---- *}
<div class="control-group">
    <label class="control-label" for="netopia_currency">{__("netopia_currency")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][currency]" id="netopia_currency">
            <option value="order_currency" {if $processor_params.currency == "order_currency"}selected="selected"{/if}>{__("netopia_order_currency")}</option>
            {foreach from=$currencies item="currency"}
                <option value="{$currency.currency_code|escape:"html"}" {if $processor_params.currency == $currency.currency_code}selected="selected"{/if}>{$currency.currency_code|escape:"html"} - {$currency.description|escape:"html"}</option>
            {/foreach}
        </select>
        <p class="muted description">{__("netopia_currency_description")}</p>
    </div>
</div>

{* ---- Installments ---- *}
<div class="control-group">
    <label class="control-label" for="netopia_allow_installments">{__("netopia_allow_installments")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][allow_installments]" id="netopia_allow_installments">
            <option value="N" {if $processor_params.allow_installments != "Y"}selected="selected"{/if}>{__("no")}</option>
            <option value="Y" {if $processor_params.allow_installments == "Y"}selected="selected"{/if}>{__("yes")}</option>
        </select>
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="netopia_max_installments">{__("netopia_max_installments")}:</label>
    <div class="controls">
        <input type="number" name="payment_data[processor_params][max_installments]" id="netopia_max_installments" value="{$processor_params.max_installments|default:1}" min="1" max="36" size="5" />
        <p class="muted description">{__("netopia_max_installments_description")}</p>
    </div>
</div>

{* ================================================================ *}
{* ---- ORDER STATUS MAPPING ---- *}
{* ================================================================ *}
{$order_statuses = "O"|fn_get_simple_statuses}
{$ntp_statuses = ""|fn_netopia_get_status_definitions}

<fieldset>
    <legend style="border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px;">
        {__("netopia_status_mapping_section")}
    </legend>
    <p class="muted description" style="margin-bottom: 15px;">{__("netopia_status_mapping_description")}</p>

    {* Group: Success *}
    <div style="margin-bottom: 10px;"><strong style="color: #468847;">{__("netopia_status_group_success")}</strong></div>
    {foreach from=$ntp_statuses key="ntp_code" item="ntp_info"}
        {if $ntp_info.group == "success"}
        <div class="control-group">
            <label class="control-label">{__("netopia_ntp_status_`$ntp_code`")} <span class="muted">(#{$ntp_code})</span>:</label>
            <div class="controls">
                <select name="payment_data[processor_params][status_map_{$ntp_code}]">
                    {foreach from=$order_statuses key="cs_code" item="cs_label"}
                        <option value="{$cs_code}" {if ($processor_params.status_map_{$ntp_code}|default:$ntp_info.default) == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                    {/foreach}
                </select>
            </div>
        </div>
        {/if}
    {/foreach}

    {* Group: Pending *}
    <div style="margin-bottom: 10px; margin-top: 15px;"><strong style="color: #c09853;">{__("netopia_status_group_pending")}</strong></div>
    {foreach from=$ntp_statuses key="ntp_code" item="ntp_info"}
        {if $ntp_info.group == "pending"}
        <div class="control-group">
            <label class="control-label">{__("netopia_ntp_status_`$ntp_code`")} <span class="muted">(#{$ntp_code})</span>:</label>
            <div class="controls">
                <select name="payment_data[processor_params][status_map_{$ntp_code}]">
                    {foreach from=$order_statuses key="cs_code" item="cs_label"}
                        <option value="{$cs_code}" {if ($processor_params.status_map_{$ntp_code}|default:$ntp_info.default) == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                    {/foreach}
                </select>
            </div>
        </div>
        {/if}
    {/foreach}

    {* Group: Cancel/Refund *}
    <div style="margin-bottom: 10px; margin-top: 15px;"><strong style="color: #b94a48;">{__("netopia_status_group_cancel")}</strong></div>
    {foreach from=$ntp_statuses key="ntp_code" item="ntp_info"}
        {if $ntp_info.group == "cancel"}
        <div class="control-group">
            <label class="control-label">{__("netopia_ntp_status_`$ntp_code`")} <span class="muted">(#{$ntp_code})</span>:</label>
            <div class="controls">
                <select name="payment_data[processor_params][status_map_{$ntp_code}]">
                    {foreach from=$order_statuses key="cs_code" item="cs_label"}
                        <option value="{$cs_code}" {if ($processor_params.status_map_{$ntp_code}|default:$ntp_info.default) == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                    {/foreach}
                </select>
            </div>
        </div>
        {/if}
    {/foreach}

    {* Group: Failure *}
    <div style="margin-bottom: 10px; margin-top: 15px;"><strong style="color: #b94a48;">{__("netopia_status_group_fail")}</strong></div>
    {foreach from=$ntp_statuses key="ntp_code" item="ntp_info"}
        {if $ntp_info.group == "fail"}
        <div class="control-group">
            <label class="control-label">{__("netopia_ntp_status_`$ntp_code`")} <span class="muted">(#{$ntp_code})</span>:</label>
            <div class="controls">
                <select name="payment_data[processor_params][status_map_{$ntp_code}]">
                    {foreach from=$order_statuses key="cs_code" item="cs_label"}
                        <option value="{$cs_code}" {if ($processor_params.status_map_{$ntp_code}|default:$ntp_info.default) == $cs_code}selected="selected"{/if}>[{$cs_code}] {$cs_label}</option>
                    {/foreach}
                </select>
            </div>
        </div>
        {/if}
    {/foreach}
</fieldset>

{* ================================================================ *}
{* ---- SANDBOX CERTIFICATES ---- *}
{* ================================================================ *}
<fieldset>
    <legend style="border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px;">
        {__("netopia_sandbox_keys_section")}
    </legend>

    {* Sandbox Public Key *}
    <div class="control-group">
        <label class="control-label">{__("netopia_sandbox_public_key")}:</label>
        <div class="controls">
            <div style="margin-bottom: 8px;">
                <label for="netopia_sandbox_public_key_file" class="btn" style="cursor:pointer;">
                    <i class="icon-upload"></i> {__("netopia_upload_key_file")}
                </label>
                <input type="file" name="netopia_sandbox_public_key_file" id="netopia_sandbox_public_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" style="margin-top: 4px;" />
            </div>
            {if $processor_params.sandbox_public_key_file}
                <div class="well well-small" style="margin-bottom: 8px;">
                    <i class="icon-file"></i>
                    {__("netopia_current_file")}: <strong>{$processor_params.sandbox_public_key_file|escape:"html"}</strong>
                    &nbsp;
                    <label style="display:inline; cursor:pointer;">
                        <input type="checkbox" name="delete_netopia_sandbox_public_key" value="1" />
                        {__("netopia_delete_key_file")}
                    </label>
                </div>
            {/if}
            <p class="muted" style="margin-bottom: 4px;">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][sandbox_public_key]" id="netopia_sandbox_public_key" cols="65" rows="6" placeholder="-----BEGIN PUBLIC KEY-----&#10;...&#10;-----END PUBLIC KEY-----">{$processor_params.sandbox_public_key|escape:"html"}</textarea>
            <p class="muted description">{__("netopia_sandbox_public_key_description")}</p>
        </div>
    </div>

    {* Sandbox Private Key *}
    <div class="control-group">
        <label class="control-label">{__("netopia_sandbox_private_key")}:</label>
        <div class="controls">
            <div style="margin-bottom: 8px;">
                <label for="netopia_sandbox_private_key_file" class="btn" style="cursor:pointer;">
                    <i class="icon-upload"></i> {__("netopia_upload_key_file")}
                </label>
                <input type="file" name="netopia_sandbox_private_key_file" id="netopia_sandbox_private_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" style="margin-top: 4px;" />
            </div>
            {if $processor_params.sandbox_private_key_file}
                <div class="well well-small" style="margin-bottom: 8px;">
                    <i class="icon-file"></i>
                    {__("netopia_current_file")}: <strong>{$processor_params.sandbox_private_key_file|escape:"html"}</strong>
                    &nbsp;
                    <label style="display:inline; cursor:pointer;">
                        <input type="checkbox" name="delete_netopia_sandbox_private_key" value="1" />
                        {__("netopia_delete_key_file")}
                    </label>
                </div>
            {/if}
            <p class="muted" style="margin-bottom: 4px;">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][sandbox_private_key]" id="netopia_sandbox_private_key" cols="65" rows="6" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----">{$processor_params.sandbox_private_key|escape:"html"}</textarea>
            <p class="muted description">{__("netopia_sandbox_private_key_description")}</p>
        </div>
    </div>
</fieldset>

{* ================================================================ *}
{* ---- LIVE CERTIFICATES ---- *}
{* ================================================================ *}
<fieldset>
    <legend style="border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px;">
        {__("netopia_live_keys_section")}
    </legend>

    {* Live Public Key *}
    <div class="control-group">
        <label class="control-label">{__("netopia_live_public_key")}:</label>
        <div class="controls">
            <div style="margin-bottom: 8px;">
                <label for="netopia_live_public_key_file" class="btn" style="cursor:pointer;">
                    <i class="icon-upload"></i> {__("netopia_upload_key_file")}
                </label>
                <input type="file" name="netopia_live_public_key_file" id="netopia_live_public_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" style="margin-top: 4px;" />
            </div>
            {if $processor_params.live_public_key_file}
                <div class="well well-small" style="margin-bottom: 8px;">
                    <i class="icon-file"></i>
                    {__("netopia_current_file")}: <strong>{$processor_params.live_public_key_file|escape:"html"}</strong>
                    &nbsp;
                    <label style="display:inline; cursor:pointer;">
                        <input type="checkbox" name="delete_netopia_live_public_key" value="1" />
                        {__("netopia_delete_key_file")}
                    </label>
                </div>
            {/if}
            <p class="muted" style="margin-bottom: 4px;">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][live_public_key]" id="netopia_live_public_key" cols="65" rows="6" placeholder="-----BEGIN PUBLIC KEY-----&#10;...&#10;-----END PUBLIC KEY-----">{$processor_params.live_public_key|escape:"html"}</textarea>
            <p class="muted description">{__("netopia_live_public_key_description")}</p>
        </div>
    </div>

    {* Live Private Key *}
    <div class="control-group">
        <label class="control-label">{__("netopia_live_private_key")}:</label>
        <div class="controls">
            <div style="margin-bottom: 8px;">
                <label for="netopia_live_private_key_file" class="btn" style="cursor:pointer;">
                    <i class="icon-upload"></i> {__("netopia_upload_key_file")}
                </label>
                <input type="file" name="netopia_live_private_key_file" id="netopia_live_private_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" style="margin-top: 4px;" />
            </div>
            {if $processor_params.live_private_key_file}
                <div class="well well-small" style="margin-bottom: 8px;">
                    <i class="icon-file"></i>
                    {__("netopia_current_file")}: <strong>{$processor_params.live_private_key_file|escape:"html"}</strong>
                    &nbsp;
                    <label style="display:inline; cursor:pointer;">
                        <input type="checkbox" name="delete_netopia_live_private_key" value="1" />
                        {__("netopia_delete_key_file")}
                    </label>
                </div>
            {/if}
            <p class="muted" style="margin-bottom: 4px;">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][live_private_key]" id="netopia_live_private_key" cols="65" rows="6" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----">{$processor_params.live_private_key|escape:"html"}</textarea>
            <p class="muted description">{__("netopia_live_private_key_description")}</p>
        </div>
    </div>
</fieldset>
