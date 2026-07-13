<?php
/**
 * OrbitHost — Provider Registry
 *
 * Single source of truth for every third-party integration. Each entry
 * declares its category, branding, the adapter that talks to its API, and
 * a `fields` schema. The Providers hub renders config forms straight from
 * this schema, so adding a new provider is: add one array here + implement
 * its adapter methods. No UI or form code to touch.
 *
 * Field types: text | secret | number | toggle | select | textarea
 */
final class ProviderRegistry
{
    /** @return array<string,array> keyed by provider key */
    public static function all(): array
    {
        return [

            /* ═══════════ HOSTING CONTROL PANELS ═══════════ */
            'whm' => [
                'key'         => 'whm',
                'name'        => 'WHM / cPanel',
                'category'    => 'panel',
                'icon'        => 'fa-server',
                'color'       => '#ff6c2c',
                'tagline'     => 'Provision and manage cPanel hosting accounts.',
                'client'      => 'PanelClient',
                'docs'        => 'https://api.docs.cpanel.net/whm/',
                'fields'      => [
                    ['key'=>'host',       'label'=>'WHM Host / IP',   'type'=>'text',   'required'=>true, 'placeholder'=>'server.orbithost.co.ke', 'hint'=>'Hostname or IP only — no https:// and no :2087 (added automatically).'],
                    ['key'=>'user',       'label'=>'Username',        'type'=>'text',   'default'=>'root'],
                    ['key'=>'token',      'label'=>'API Token',       'type'=>'secret', 'required'=>true, 'hint'=>'WHM › Development › Manage API Tokens.'],
                    ['key'=>'port',       'label'=>'Port',            'type'=>'number', 'default'=>2087],
                    ['key'=>'ssl_verify', 'label'=>'Verify SSL certificate', 'type'=>'toggle', 'default'=>false, 'hint'=>'Leave off for self-signed certs.'],
                ],
            ],
            'plesk' => [
                'key'      => 'plesk',
                'name'     => 'Plesk',
                'category' => 'panel',
                'icon'     => 'fa-cubes',
                'color'    => '#52bab3',
                'tagline'  => 'Provision hosting subscriptions on a Plesk server.',
                'client'   => 'PanelClient',
                'docs'     => 'https://docs.plesk.com/en-US/obsidian/api-rpc/',
                'fields'   => [
                    ['key'=>'host',       'label'=>'Plesk Host / IP', 'type'=>'text',   'required'=>true, 'placeholder'=>'plesk.orbithost.co.ke'],
                    ['key'=>'user',       'label'=>'Admin Username',  'type'=>'text',   'default'=>'admin'],
                    ['key'=>'password',   'label'=>'Admin Password / API Key', 'type'=>'secret', 'required'=>true],
                    ['key'=>'port',       'label'=>'Port',            'type'=>'number', 'default'=>8443],
                    ['key'=>'ssl_verify', 'label'=>'Verify SSL certificate', 'type'=>'toggle', 'default'=>false],
                ],
            ],
            'directadmin' => [
                'key'      => 'directadmin',
                'name'     => 'DirectAdmin',
                'category' => 'panel',
                'icon'     => 'fa-gauge-high',
                'color'    => '#2f8fd8',
                'tagline'  => 'Provision hosting accounts on a DirectAdmin server.',
                'client'   => 'PanelClient',
                'docs'     => 'https://docs.directadmin.com/developer/api/',
                'fields'   => [
                    ['key'=>'host',       'label'=>'DirectAdmin Host / IP', 'type'=>'text',   'required'=>true, 'placeholder'=>'da.orbithost.co.ke'],
                    ['key'=>'user',       'label'=>'Admin Username',   'type'=>'text',   'required'=>true, 'default'=>'admin'],
                    ['key'=>'token',      'label'=>'Login Key / Password', 'type'=>'secret', 'required'=>true],
                    ['key'=>'port',       'label'=>'Port',             'type'=>'number', 'default'=>2222],
                    ['key'=>'ssl_verify', 'label'=>'Verify SSL certificate', 'type'=>'toggle', 'default'=>false],
                ],
            ],

            /* ═══════════ DOMAIN REGISTRARS ═══════════ */
            'namecheap' => [
                'key'      => 'namecheap',
                'name'     => 'Namecheap',
                'category' => 'registrar',
                'icon'     => 'fa-globe',
                'color'    => '#d4202c',
                'tagline'  => 'Register, renew and manage domains via Namecheap.',
                'client'   => 'DomainClient',
                'docs'     => 'https://www.namecheap.com/support/api/methods/',
                'fields'   => [
                    ['key'=>'api_user',  'label'=>'API User',  'type'=>'text',   'required'=>true, 'placeholder'=>'Your Namecheap username'],
                    ['key'=>'api_key',   'label'=>'API Key',   'type'=>'secret', 'required'=>true],
                    ['key'=>'client_ip', 'label'=>'Whitelisted IP', 'type'=>'text', 'placeholder'=>'Your server public IP', 'hint'=>'Must be whitelisted in Namecheap API settings.'],
                    ['key'=>'sandbox',   'label'=>'Sandbox mode', 'type'=>'toggle', 'default'=>true],
                ],
            ],
            'godaddy' => [
                'key'      => 'godaddy',
                'name'     => 'GoDaddy',
                'category' => 'registrar',
                'icon'     => 'fa-globe',
                'color'    => '#1bab6b',
                'tagline'  => 'Register and manage domains via the GoDaddy API.',
                'client'   => 'DomainClient',
                'docs'     => 'https://developer.godaddy.com/doc/endpoint/domains',
                'fields'   => [
                    ['key'=>'api_key',    'label'=>'API Key',    'type'=>'secret', 'required'=>true],
                    ['key'=>'api_secret', 'label'=>'API Secret', 'type'=>'secret', 'required'=>true],
                    ['key'=>'sandbox',    'label'=>'OTE / Sandbox', 'type'=>'toggle', 'default'=>true],
                ],
            ],
            'enom' => [
                'key'      => 'enom',
                'name'     => 'Enom',
                'category' => 'registrar',
                'icon'     => 'fa-globe',
                'color'    => '#0a67a3',
                'tagline'  => 'Wholesale domain registration via the Enom reseller API.',
                'client'   => 'DomainClient',
                'docs'     => 'https://api.enom.com/docs',
                'fields'   => [
                    ['key'=>'uid',      'label'=>'Reseller Login (UID)', 'type'=>'text',   'required'=>true],
                    ['key'=>'password', 'label'=>'API Password',         'type'=>'secret', 'required'=>true],
                    ['key'=>'sandbox',  'label'=>'Test environment',     'type'=>'toggle', 'default'=>true],
                ],
            ],
            'resellerclub' => [
                'key'      => 'resellerclub',
                'name'     => 'ResellerClub',
                'category' => 'registrar',
                'icon'     => 'fa-globe',
                'color'    => '#f26722',
                'tagline'  => 'Domains + hosting reseller platform (LogicBoxes).',
                'client'   => 'DomainClient',
                'docs'     => 'https://manage.resellerclub.com/kb/answer/751',
                'fields'   => [
                    ['key'=>'auth_userid', 'label'=>'Reseller ID', 'type'=>'text',   'required'=>true],
                    ['key'=>'api_key',     'label'=>'API Key',     'type'=>'secret', 'required'=>true],
                    ['key'=>'sandbox',     'label'=>'Test / demo mode', 'type'=>'toggle', 'default'=>true],
                ],
            ],
            'netearthone' => [
                'key'      => 'netearthone',
                'name'     => 'NetEarthOne',
                'category' => 'registrar',
                'icon'     => 'fa-earth-africa',
                'color'    => '#1d4ed8',
                'tagline'  => 'Wholesale domains via the NetEarthOne (LogicBoxes) reseller API — with TLD pricing sync.',
                'client'   => 'DomainClient',
                'docs'     => 'https://manage.netearthone.com/kb/answer/751',
                'fields'   => [
                    ['key'=>'auth_userid', 'label'=>'Reseller ID',  'type'=>'text',   'required'=>true, 'hint'=>'NetEarthOne control panel › Settings › API.'],
                    ['key'=>'api_key',     'label'=>'API Key',      'type'=>'secret', 'required'=>true, 'hint'=>'Your server IP must be whitelisted in the API settings.'],
                    ['key'=>'api_base',    'label'=>'API Endpoint', 'type'=>'text',   'default'=>'https://httpapi.com/api', 'hint'=>'LogicBoxes endpoint. Change only if NetEarthOne gives you a brand-specific URL.'],
                    ['key'=>'default_ns',  'label'=>'Default Nameservers', 'type'=>'text', 'placeholder'=>'ns1.orbitcloud.co.ke, ns2.orbitcloud.co.ke', 'hint'=>'Comma-separated. Used when registering domains for clients.'],
                    ['key'=>'sandbox',     'label'=>'Test / demo mode', 'type'=>'toggle', 'default'=>true],
                ],
            ],
            'cloudflare' => [
                'key'      => 'cloudflare',
                'name'     => 'Cloudflare Registrar',
                'category' => 'registrar',
                'icon'     => 'fa-cloud',
                'color'    => '#f38020',
                'tagline'  => 'At-cost domain registration + DNS via Cloudflare.',
                'client'   => 'DomainClient',
                'docs'     => 'https://developers.cloudflare.com/api/',
                'fields'   => [
                    ['key'=>'api_token', 'label'=>'API Token',   'type'=>'secret', 'required'=>true, 'hint'=>'Scoped token with Domain + DNS edit permissions.'],
                    ['key'=>'account_id','label'=>'Account ID',   'type'=>'text',   'required'=>true],
                ],
            ],

            /* ═══════════ PAYMENT GATEWAYS ═══════════ */
            'stripe' => [
                'key'      => 'stripe',
                'name'     => 'Stripe',
                'category' => 'payment',
                'icon'     => 'fa-credit-card',
                'color'    => '#635bff',
                'tagline'  => 'Accept card payments worldwide via Stripe Checkout.',
                'client'   => 'PaymentClient',
                'docs'     => 'https://stripe.com/docs/api',
                'fields'   => [
                    ['key'=>'publishable_key', 'label'=>'Publishable Key', 'type'=>'text',   'placeholder'=>'pk_live_…'],
                    ['key'=>'secret_key',      'label'=>'Secret Key',      'type'=>'secret', 'required'=>true, 'placeholder'=>'sk_live_…'],
                    ['key'=>'webhook_secret',  'label'=>'Webhook Signing Secret', 'type'=>'secret', 'placeholder'=>'whsec_…'],
                    ['key'=>'currency',        'label'=>'Currency',        'type'=>'text',   'default'=>'USD'],
                    ['key'=>'sandbox',         'label'=>'Test mode',       'type'=>'toggle', 'default'=>true],
                ],
            ],
            'paypal' => [
                'key'      => 'paypal',
                'name'     => 'PayPal',
                'category' => 'payment',
                'icon'     => 'fa-brands fa-paypal',
                'color'    => '#003087',
                'tagline'  => 'Accept PayPal and card payments via PayPal Orders.',
                'client'   => 'PaymentClient',
                'docs'     => 'https://developer.paypal.com/api/rest/',
                'fields'   => [
                    ['key'=>'client_id',     'label'=>'Client ID',     'type'=>'text',   'required'=>true],
                    ['key'=>'client_secret', 'label'=>'Client Secret', 'type'=>'secret', 'required'=>true],
                    ['key'=>'currency',      'label'=>'Currency',      'type'=>'text',   'default'=>'USD'],
                    ['key'=>'sandbox',       'label'=>'Sandbox mode',  'type'=>'toggle', 'default'=>true],
                ],
            ],
            'mpesa' => [
                'key'      => 'mpesa',
                'name'     => 'M-Pesa (Daraja)',
                'category' => 'payment',
                'icon'     => 'fa-mobile-screen',
                'color'    => '#4caf50',
                'tagline'  => 'Safaricom M-Pesa STK Push for Kenyan customers.',
                'client'   => 'PaymentClient',
                'docs'     => 'https://developer.safaricom.co.ke/APIs',
                'fields'   => [
                    ['key'=>'consumer_key',    'label'=>'Consumer Key',    'type'=>'text',   'required'=>true],
                    ['key'=>'consumer_secret', 'label'=>'Consumer Secret', 'type'=>'secret', 'required'=>true],
                    ['key'=>'shortcode',       'label'=>'Business Shortcode', 'type'=>'text', 'required'=>true, 'placeholder'=>'174379'],
                    ['key'=>'passkey',         'label'=>'Lipa na M-Pesa Passkey', 'type'=>'secret', 'required'=>true],
                    ['key'=>'sandbox',         'label'=>'Sandbox mode',    'type'=>'toggle', 'default'=>true],
                ],
            ],
            'flutterwave' => [
                'key'      => 'flutterwave',
                'name'     => 'Flutterwave',
                'category' => 'payment',
                'icon'     => 'fa-money-bill-wave',
                'color'    => '#f5a623',
                'tagline'  => 'Cards, bank + mobile money across Africa.',
                'client'   => 'PaymentClient',
                'docs'     => 'https://developer.flutterwave.com/docs',
                'fields'   => [
                    ['key'=>'public_key',    'label'=>'Public Key',    'type'=>'text',   'placeholder'=>'FLWPUBK-…'],
                    ['key'=>'secret_key',    'label'=>'Secret Key',    'type'=>'secret', 'required'=>true, 'placeholder'=>'FLWSECK-…'],
                    ['key'=>'currency',      'label'=>'Currency',      'type'=>'text',   'default'=>'KES'],
                    ['key'=>'sandbox',       'label'=>'Test mode',     'type'=>'toggle', 'default'=>true],
                ],
            ],

            /* ═══════════ EMAIL ═══════════ */
            'smtp' => [
                'key'      => 'smtp',
                'name'     => 'SMTP Email',
                'category' => 'email',
                'icon'     => 'fa-envelope',
                'color'    => '#2563eb',
                'tagline'  => 'Outbound email for invoices, invites and alerts.',
                'client'   => 'Mailer',
                'docs'     => '',
                'fields'   => [
                    ['key'=>'host',       'label'=>'SMTP Host', 'type'=>'text',   'required'=>true, 'placeholder'=>'smtp.gmail.com', 'hint'=>'Leave blank to fall back to the server\'s PHP mail().'],
                    ['key'=>'port',       'label'=>'Port',      'type'=>'number', 'default'=>587, 'hint'=>'587 for TLS, 465 for SSL.'],
                    ['key'=>'username',   'label'=>'Username',  'type'=>'text',   'required'=>true],
                    ['key'=>'password',   'label'=>'Password',  'type'=>'secret', 'required'=>true],
                    ['key'=>'encryption', 'label'=>'Encryption','type'=>'select', 'options'=>['tls'=>'TLS (STARTTLS)','ssl'=>'SSL','none'=>'None'], 'default'=>'tls'],
                    ['key'=>'from_name',  'label'=>'From Name', 'type'=>'text',   'default'=>'OrbitHost'],
                    ['key'=>'from_email', 'label'=>'From Email','type'=>'text',   'placeholder'=>'noreply@orbithost.co.ke'],
                ],
            ],

        ];
    }

