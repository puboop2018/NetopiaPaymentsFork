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

{* Show existing payment link info if already generated *}
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
