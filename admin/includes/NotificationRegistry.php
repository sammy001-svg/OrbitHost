<?php
/**
 * Orbit Cloud — Notification type registry
 *
 * Every notification the platform sends — in-app and email — is defined
 * here as one entry: who it's for, whether it emails by default, and the
 * templates (title/message for the bell, subject/body for email).
 * Templates use {placeholder} substitution against the $vars array passed
 * to Notifier::send(). Adding a new notification is one array entry here
 * plus a call to Notifier::send() at the trigger point — no UI changes.
 */
final class NotificationRegistry
{
    public static function types(): array
    {
        return [

            /* ══════════════ SUPPORT TICKETS ══════════════ */
            'ticket_replied' => [
                'audience' => 'client', 'icon' => 'fa-reply', 'color' => '#1A8A45', 'email' => true,
                'title'   => 'New reply on ticket {ticket_number}',
                'message' => '{admin_name} replied to "{subject}".',
                'email_subject' => 'Re: {subject} [{ticket_number}]',
                'email_body'    => '<p>Hi {client_name},</p><p>Our support team has replied to your ticket <strong>{ticket_number}</strong> — "{subject}".</p><blockquote style="margin:16px 0;padding:12px 16px;background:#f7f9fc;border-left:3px solid #1A8A45;border-radius:6px;color:#334155">{reply_excerpt}</blockquote><p>Log in to the client portal to view the full conversation and reply.</p>',
            ],
            'ticket_closed' => [
                'audience' => 'client', 'icon' => 'fa-circle-check', 'color' => '#64748b', 'email' => true,
                'title'   => 'Ticket {ticket_number} closed',
                'message' => 'Your ticket "{subject}" has been marked as closed.',
                'email_subject' => 'Ticket closed: {subject} [{ticket_number}]',
                'email_body'    => '<p>Hi {client_name},</p><p>Your support ticket <strong>{ticket_number}</strong> — "{subject}" — has been closed.</p><p>If you need further help, just reply to reopen it or open a new ticket any time from the client portal.</p>',
            ],
            'ticket_opened_admin' => [
                'audience' => 'admin', 'icon' => 'fa-ticket', 'color' => '#2563eb', 'email' => true,
                'title'   => 'New ticket: {ticket_number}',
                'message' => '{client_name} opened "{subject}" ({priority}).',
                'email_subject' => 'New ticket [{ticket_number}]: {subject}',
                'email_body'    => '<p><strong>{client_name}</strong> opened a new support ticket.</p><table style="width:100%;border-collapse:collapse;margin:16px 0"><tr><td style="padding:6px 0;color:#64748b">Ticket</td><td style="padding:6px 0;text-align:right;font-weight:700">{ticket_number}</td></tr><tr><td style="padding:6px 0;color:#64748b">Subject</td><td style="padding:6px 0;text-align:right;font-weight:700">{subject}</td></tr><tr><td style="padding:6px 0;color:#64748b">Priority</td><td style="padding:6px 0;text-align:right;font-weight:700">{priority}</td></tr></table><p>Log in to the admin panel to reply.</p>',
            ],
            'ticket_client_replied_admin' => [
                'audience' => 'admin', 'icon' => 'fa-comment-dots', 'color' => '#2563eb', 'email' => true,
                'title'   => '{client_name} replied to {ticket_number}',
                'message' => 'New reply on "{subject}".',
                'email_subject' => 'Client reply on ticket [{ticket_number}]: {subject}',
                'email_body'    => '<p><strong>{client_name}</strong> replied to ticket <strong>{ticket_number}</strong> — "{subject}".</p><p>Log in to the admin panel to view and respond.</p>',
            ],

            /* ══════════════ BILLING ══════════════ */
            'invoice_new' => [
                'audience' => 'client', 'icon' => 'fa-file-invoice', 'color' => '#2563eb', 'email' => true,
                'title'   => 'New invoice {invoice_number}',
                'message' => '{amount} due {due_date}.',
                'email_subject' => 'Invoice {invoice_number} — {amount} due {due_date}',
                'email_body'    => '<p>Hi {client_name},</p><p>A new invoice has been generated on your account.</p>'
                    . '<table style="width:100%;border-collapse:collapse;margin:16px 0">'
                    . '<tr><td style="padding:6px 0;color:#64748b">Invoice</td><td style="padding:6px 0;text-align:right;font-weight:700">{invoice_number}</td></tr>'
                    . '<tr><td style="padding:6px 0;color:#64748b">Status</td><td style="padding:6px 0;text-align:right"><span style="background:{status_color};color:#fff;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">{status}</span></td></tr>'
                    . '<tr><td style="padding:6px 0;color:#64748b">Amount due</td><td style="padding:6px 0;text-align:right;font-weight:700">{amount}</td></tr>'
                    . '<tr><td style="padding:6px 0;color:#64748b">Due date</td><td style="padding:6px 0;text-align:right;font-weight:700">{due_date}</td></tr></table>'
                    . '{items_table}'
                    . '<p>Log in to the client portal to view and pay this invoice online.</p>',
            ],
            'invoice_overdue' => [
                'audience' => 'client', 'icon' => 'fa-triangle-exclamation', 'color' => '#dc2626', 'email' => true,
                'title'   => 'Invoice {invoice_number} is overdue',
                'message' => '{amount} was due {due_date}. Please pay to avoid service interruption.',
                'email_subject' => 'Overdue: Invoice {invoice_number} — please pay {amount}',
                'email_body'    => '<p>Hi {client_name},</p><p>Invoice <strong>{invoice_number}</strong> for <strong>{amount}</strong> was due on {due_date} and is now'
                    . ' <span style="background:{status_color};color:#fff;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">{status}</span>.</p>'
                    . '{items_table}'
                    . '<p>Please log in to the client portal to settle this invoice as soon as possible to avoid service suspension.</p>',
            ],
            'invoice_paid' => [
                'audience' => 'client', 'icon' => 'fa-circle-check', 'color' => '#1A8A45', 'email' => true,
                'title'   => 'Payment received — {invoice_number}',
                'message' => 'Thank you! {amount} received via {gateway}.',
                'email_subject' => 'Payment receipt — {invoice_number}',
                'email_body'    => '<p>Hi {client_name},</p><p>We\'ve received your payment of <strong>{amount}</strong> for invoice <strong>{invoice_number}</strong> via {gateway}. Thank you!</p>'
                    . '<p><span style="background:{status_color};color:#fff;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">{status}</span></p>'
                    . '{items_table}'
                    . '<p>You can view your full billing history any time in the client portal.</p>',
            ],
            'payment_failed' => [
                'audience' => 'client', 'icon' => 'fa-circle-xmark', 'color' => '#dc2626', 'email' => true,
                'title'   => 'Payment attempt failed',
                'message' => '{reason}',
                'email_subject' => 'We couldn\'t process your payment',
                'email_body'    => '<p>Hi {client_name},</p><p>Your recent payment attempt of <strong>{amount}</strong> via {gateway} was not successful.</p><p style="color:#64748b;font-size:13px">{reason}</p><p>Please try again from the client portal, or contact support if the problem continues.</p>',
            ],
            'payment_reference_submitted' => [
                'audience' => 'admin', 'icon' => 'fa-receipt', 'color' => '#d97706', 'email' => true,
                'title'   => 'Payment reference submitted — {invoice_number}',
                'message' => '{client_name} says they paid {amount} via {gateway}: {reference}',
                'email_subject' => 'Awaiting confirmation: {invoice_number} — {gateway} reference submitted',
                'email_body'    => '<p>A client has submitted a manual payment reference for review.</p><table style="width:100%;border-collapse:collapse;margin:16px 0"><tr><td style="padding:6px 0;color:#64748b">Client</td><td style="padding:6px 0;text-align:right;font-weight:700">{client_name}</td></tr><tr><td style="padding:6px 0;color:#64748b">Invoice</td><td style="padding:6px 0;text-align:right;font-weight:700">{invoice_number}</td></tr><tr><td style="padding:6px 0;color:#64748b">Amount</td><td style="padding:6px 0;text-align:right;font-weight:700">{amount}</td></tr><tr><td style="padding:6px 0;color:#64748b">Method</td><td style="padding:6px 0;text-align:right;font-weight:700">{gateway}</td></tr><tr><td style="padding:6px 0;color:#64748b">Reference</td><td style="padding:6px 0;text-align:right;font-weight:700">{reference}</td></tr></table><p>Verify it against your bank/M-Pesa statement, then confirm receipt in Billing.</p>',
            ],
            'payment_reference_rejected' => [
                'audience' => 'client', 'icon' => 'fa-circle-xmark', 'color' => '#dc2626', 'email' => true,
                'title'   => 'We couldn\'t verify your payment — {invoice_number}',
                'message' => '{reason}',
                'email_subject' => 'Action needed: payment reference for {invoice_number}',
                'email_body'    => '<p>Hi {client_name},</p><p>We could not verify the payment reference you submitted for invoice <strong>{invoice_number}</strong>.</p><p style="color:#64748b;font-size:13px">{reason}</p><p>Please double-check the reference number and submit it again from the client portal, or contact support if you believe this is a mistake.</p>',
            ],

            /* ══════════════ ORDERS / PURCHASES ══════════════ */
            'order_new' => [
                'audience' => 'client', 'icon' => 'fa-bag-shopping', 'color' => '#1A8A45', 'email' => true,
                'title'   => 'Order confirmed: {item}',
                'message' => '{item} — thank you for your purchase!',
                'email_subject' => 'Order confirmed — {item}',
                'email_body'    => '<p>Hi {client_name},</p><p>Thanks for your purchase! Here\'s a summary:</p><table style="width:100%;border-collapse:collapse;margin:16px 0"><tr><td style="padding:6px 0;color:#64748b">Item</td><td style="padding:6px 0;text-align:right;font-weight:700">{item}</td></tr><tr><td style="padding:6px 0;color:#64748b">Amount</td><td style="padding:6px 0;text-align:right;font-weight:700">{amount}</td></tr></table><p>{note}</p>',
            ],
            'order_new_admin' => [
                'audience' => 'admin', 'icon' => 'fa-cash-register', 'color' => '#1A8A45', 'email' => true,
                'title'   => 'New order: {item}',
                'message' => '{client_name} — {amount} via {gateway}.',
                'email_subject' => 'New order: {item} — {amount}',
                'email_body'    => '<p>New order received.</p><table style="width:100%;border-collapse:collapse;margin:16px 0"><tr><td style="padding:6px 0;color:#64748b">Client</td><td style="padding:6px 0;text-align:right;font-weight:700">{client_name}</td></tr><tr><td style="padding:6px 0;color:#64748b">Item</td><td style="padding:6px 0;text-align:right;font-weight:700">{item}</td></tr><tr><td style="padding:6px 0;color:#64748b">Amount</td><td style="padding:6px 0;text-align:right;font-weight:700">{amount}</td></tr><tr><td style="padding:6px 0;color:#64748b">Gateway</td><td style="padding:6px 0;text-align:right;font-weight:700">{gateway}</td></tr></table>',
            ],

            /* ══════════════ SERVICE CHANGE REQUESTS ══════════════ */
            'service_change_requested' => [
                'audience' => 'client', 'icon' => 'fa-arrows-up-down', 'color' => '#2563eb', 'email' => true,
                'title'   => '{direction} request submitted for {service_label}',
                'message' => 'Requested plan: {plan_name}. We\'ll review and confirm shortly.',
                'email_subject' => 'Your {direction} request has been received',
                'email_body'    => '<p>Hi {client_name},</p><p>We\'ve received your request to {direction_lc} <strong>{service_label}</strong> to <strong>{plan_name}</strong>.</p><p>Our team will review it and confirm shortly — you\'ll get another email once it\'s actioned.</p>',
            ],
            'service_change_requested_admin' => [
                'audience' => 'admin', 'icon' => 'fa-arrows-up-down', 'color' => '#d97706', 'email' => true,
                'title'   => '{direction} request: {service_label}',
                'message' => '{client_name} wants to move to {plan_name}.',
                'email_subject' => 'Service change request — {service_label}',
                'email_body'    => '<p><strong>{client_name}</strong> has requested a {direction_lc} for <strong>{service_label}</strong> to <strong>{plan_name}</strong>.</p><p style="color:#64748b;font-size:13px">Review and action it from Services › Change Requests in the admin panel.</p>',
            ],
            'service_change_approved' => [
                'audience' => 'client', 'icon' => 'fa-circle-check', 'color' => '#1A8A45', 'email' => true,
                'title'   => '{service_label} is now {plan_name}',
                'message' => 'Your plan change has been applied.',
                'email_subject' => 'Your plan change is complete — {service_label}',
                'email_body'    => '<p>Hi {client_name},</p><p>Good news — your {direction_lc} for <strong>{service_label}</strong> is complete. It\'s now on the <strong>{plan_name}</strong> plan.</p>',
            ],
            'service_change_declined' => [
                'audience' => 'client', 'icon' => 'fa-circle-xmark', 'color' => '#dc2626', 'email' => true,
                'title'   => '{direction} request declined',
                'message' => '{reason}',
                'email_subject' => 'Update on your {direction_lc} request — {service_label}',
                'email_body'    => '<p>Hi {client_name},</p><p>We were unable to process your {direction_lc} request for <strong>{service_label}</strong>.</p><p style="color:#64748b;font-size:13px">Reason: {reason}</p><p>Please open a support ticket if you have questions.</p>',
            ],

            /* ══════════════ ACCOUNT / SERVICES ══════════════ */
            'account_welcome' => [
                'audience' => 'client', 'icon' => 'fa-user-check', 'color' => '#1A8A45', 'email' => true,
                'title'   => 'Welcome to Orbit Cloud!',
                'message' => 'Your client portal account is ready.',
                'email_subject' => 'Welcome to Orbit Cloud — your account is ready',
                'email_body'    => '<p>Hi {client_name},</p><p>Your Orbit Cloud client portal account is now active. From the portal you can manage your services, domains, invoices and support tickets any time.</p>',
            ],
            'email_verification' => [
                'audience' => 'client', 'icon' => 'fa-envelope-circle-check', 'color' => '#2563eb', 'email' => true,
                'title'   => 'Verify your email address',
                'message' => 'Click the link we sent you to confirm your email.',
                'email_subject' => 'Confirm your email address — Orbit Cloud',
                'email_body'    => '<p>Hi {client_name},</p><p>Please confirm this is your email address by clicking the button below.</p><p style="margin:22px 0"><a href="{verify_link}" style="background:#1A8A45;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;font-weight:700">Verify Email Address</a></p><p style="color:#94a3b8;font-size:12px">This link expires in 24 hours. If you didn\'t create an Orbit Cloud account, you can ignore this email.</p>',
            ],
            'service_ready' => [
                'audience' => 'client', 'icon' => 'fa-server', 'color' => '#1A8A45', 'email' => true,
                'title'   => '{service_label} is ready',
                'message' => 'Your new service has been provisioned.',
                'email_subject' => 'Your {service_label} account is ready',
                'email_body'    => '<p>Hi {client_name},</p><p>Your new service <strong>{service_label}</strong> has been provisioned and is ready to use.</p><table style="width:100%;border-collapse:collapse;margin:16px 0">{account_rows}</table><p style="color:#64748b;font-size:13px">Keep these details somewhere safe. You can log in to your hosting control panel directly from the client portal at any time without re-entering these credentials.</p>',
            ],
            'service_suspended' => [
                'audience' => 'client', 'icon' => 'fa-ban', 'color' => '#dc2626', 'email' => true,
                'title'   => '{service_label} suspended',
                'message' => '{reason}',
                'email_subject' => 'Your service has been suspended — {service_label}',
                'email_body'    => '<p>Hi {client_name},</p><p>Your service <strong>{service_label}</strong> has been suspended.</p><p style="color:#64748b;font-size:13px">Reason: {reason}</p><p>Please contact support or settle any outstanding invoices to have it restored.</p>',
            ],
            'service_unsuspended' => [
                'audience' => 'client', 'icon' => 'fa-circle-check', 'color' => '#1A8A45', 'email' => true,
                'title'   => '{service_label} reactivated',
                'message' => 'Your service is active again.',
                'email_subject' => 'Your service has been reactivated — {service_label}',
                'email_body'    => '<p>Hi {client_name},</p><p>Good news — your service <strong>{service_label}</strong> has been reactivated and is live again.</p>',
            ],
            'service_renewal_reminder' => [
                // Skippable via Notification Preferences — unlike
                // service_expiry_reminder (the urgent "about to lose this"
                // variant fired at 7/3/1/0 days), which always sends
                // regardless, this is the earlier, informational 30/14-day
                // heads-up and safe to opt out of.
                'audience' => 'client', 'icon' => 'fa-clock', 'color' => '#d97706', 'email' => true, 'category' => 'reminder',
                'title'   => '{item} renews in {days_left} days',
                'message' => 'Renew before {expiry_date} to avoid interruption.',
                'email_subject' => 'Reminder: {item} renews in {days_left} days',
                'email_body'    => '<p>Hi {client_name},</p><p><strong>{item}</strong> is due to renew on <strong>{expiry_date}</strong> ({days_left} days from now).</p><p>Log in to the client portal to renew it online in a few clicks, or make sure auto-renew / your invoice is settled to avoid any interruption.</p>',
            ],
            'service_expiry_reminder' => [
                'audience' => 'client', 'icon' => 'fa-triangle-exclamation', 'color' => '#dc2626', 'email' => true,
                'title'   => '{item} expires in {days_left} days',
                'message' => 'Act now — expires {expiry_date}.',
                'email_subject' => 'Urgent: {item} expires in {days_left} days',
                'email_body'    => '<p>Hi {client_name},</p><p><strong>{item}</strong> is about to expire on <strong>{expiry_date}</strong> — only {days_left} day(s) left.</p><p>Please renew as soon as possible from the client portal to avoid losing it.</p>',
            ],
            'password_changed' => [
                'audience' => 'client', 'icon' => 'fa-key', 'color' => '#2563eb', 'email' => true,
                'title'   => 'Your password was changed',
                'message' => 'If this wasn\'t you, contact support immediately.',
                'email_subject' => 'Security alert: your Orbit Cloud password was changed',
                'email_body'    => '<p>Hi {client_name},</p><p>This is a confirmation that the password for your Orbit Cloud client portal account was just changed.</p><p style="color:#64748b;font-size:13px">If you did not make this change, please contact support immediately.</p>',
            ],

            'two_factor_enabled' => [
                'audience' => 'client', 'icon' => 'fa-shield-halved', 'color' => '#2563eb', 'email' => true,
                'title'   => 'Two-factor authentication turned on',
                'message' => 'Your account now requires a code at sign-in. If this wasn\'t you, contact support immediately.',
                'email_subject' => 'Security alert: two-factor authentication enabled on your account',
                'email_body'    => '<p>Hi {client_name},</p><p>Two-factor authentication was just turned on for your Orbit Cloud client portal account. Sign-in now requires a code from your authenticator app in addition to your password.</p><p style="color:#64748b;font-size:13px">If you did not make this change, please contact support immediately.</p>',
            ],
            'two_factor_disabled' => [
                'audience' => 'client', 'icon' => 'fa-shield-halved', 'color' => '#d97706', 'email' => true,
                'title'   => 'Two-factor authentication turned off',
                'message' => 'If this wasn\'t you, contact support immediately.',
                'email_subject' => 'Security alert: two-factor authentication disabled on your account',
                'email_body'    => '<p>Hi {client_name},</p><p>Two-factor authentication was just turned off for your Orbit Cloud client portal account.</p><p style="color:#64748b;font-size:13px">If you did not make this change, please contact support immediately.</p>',
            ],

            /* ══════════════ ADMIN ANNOUNCEMENTS ══════════════ */
            'admin_announcement' => [
                // {subject}/{body_html} are the admin's own composed content
                // (admin/clients/announce.php) — every other type's copy
                // lives in this registry, but an announcement's whole point
                // is that admin writes it fresh each time.
                'audience' => 'client', 'icon' => 'fa-bullhorn', 'color' => '#2563eb', 'email' => true, 'category' => 'announcement',
                'title'   => '{subject}',
                'message' => '{excerpt}',
                'email_subject' => '{subject}',
                'email_body'    => '<p>Hi {client_name},</p>{body_html}',
            ],
        ];
    }

    public static function get(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }
}
