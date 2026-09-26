<?php

namespace Tests\Feature;

use App\Services\CountryLocalizationService;
use App\Support\LocalizedFormatter;
use Illuminate\Support\Arr;
use Tests\TestCase;

class LocalizationCompletionTest extends TestCase
{
    public function test_every_english_translation_file_and_key_exists_in_dari_and_arabic(): void
    {
        $englishFiles = glob(resource_path('lang/en/*.php')) ?: [];

        $this->assertNotEmpty($englishFiles);

        foreach ($englishFiles as $englishFile) {
            $filename = basename($englishFile);
            $english = Arr::dot(require $englishFile);

            foreach (['fa', 'ar'] as $locale) {
                $localizedFile = resource_path('lang/'.$locale.'/'.$filename);

                $this->assertFileExists($localizedFile, $locale.'/'.$filename.' is missing.');

                $localized = Arr::dot(require $localizedFile);

                $missing = array_values(array_diff(array_keys($english), array_keys($localized)));

                $this->assertSame(
                    [],
                    $missing,
                    $locale.'/'.$filename.' is missing translation keys: '.implode(', ', $missing),
                );
            }
        }
    }

    public function test_supported_locale_directions_are_complete(): void
    {
        $supported = config('localization.supported');

        $this->assertSame(['en', 'fa', 'ps', 'ar'], array_keys($supported));
        $this->assertSame('ltr', $supported['en']['direction']);
        $this->assertSame('rtl', $supported['fa']['direction']);
        $this->assertSame('rtl', $supported['ps']['direction']);
        $this->assertSame('rtl', $supported['ar']['direction']);
        $this->assertSame('Dari', $supported['fa']['label']);
        $this->assertSame('دری', $supported['fa']['native']);
        $this->assertSame('Pashto', $supported['ps']['label']);
        $this->assertSame('پښتو', $supported['ps']['native']);
    }

    public function test_afghanistan_profile_is_curated_without_hard_coding_the_core(): void
    {
        $service = app(CountryLocalizationService::class);
        $afghanistan = $service->workspaceDefaults('AF');

        $this->assertSame('AF', $afghanistan['country']);
        $this->assertSame('AFN', $afghanistan['currency']);
        $this->assertSame('Asia/Kabul', $afghanistan['timezone']);
        $this->assertSame('fa', $afghanistan['locale']);
        $this->assertFalse($afghanistan['tax_enabled']);

        $this->assertSame('AED', $service->workspaceDefaults('AE')['currency']);
        $this->assertSame('USD', $service->workspaceDefaults('US')['currency']);
        $this->assertSame('CAD', $service->workspaceDefaults('CA')['currency']);
    }

    public function test_central_formatter_localizes_numbers_dates_and_currency(): void
    {
        $formatter = app(LocalizedFormatter::class);

        app()->setLocale('en');
        $englishNumber = $formatter->number('1234.50', 2);
        $englishDate = $formatter->date('2026-09-25', 'j F Y');
        $englishCurrency = $formatter->currency('1234.50', 'AFN');

        app()->setLocale('fa');
        $dariNumber = $formatter->number('1234.50', 2);
        $dariDate = $formatter->date('2026-09-25', 'j F Y');
        $dariCurrency = $formatter->currency('1234.50', 'AFN');

        $this->assertNotSame('', $englishNumber);
        $this->assertNotSame('', $dariNumber);
        $this->assertNotSame($englishNumber, $dariNumber);
        $this->assertNotSame($englishDate, $dariDate);
        $this->assertNotSame('', $englishCurrency);
        $this->assertNotSame('', $dariCurrency);
    }

    public function test_onboarding_industry_profiles_only_recommend_registered_modules(): void
    {
        $registered = array_keys(config('modules.registry', []));
        $profiles = config('onboarding_industries', []);

        $this->assertNotEmpty($profiles);

        foreach ($profiles as $industry => $profile) {
            $recommended = $profile['recommended_modules'] ?? [];
            $this->assertNotEmpty($recommended, $industry.' must recommend at least one module.');
            $this->assertSame(
                [],
                array_values(array_diff($recommended, $registered)),
                $industry.' recommends an unregistered module.',
            );
        }

        $this->assertContains('manufacturing', $profiles['manufacturing']['recommended_modules']);
        $this->assertNotContains('manufacturing', $profiles['services']['recommended_modules']);
        $this->assertContains('pos', $profiles['retail_wholesale']['recommended_modules']);
    }

    public function test_final_shell_blades_do_not_introduce_untranslated_user_facing_copy(): void
    {
        $paths = [
            resource_path('views/components/app/header.blade.php'),
            resource_path('views/components/app/sidebar.blade.php'),
            resource_path('views/components/app/nav-link.blade.php'),
            resource_path('views/components/app/business-switcher.blade.php'),
            resource_path('views/business/create.blade.php'),
        ];

        $forbidden = [
            'Coming soon',
            'Business setup',
            'Back to dashboard',
            'Search anything...',
            'Your workspace is ready!',
            'Customize your workspace',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            foreach ($forbidden as $literal) {
                $this->assertStringNotContainsString(
                    '>'.$literal.'<',
                    $source,
                    basename($path).' contains untranslated user-facing text: '.$literal,
                );
            }
        }
    }
}
