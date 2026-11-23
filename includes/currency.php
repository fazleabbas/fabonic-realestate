<?php
/**
 * FIS Real Estate — Multi-Currency Support
 * Handles currency conversion, exchange rates, and display formatting.
 *
 * Supported currencies: TZS, USD, KES, UGX
 * Exchange rates stored in tenant settings as JSON.
 */

// ============================================================
// Currency Configuration
// ============================================================

/**
 * Get all supported currencies with metadata
 */
function getSupportedCurrencies() {
    return [
        'TZS' => [
            'name' => 'Tanzanian Shilling',
            'symbol' => 'TZS',
            'code' => 'TZS',
            'decimals' => 0,
            'position' => 'before',   // symbol before amount
            'thousand_sep' => ',',
            'decimal_sep' => '.',
            'flag' => '🇹🇿'
        ],
        'USD' => [
            'name' => 'US Dollar',
            'symbol' => '$',
            'code' => 'USD',
            'decimals' => 2,
            'position' => 'before',
            'thousand_sep' => ',',
            'decimal_sep' => '.',
            'flag' => '🇺🇸'
        ],
        'KES' => [
            'name' => 'Kenyan Shilling',
            'symbol' => 'KES',
            'code' => 'KES',
            'decimals' => 0,
            'position' => 'before',
            'thousand_sep' => ',',
            'decimal_sep' => '.',
            'flag' => '🇰🇪'
        ],
        'UGX' => [
            'name' => 'Ugandan Shilling',
            'symbol' => 'UGX',
            'code' => 'UGX',
            'decimals' => 0,
            'position' => 'before',
            'thousand_sep' => ',',
            'decimal_sep' => '.',
            'flag' => '🇺🇬'
        ]
    ];
}

/**
 * Get currencies enabled for this tenant
 */
function getEnabledCurrencies() {
    $json = getSetting('supported_currencies_json', '["TZS"]');
    $enabled = json_decode($json, true);
    if (!is_array($enabled)) return ['TZS'];
    return $enabled;
}

/**
 * Get currency metadata for a specific currency code
 */
function getCurrencyInfo($code) {
    $currencies = getSupportedCurrencies();
    return $currencies[$code] ?? $currencies['TZS'];
}

// ============================================================
// Exchange Rates
// ============================================================

/**
 * Get exchange rates relative to TZS (base currency)
 * Stored in tenant settings as JSON: {"USD": 2500, "KES": 20, "UGX": 0.7}
 * Value = how many TZS per 1 unit of foreign currency
 *
 * Example: USD rate of 2500 means 1 USD = 2,500 TZS
 */
function getExchangeRates() {
    $json = getSetting('exchange_rates_json', '{}');
    $rates = json_decode($json, true);
    if (!is_array($rates)) $rates = [];

    // TZS is always 1:1
    $rates['TZS'] = 1;

    // Defaults if not set
    $defaults = [
        'USD' => 2500,
        'KES' => 18,
        'UGX' => 0.68
    ];

    foreach ($defaults as $code => $defaultRate) {
        if (!isset($rates[$code])) {
            $rates[$code] = $defaultRate;
        }
    }

    return $rates;
}

/**
 * Get a single exchange rate (TZS per 1 unit of the given currency)
 */
function getExchangeRate($currencyCode) {
    $rates = getExchangeRates();
    return $rates[$currencyCode] ?? 1;
}

/**
 * Save exchange rates to tenant settings
 */
function saveExchangeRates($rates) {
    // Ensure TZS is always 1
    $rates['TZS'] = 1;
    return updateSetting('exchange_rates_json', json_encode($rates));
}

// ============================================================
// Currency Conversion
// ============================================================

/**
 * Convert an amount from one currency to another
 *
 * @param float $amount Amount in source currency
 * @param string $fromCurrency Source currency code
 * @param string $toCurrency Target currency code
 * @return float Converted amount
 */
function convertCurrency($amount, $fromCurrency, $toCurrency) {
    if ($fromCurrency === $toCurrency) return $amount;

    $rates = getExchangeRates();
    $fromRate = $rates[$fromCurrency] ?? 1;
    $toRate = $rates[$toCurrency] ?? 1;

    // Convert to TZS first, then to target currency
    $amountInTZS = $amount * $fromRate;
    $converted = $amountInTZS / $toRate;

    return round($converted, getCurrencyInfo($toCurrency)['decimals']);
}

/**
 * Convert amount to the tenant's default currency
 */
function convertToDefault($amount, $fromCurrency) {
    return convertCurrency($amount, $fromCurrency, getSetting('default_currency', 'TZS'));
}

// ============================================================
// Display Formatting
// ============================================================

/**
 * Format a monetary amount with full currency formatting
 *
 * @param float $amount Amount
 * @param string|null $currencyCode Currency code (null = default)
 * @param int|null $decimals Override decimals
 * @return string Formatted string e.g. "TZS 1,500,000" or "$600.00"
 */
function formatMoney($amount, $currencyCode = null, $decimals = null) {
    $currencyCode = $currencyCode ?? getSetting('default_currency', DEFAULT_CURRENCY);
    $info = getCurrencyInfo($currencyCode);

    $decimals = $decimals ?? $info['decimals'];
    $formatted = number_format(
        abs($amount),
        $decimals,
        $info['decimal_sep'],
        $info['thousand_sep']
    );

    $sign = $amount < 0 ? '-' : '';

    if ($info['position'] === 'before') {
        return $sign . $info['symbol'] . ' ' . $formatted;
    } else {
        return $sign . $formatted . ' ' . $info['symbol'];
    }
}

/**
 * Format amount with currency code suffix (for reports/exports)
 * e.g. "1,500,000 TZS" or "600.00 USD"
 */
function formatMoneyCode($amount, $currencyCode = null) {
    $currencyCode = $currencyCode ?? getSetting('default_currency', DEFAULT_CURRENCY);
    $info = getCurrencyInfo($currencyCode);
    $formatted = number_format(abs($amount), $info['decimals'], $info['decimal_sep'], $info['thousand_sep']);
    $sign = $amount < 0 ? '-' : '';
    return $sign . $formatted . ' ' . $currencyCode;
}

/**
 * Generate a currency selector dropdown options HTML
 *
 * @param string $selected Currently selected currency code
 * @param bool $enabledOnly Only show enabled currencies
 * @return string HTML <option> tags
 */
function currencyOptions($selected = null, $enabledOnly = true) {
    $selected = $selected ?? getSetting('default_currency', DEFAULT_CURRENCY);
    $all = getSupportedCurrencies();
    $enabled = $enabledOnly ? getEnabledCurrencies() : array_keys($all);

    $html = '';
    foreach ($enabled as $code) {
        if (!isset($all[$code])) continue;
        $info = $all[$code];
        $sel = ($code === $selected) ? ' selected' : '';
        $html .= "<option value=\"{$code}\"{$sel}>{$info['flag']} {$code} — {$info['name']}</option>\n";
    }
    return $html;
}

/**
 * Get currency symbol for display
 */
function getCurrencySymbol($code = null) {
    $code = $code ?? getSetting('default_currency', DEFAULT_CURRENCY);
    $info = getCurrencyInfo($code);
    return $info['symbol'];
}
