{**
 * NETOPIA Payments — "Send payment link" button on order detail page.
 *
 * Hook: orders:details_tools — appends to the order action toolbar.
 * Only shown for orders using the NETOPIA Payments processor.
 * The $netopia_payment_link_available flag is set by orders.post.php controller.
 *}

{if $netopia_payment_link_available}
{* Show action buttons for non-completed orders — Failed, Open, Incomplete, New, Declined *}
{if $order_info.status == "F" || $order_info.status == "O" || $order_info.status == "I" || $order_info.status == "N" || $order_info.status == "D"}
<div class="btn-group" style="margin-left: 5px;">
    <a class="btn btn-primary dropdown-toggle" data-toggle="dropdown" href="#">
        <i class="icon-envelope icon-white"></i> {__("netopia_send_payment_link")}
        <span class="caret"></span>
    </a>
    <ul class="dropdown-menu">
        <li>
            <form action="{"netopia_payment_link.send"|fn_url}" method="post" class="cm-post-form" style="display:inline;">
                <input type="hidden" name="order_id" value="{$order_info.order_id}" />
                <input type="hidden" name="security_hash" value="{$security_hash}" />
                <button type="submit" class="btn btn-link" style="width:100%; text-align:left; padding: 3px 15px;">
                    <i class="icon-envelope"></i> {__("netopia_payment_link_send_email")}
                </button>
            </form>
        </li>
        <li>
            <form action="{"netopia_payment_link.generate"|fn_url}" method="post" class="cm-post-form" style="display:inline;">
                <input type="hidden" name="order_id" value="{$order_info.order_id}" />
                <input type="hidden" name="security_hash" value="{$security_hash}" />
                <button type="submit" class="btn btn-link" style="width:100%; text-align:left; padding: 3px 15px;">
                    <i class="icon-link"></i> {__("netopia_payment_link_generate_only")}
                </button>
            </form>
        </li>
    </ul>
</div>
{/if}

{* ---- NETOPIA Transaction Info Panel ---- *}
{if $order_info.payment_info.netopia_ntp_id}
<div class="well well-small" style="margin-top: 10px;">
    <h4 style="margin-top: 0;">{__("netopia_transaction_info")}</h4>
    <table class="table table-condensed" style="margin-bottom: 0;">
        <tr>
            <td style="width: 180px;"><strong>{__("netopia_ntp_id_label")}:</strong></td>
            <td><code>{$order_info.payment_info.netopia_ntp_id}</code></td>
        </tr>
        {if $order_info.payment_info.netopia_status}
        <tr>
            <td><strong>{__("netopia_status_label")}:</strong></td>
            <td>
                {$ntp_status_code = $order_info.payment_info.netopia_status}
                {$ntp_statuses_map = ""|fn_netopia_get_status_definitions}
                {if isset($ntp_statuses_map[$ntp_status_code])}
                    {$ntp_statuses_map[$ntp_status_code].label} <span class="muted">(#{$ntp_status_code})</span>
                {else}
                    #{$ntp_status_code}
                {/if}
            </td>
        </tr>
        {/if}
        {if $order_info.payment_info.netopia_amount}
        <tr>
            <td><strong>{__("netopia_amount_label")}:</strong></td>
            <td>{$order_info.payment_info.netopia_amount}</td>
        </tr>
        {/if}
        {if $order_info.payment_info.netopia_error_code}
        <tr>
            <td><strong>{__("netopia_error_code_label")}:</strong></td>
            <td>{$order_info.payment_info.netopia_error_code}</td>
        </tr>
        {/if}
        {if $order_info.payment_info.netopia_error_message}
        <tr>
            <td><strong>{__("netopia_error_message_label")}:</strong></td>
            <td>{$order_info.payment_info.netopia_error_message}</td>
        </tr>
        {/if}
    </table>
</div>
{/if}

{* ---- Payment link info if already generated ---- *}
{if $order_info.payment_info.netopia_payment_link}
<div class="well well-small" style="margin-top: 10px;">
    <strong><i class="icon-link"></i> {__("netopia_payment_link_label")}:</strong><br/>
    <a href="{$order_info.payment_info.netopia_payment_link}" target="_blank" rel="noopener">
        {$order_info.payment_info.netopia_payment_link}
    </a>
    <br/>
    <span class="muted">{__("netopia_payment_link_generated_at")}: {$order_info.payment_info.netopia_payment_link_at}</span>
    {if $order_info.payment_info.netopia_payment_link_email_sent}
        <br/>
        <span class="muted">{__("netopia_payment_link_emailed_to")}: {$order_info.payment_info.netopia_payment_link_email_sent} ({$order_info.payment_info.netopia_payment_link_email_sent_at})</span>
    {/if}
</div>
{/if}
{/if}
