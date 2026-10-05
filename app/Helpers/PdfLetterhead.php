<?php

namespace App\Helpers;

use App\Models\Config\Setting;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** The company's name, tagline and logo for PDF headers. The logo is embedded so DomPDF needs no HTTP. */
class PdfLetterhead
{
    /** @return array{company: \Illuminate\Support\Collection, logo: ?string} */
    public static function get(): array
    {
        $company = Setting::whereIn('key', ['company.name', 'company.abbreviation', 'company.tagline', 'company.logo_url'])
            ->get()
            ->mapWithKeys(fn ($s) => [ltrim(strstr($s->key, '.'), '.') => $s->value]);

        $logo = null;
        if ($key = $company->get('logo_url')) {
            try {
                if ($content = Storage::disk('common')->get($key)) {
                    $logo = 'data:' . (Storage::disk('common')->mimeType($key) ?: 'image/png') . ';base64,' . base64_encode($content);
                }
            } catch (Throwable) {
                // Logo unavailable: render without it.
            }
        }

        return ['company' => $company, 'logo' => $logo];
    }
}
