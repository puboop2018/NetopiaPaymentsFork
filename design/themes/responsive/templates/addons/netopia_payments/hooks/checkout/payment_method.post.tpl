{**
 * NETOPIA Payments — 3D Secure browser fingerprint data collection.
 * Injected after the payment method form on checkout.
 * Collects browser data required by NETOPIA for 3DS authentication.
 *}

{if $payment_method.processor_script == "netopia_payments.php"}
<div id="netopia_3ds_fields" style="display:none;">
    <input type="hidden" name="netopia_screen_print" id="netopia_screen_print" value="" />
    <input type="hidden" name="netopia_screen_height" id="netopia_screen_height" value="" />
    <input type="hidden" name="netopia_screen_width" id="netopia_screen_width" value="" />
    <input type="hidden" name="netopia_color_depth" id="netopia_color_depth" value="" />
    <input type="hidden" name="netopia_tz" id="netopia_tz" value="" />
    <input type="hidden" name="netopia_tz_offset" id="netopia_tz_offset" value="" />
    <input type="hidden" name="netopia_language" id="netopia_language" value="" />
    <input type="hidden" name="netopia_java_enabled" id="netopia_java_enabled" value="" />
    <input type="hidden" name="netopia_plugins" id="netopia_plugins" value="" />
    <input type="hidden" name="netopia_mobile" id="netopia_mobile" value="" />
    <input type="hidden" name="netopia_screen_point" id="netopia_screen_point" value="" />
</div>

{literal}
<script>
(function() {
    try {
        var d = document;
        var s = window.screen;

        d.getElementById('netopia_screen_print').value = 'Current Resolution: ' + s.width + 'x' + s.height + ', Available Resolution: ' + s.availWidth + 'x' + s.availHeight;
        d.getElementById('netopia_screen_height').value = s.height;
        d.getElementById('netopia_screen_width').value = s.width;
        d.getElementById('netopia_color_depth').value = s.colorDepth;
        d.getElementById('netopia_tz').value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Bucharest';
        d.getElementById('netopia_tz_offset').value = new Date().getTimezoneOffset();
        d.getElementById('netopia_language').value = navigator.language || navigator.userLanguage || 'en-US';
        d.getElementById('netopia_java_enabled').value = navigator.javaEnabled ? navigator.javaEnabled().toString() : 'false';
        d.getElementById('netopia_mobile').value = /Mobi|Android/i.test(navigator.userAgent) ? 'true' : 'false';
        d.getElementById('netopia_screen_point').value = ('ontouchstart' in window) ? 'true' : 'false';

        var plugins = [];
        if (navigator.plugins) {
            for (var i = 0; i < Math.min(navigator.plugins.length, 10); i++) {
                plugins.push(navigator.plugins[i].name);
            }
        }
        d.getElementById('netopia_plugins').value = plugins.join(', ');
    } catch(e) {}
})();
</script>
{/literal}

{* Installments selector (shown if enabled) *}
{if $payment_method.processor_params.allow_installments == "Y" && $payment_method.processor_params.max_installments > 1}
<div class="ty-control-group">
    <label class="ty-control-group__title" for="netopia_installments">{__("netopia_installments_label")}:</label>
    <select name="netopia_installments" id="netopia_installments" class="ty-select">
        <option value="1">{__("netopia_full_payment")}</option>
        {section name="inst" start=2 loop=$payment_method.processor_params.max_installments+1}
            <option value="{$smarty.section.inst.index}">{$smarty.section.inst.index} {__("netopia_installments_suffix")}</option>
        {/section}
    </select>
</div>
{/if}

{/if}