    /** Category metadata for grouping in the UI. */
    public static function categories(): array
    {
        return [
            'panel'     => ['label'=>'Hosting Control Panels', 'icon'=>'fa-server',       'hint'=>'Provision and manage hosting accounts.'],
            'registrar' => ['label'=>'Domain Registrars',      'icon'=>'fa-globe',        'hint'=>'Register, renew and manage domains.'],
            'payment'   => ['label'=>'Payment Gateways',       'icon'=>'fa-credit-card',  'hint'=>'Collect payments for invoices online.'],
            'email'     => ['label'=>'Email Delivery',         'icon'=>'fa-envelope',     'hint'=>'Outbound transactional email.'],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string,array> providers filtered to one category */
    public static function byCategory(string $category): array
    {
        return array_filter(self::all(), fn($p) => $p['category'] === $category);
    }

    /** Apply field defaults on top of saved config so forms/adapters always have every key. */
    public static function withDefaults(string $key, array $saved): array
    {
        $def = self::get($key);
        if (!$def) return $saved;
        $out = [];
        foreach ($def['fields'] as $f) {
            $out[$f['key']] = $saved[$f['key']] ?? ($f['default'] ?? '');
        }
        // preserve any extra saved keys not in the schema
        return array_merge($out, $saved);
    }
}
