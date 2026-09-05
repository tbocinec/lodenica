<?php

namespace Database\Seeders;

use App\Services\SiteConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default content pages for a fresh installation (INST-001). Inserts a page
 * only when its settings row is missing, so an admin's edits survive every
 * deploy. Templates live in database/seeders/content/ and use
 * {{siteName}}, {{clubName}}, {{contactEmail}} and {{rulesUrl}}; a link
 * whose target resolved to nothing is unwrapped to plain text.
 */
class ContentSeeder extends Seeder
{
    private const PAGES = [
        'reservation_rules' => 'reservation-rules.html',
        'faq' => 'faq.html',
        'privacy_policy' => 'privacy-policy.html',
    ];

    public function run(): void
    {
        $site = app(SiteConfig::class);
        $vars = [
            'siteName' => $site->siteName(),
            'clubName' => (string) $site->get('clubName') ?: $site->siteName(),
            'contactEmail' => (string) $site->contactEmail(),
            'rulesUrl' => (string) $site->get('rulesUrl'),
        ];

        foreach (self::PAGES as $key => $file) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }

            $template = (string) file_get_contents(__DIR__.'/content/'.$file);
            DB::table('settings')->insert([
                'key' => $key,
                'value' => self::render($template, $vars),
                'updatedAt' => now(),
            ]);
            $this->command?->info("Seeded content page '{$key}'.");
        }
    }

    /**
     * @param  array<string, string>  $vars
     */
    public static function render(string $template, array $vars): string
    {
        $html = preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            fn (array $m) => e($vars[$m[1]] ?? ''),
            $template,
        ) ?? $template;

        // <a href=""> or <a href="mailto:"> → just the link text.
        return preg_replace('#<a\b[^>]*href="(?:mailto:)?"[^>]*>(.*?)</a>#is', '$1', $html) ?? $html;
    }
}
