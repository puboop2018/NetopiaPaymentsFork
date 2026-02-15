{**
 * NETOPIA Payments processor configuration template.
 * Displayed in the admin panel when editing a payment method that uses this processor.
 *}

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
        <input type="text" name="payment_data[processor_params][pos_signature]" id="netopia_pos_signature" value="{$processor_params.pos_signature}" size="60" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" />
        <p class="muted description">{__("netopia_pos_signature_description")}</p>
    </div>
</div>

{* ---- API Key ---- *}
<div class="control-group">
    <label class="control-label" for="netopia_api_key">{__("netopia_api_key")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][api_key]" id="netopia_api_key" value="{$processor_params.api_key}" size="60" placeholder="ApiKey_XXXXXXXX" />
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
                <option value="{$currency.currency_code}" {if $processor_params.currency == $currency.currency_code}selected="selected"{/if}>{$currency.currency_code} - {$currency.description}</option>
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

{* ---- Public Key (from NETOPIA) ---- *}
<div class="control-group">
    <label class="control-label">{__("netopia_public_key")}:</label>
    <div class="controls">
        <div class="netopia-key-section">

            {* Upload field *}
            <div style="margin-bottom: 8px;">
                <label for="netopia_public_key_file" class="btn" style="cursor:pointer;">
                    <i class="icon-upload"></i> {__("netopia_upload_key_file")}
                </label>
                <input type="file" name="netopia_public_key_file" id="netopia_public_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" style="margin-top: 4px;" />
            </div>

            {* Show currently uploaded filename *}
            {if $processor_params.public_key_file}
                <div class="well well-small" style="margin-bottom: 8px;">
                    <i class="icon-file"></i>
                    {__("netopia_current_file")}: <strong>{$processor_params.public_key_file}</strong>
                    &nbsp;
                    <label style="display:inline; cursor:pointer;">
                        <input type="checkbox" name="delete_netopia_public_key" value="1" />
                        {__("netopia_delete_key_file")}
                    </label>
                </div>
            {/if}

            {* Textarea fallback *}
            <p class="muted" style="margin-bottom: 4px;">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][public_key]" id="netopia_public_key" cols="65" rows="8" placeholder="-----BEGIN PUBLIC KEY-----&#10;...&#10;-----END PUBLIC KEY-----">{$processor_params.public_key}</textarea>
            <p class="muted description">{__("netopia_public_key_description")}</p>
        </div>
    </div>
</div>

{* ---- Private Key (from NETOPIA) ---- *}
<div class="control-group">
    <label class="control-label">{__("netopia_private_key")}:</label>
    <div class="controls">
        <div class="netopia-key-section">

            {* Upload field *}
            <div style="margin-bottom: 8px;">
                <label for="netopia_private_key_file" class="btn" style="cursor:pointer;">
                    <i class="icon-upload"></i> {__("netopia_upload_key_file")}
                </label>
                <input type="file" name="netopia_private_key_file" id="netopia_private_key_file" accept=".pem,.key,.cer,.crt,.pub,.txt" style="margin-top: 4px;" />
            </div>

            {* Show currently uploaded filename *}
            {if $processor_params.private_key_file}
                <div class="well well-small" style="margin-bottom: 8px;">
                    <i class="icon-file"></i>
                    {__("netopia_current_file")}: <strong>{$processor_params.private_key_file}</strong>
                    &nbsp;
                    <label style="display:inline; cursor:pointer;">
                        <input type="checkbox" name="delete_netopia_private_key" value="1" />
                        {__("netopia_delete_key_file")}
                    </label>
                </div>
            {/if}

            {* Textarea fallback *}
            <p class="muted" style="margin-bottom: 4px;">{__("netopia_or_paste_key")}:</p>
            <textarea name="payment_data[processor_params][private_key]" id="netopia_private_key" cols="65" rows="8" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----">{$processor_params.private_key}</textarea>
            <p class="muted description">{__("netopia_private_key_description")}</p>
        </div>
    </div>
</div>
