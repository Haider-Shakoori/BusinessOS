<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class DocumentThemeService
{
    public function __construct(
        private readonly BusinessSettings $settings,
        private readonly BusinessContext $context,
    ) {
        //
    }

    /**
     * Resolve a document theme exclusively through the static registry.
     *
     * A tampered/legacy database value can never become a Blade view path.
     *
     * @return array{key: string, label: string, view: string}
     */
    public function resolve(string $document): array
    {
        $definition = config('document_themes.documents.'.$document, []);
        $themes = $definition['themes'] ?? [];
        $default = (string) ($definition['default'] ?? '');

        $settingKey = $definition['setting'] ?? null;
        $selected = is_string($settingKey)
            ? (string) $this->settings->get($settingKey, $default)
            : $default;

        if (! isset($themes[$selected])) {
            $selected = $default;
        }

        if (! isset($themes[$selected])) {
            abort(500, 'No registered document theme is available.');
        }

        return [
            'key' => $selected,
            'label' => (string) ($themes[$selected]['label'] ?? $selected),
            'view' => (string) $themes[$selected]['view'],
        ];
    }

    /**
     * @return array<string, array{label: string, view: string}>
     */
    public function themes(string $document): array
    {
        return config('document_themes.documents.'.$document.'.themes', []);
    }

    /**
     * Settings shared by invoice themes today and future document renderers.
     *
     * @return array<string, mixed>
     */
    public function presentation(bool $forPdf = false): array
    {
        $accent = (string) $this->settings->get(
            'document.accent_color',
            config('document_themes.default_accent', '#2563EB'),
        );

        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) {
            $accent = (string) config('document_themes.default_accent', '#2563EB');
        }

        return [
            'accent_color' => strtoupper($accent),
            'header_text' => $this->settings->get('document.header_text'),
            'footer_text' => $this->settings->get('document.footer_text'),
            'terms' => $this->settings->get('document.terms'),
            'bank_details' => $this->settings->get('document.bank_details'),
            'signature_line' => $this->settings->get('document.signature_line'),
            'logo_path' => $this->settings->get('document.logo_path'),
            'logo_url' => $this->logoUrl(),
            'logo_data_uri' => $forPdf ? $this->logoDataUri() : null,
            'business' => $this->context->current(),
            'profile' => [
                'address' => $this->settings->get('general.address'),
                'phone' => $this->settings->get('general.phone'),
                'email' => $this->settings->get('general.email'),
            ],
        ];
    }

    public function logoUrl(): ?string
    {
        $path = $this->settings->get('document.logo_path');

        if (! is_string($path) || $path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Embed the locally stored logo for PDF generation without enabling
     * Dompdf remote access. Unsupported or missing files fail closed.
     */
    public function logoDataUri(): ?string
    {
        $path = $this->settings->get('document.logo_path');

        if (! is_string($path) || $path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path);
        $allowed = ['image/png', 'image/jpeg', 'image/webp'];

        if (! is_string($mime) || ! in_array($mime, $allowed, true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
    }
}
