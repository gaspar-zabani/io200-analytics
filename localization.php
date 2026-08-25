<?php

/**
 * IO200 Analytics localization bootstrap.
 *
 * Language files are IOA-owned and return associative arrays. The external
 * testing release intentionally presents an English-only interface.
 */

$ioaLanguageCode = 'en';

$ioaLoadLanguage = static function (string $languageCode): array {
    $languageFile = __DIR__ . '/lang/' . $languageCode . '.php';

    if (!is_file($languageFile)) {
        return [];
    }

    $translations = require $languageFile;

    return is_array($translations) ? $translations : [];
};

$ioaEnglishTranslations = $ioaLoadLanguage('en');

$GLOBALS['IOA_LANGUAGE_CODE'] = $ioaLanguageCode;
$GLOBALS['IOA_TRANSLATIONS'] = $ioaEnglishTranslations;

if (!function_exists('ioa_translate')) {
    function ioa_translate(string $key): string
    {
        $translations = $GLOBALS['IOA_TRANSLATIONS'] ?? [];
        $value = $translations[$key] ?? $key;

        return is_scalar($value) ? (string) $value : $key;
    }
}

if (!function_exists('ioa_language_code')) {
    function ioa_language_code(): string
    {
        return $GLOBALS['IOA_LANGUAGE_CODE'] ?? 'en';
    }
}

if (!function_exists('ioa_t')) {
    function ioa_t(string $key): string
    {
        return htmlspecialchars(ioa_translate($key), ENT_QUOTES, 'UTF-8');
    }
}
