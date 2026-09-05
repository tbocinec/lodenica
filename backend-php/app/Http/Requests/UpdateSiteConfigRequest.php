<?php

namespace App\Http\Requests;

use App\Services\SiteConfig;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial update of the site configuration (SITE-003). Every field is
 * optional and nullable (null = back to the default); unknown keys are
 * rejected so a typo in the SPA cannot be silently dropped.
 */
class UpdateSiteConfigRequest extends FormRequest
{
    private const URL = ['sometimes', 'nullable', 'url:http,https', 'max:500'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'siteName' => ['sometimes', 'nullable', 'string', 'min:1', 'max:80'],
            'shortName' => ['sometimes', 'nullable', 'string', 'min:1', 'max:40'],
            'clubName' => ['sometimes', 'nullable', 'string', 'max:160'],
            'contactEmail' => ['sometimes', 'nullable', 'email', 'max:200'],
            'adminEmail' => ['sometimes', 'nullable', 'email', 'max:200'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'mapsUrl' => self::URL,
            'websiteUrl' => self::URL,
            'rulesUrl' => self::URL,
            'gdprNoticeUrl' => self::URL,
            'gdprConsentUrl' => self::URL,
            'statutesUrl' => self::URL,
            'operatorNotice' => ['sometimes', 'nullable', 'string', 'max:500'],
            'memberIdExample' => ['sometimes', 'nullable', 'string', 'max:40'],
            'features' => ['sometimes', 'array'],
            'features.paddlingTrafficLight' => ['sometimes', 'nullable', 'boolean'],
            'features.expeditions' => ['sometimes', 'nullable', 'boolean'],
            'theme' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z][a-z0-9-]{1,31}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $allowed = [...SiteConfig::fieldNames(), 'features'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $unknown) {
                $v->errors()->add((string) $unknown, "Neznáme pole „{$unknown}“.");
            }

            $features = $this->input('features');
            if (is_array($features)) {
                foreach (array_diff(array_keys($features), SiteConfig::featureNames()) as $unknown) {
                    $v->errors()->add("features.{$unknown}", "Neznámy modul „{$unknown}“.");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            '*.email' => 'Zadajte platnú e-mailovú adresu.',
            '*.url' => 'Zadajte platnú adresu začínajúcu http:// alebo https://.',
            'theme.regex' => 'Kľúč témy môže obsahovať len malé písmená, číslice a pomlčky.',
        ];
    }
}
