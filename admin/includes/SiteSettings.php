<?php
/**
 * OrbitHost — Site Settings (branding, header, business info, footer, contact page)
 *
 * Same pattern as ProviderRegistry/Provider: a field-schema registry drives
 * the admin UI, settings persist as one JSON blob per section, and the
 * public website reads them back via api/site-settings.php.
 */
require_once __DIR__ . '/db.php';

final class SiteSettings
{
    private const UPLOAD_DIR = '/uploads/branding/';
    private const MAX_BYTES  = 2 * 1024 * 1024; // 2MB
    private const MIME_EXT   = [
        'image/png'              => 'png',
        'image/jpeg'             => 'jpg',
        'image/gif'              => 'gif',
        'image/webp'             => 'webp',
        'image/x-icon'           => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    /** @return array<string,array> field schema keyed by section */
    public static function sections(): array
    {
        return [
            'branding' => [
                'label' => 'Branding', 'icon' => 'fa-swatchbook',
                'hint'  => 'Your logo, favicon and brand name shown across the whole website.',
                'fields' => [
                    ['key'=>'site_name_primary', 'label'=>'Brand name — part 1', 'type'=>'text', 'default'=>'Orbit', 'hint'=>'e.g. "Orbit" in "OrbitHost".'],
                    ['key'=>'site_name_accent',  'label'=>'Brand name — part 2 (accent color)', 'type'=>'text', 'default'=>'Host'],
                    ['key'=>'logo_image',    'label'=>'Logo image',    'type'=>'image', 'hint'=>'Optional. Replaces the text logo everywhere. PNG/WebP with transparent background recommended. Max 2MB.'],
                    ['key'=>'favicon_image', 'label'=>'Favicon',       'type'=>'image', 'hint'=>'Optional. Square PNG or ICO, at least 32×32px. Max 2MB.'],
                ],
            ],
            'header' => [
                'label' => 'Top Header', 'icon' => 'fa-window-maximize',
                'hint'  => 'The promo bar and phone number shown at the very top of every page.',
                'fields' => [
                    ['key'=>'announcement_enabled',  'label'=>'Show announcement bar', 'type'=>'toggle', 'default'=>true, 'hint'=>'This one bar replaces the promo text that used to be hardcoded differently on each page.'],
                    ['key'=>'announcement_text',     'label'=>'Announcement text',     'type'=>'text', 'default'=>'Get 30% OFF all hosting plans for the first year — Use code ORBIT30'],
                    ['key'=>'announcement_link_text','label'=>'Announcement button text', 'type'=>'text', 'default'=>'Claim Offer'],
                    ['key'=>'announcement_link_url', 'label'=>'Announcement button link', 'type'=>'text', 'default'=>'hosting/shared.html'],
                    ['key'=>'show_header_phone',      'label'=>'Show phone number in header', 'type'=>'toggle', 'default'=>false],
                ],
            ],
            'business' => [
                'label' => 'Business Info', 'icon' => 'fa-building',
                'hint'  => 'Shared contact details used in the header, footer and contact page.',
                'fields' => [
                    ['key'=>'phone',         'label'=>'Phone number',      'type'=>'text', 'default'=>'+254 700 000 000'],
                    ['key'=>'whatsapp',      'label'=>'WhatsApp number',   'type'=>'text', 'placeholder'=>'+254 700 000 000', 'hint'=>'Optional — leave blank to hide WhatsApp.'],
                    ['key'=>'general_email', 'label'=>'General enquiries email', 'type'=>'text', 'default'=>'info@orbithost.com'],
                    ['key'=>'support_email', 'label'=>'Technical support email', 'type'=>'text', 'default'=>'support@orbithost.com'],
                    ['key'=>'sales_email',   'label'=>'Sales email',      'type'=>'text', 'default'=>'sales@orbithost.com'],
                    ['key'=>'address_line',  'label'=>'Address',          'type'=>'text', 'default'=>'Nairobi, Kenya'],
                ],
            ],
            'footer' => [
                'label' => 'Footer', 'icon' => 'fa-shoe-prints',
                'hint'  => 'The about text, copyright line and social links shown in the site footer.',
                'fields' => [
                    ['key'=>'about_text',      'label'=>'About text',      'type'=>'textarea', 'default'=>'OrbitHost delivers enterprise-grade hosting infrastructure, 99.9% uptime, and expert 24/7 support to businesses across Africa and beyond.'],
                    ['key'=>'copyright_text',  'label'=>'Copyright line',  'type'=>'text', 'default'=>'© {year} OrbitHost Ltd. All rights reserved.', 'hint'=>'Use {year} to always show the current year.'],
                    ['key'=>'social_facebook', 'label'=>'Facebook URL',   'type'=>'text', 'placeholder'=>'https://facebook.com/…'],
                    ['key'=>'social_twitter',  'label'=>'X / Twitter URL','type'=>'text', 'placeholder'=>'https://x.com/…'],
                    ['key'=>'social_linkedin', 'label'=>'LinkedIn URL',   'type'=>'text', 'placeholder'=>'https://linkedin.com/company/…'],
                    ['key'=>'social_instagram','label'=>'Instagram URL',  'type'=>'text', 'placeholder'=>'https://instagram.com/…'],
                ],
            ],
            'contact' => [
                'label' => 'Contact Page', 'icon' => 'fa-address-card',
                'hint'  => 'Content shown on the Contact Us page.',
                'fields' => [
                    ['key'=>'hero_heading',     'label'=>'Page heading',     'type'=>'text', 'default'=>'Contact OrbitHost Support'],
                    ['key'=>'hero_subtext',     'label'=>'Page subtext',     'type'=>'textarea', 'default'=>'Our certified hosting specialists are available around the clock. Choose the support channel that works best for you.'],
                    ['key'=>'office1_title',    'label'=>'Office 1 — title',   'type'=>'text', 'default'=>'Head Office — Nairobi'],
                    ['key'=>'office1_address',  'label'=>'Office 1 — address', 'type'=>'textarea', 'default'=>"Upper Hill Business Park,\nNairobi, Kenya"],
                    ['key'=>'office2_title',    'label'=>'Office 2 — title (optional)',   'type'=>'text', 'default'=>'European Office — London'],
                    ['key'=>'office2_address',  'label'=>'Office 2 — address (optional)', 'type'=>'textarea', 'default'=>"20 Fenchurch Street,\nLondon, EC3M 3BY, UK"],
                ],
            ],
        ];
    }

    public static function ensureTable(): bool
    {
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS site_settings (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                section    VARCHAR(50)  NOT NULL UNIQUE,
                settings   JSON         NOT NULL,
                updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Saved config for a section, merged with registry defaults. */
    public static function get(string $section): array
    {
        $def = self::sections()[$section] ?? null;
        if (!$def) return [];

        $saved = [];
        try {
            $stmt = db()->prepare('SELECT settings FROM site_settings WHERE section = ?');
            $stmt->execute([$section]);
            $json = $stmt->fetchColumn();
            if ($json) $saved = json_decode($json, true) ?: [];
        } catch (\Throwable $e) { /* table missing — defaults only */ }

        $out = [];
        foreach ($def['fields'] as $f) {
            $out[$f['key']] = $saved[$f['key']] ?? ($f['default'] ?? '');
        }
        return $out;
    }

    /** All sections, merged with defaults — for the public API. */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::sections()) as $section) {
            $out[$section] = self::get($section);
        }
        return $out;
    }

    public static function save(string $section, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        db()->prepare('REPLACE INTO site_settings (section, settings) VALUES (?, ?)')->execute([$section, $json]);
    }

    /**
     * Validate + store an uploaded image. Returns the public URL path
     * (e.g. "/uploads/branding/logo-6512ab3f.png") or null on failure.
     * Filename is fully server-generated — never derived from client input.
     */
    public static function handleUpload(array $file, string $fieldKey, ?string &$error): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null; // no new file chosen — caller keeps the existing value
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload failed (error code ' . $file['error'] . ').';
            return null;
        }
        if ($file['size'] > self::MAX_BYTES) {
            $error = 'Image is larger than 2MB.';
            return null;
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            $error = 'Invalid upload.';
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $ext   = self::MIME_EXT[$mime] ?? null;
        if (!$ext) {
            $error = 'Unsupported image type. Use PNG, JPG, GIF, WebP or ICO.';
            return null;
        }
        // Confirm it's really a decodable raster image (skip for .ico — getimagesize is unreliable for it)
        if ($ext !== 'ico' && @getimagesize($file['tmp_name']) === false) {
            $error = 'That file is not a valid image.';
            return null;
        }

        $dir = dirname(__DIR__, 2) . self::UPLOAD_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $name = preg_replace('/[^a-z0-9]/', '', strtolower($fieldKey)) . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $error = 'Could not save the uploaded file.';
            return null;
        }
        return self::UPLOAD_DIR . $name;
    }

    /** Delete a previously uploaded branding file (best-effort, ignores missing files). */
    public static function deleteUpload(?string $path): void
    {
        if (!$path || !str_starts_with($path, self::UPLOAD_DIR)) return;
        $full = dirname(__DIR__, 2) . $path;
        if (is_file($full)) @unlink($full);
    }
}
