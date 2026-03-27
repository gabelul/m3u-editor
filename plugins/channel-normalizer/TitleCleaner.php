<?php

namespace AppLocalPlugins\ChannelNormalizer;

/**
 * Deterministic title/name cleanup for IPTV channel names.
 *
 * PHP port of the Python normalizer's cleaner.py — strips country prefixes,
 * quality suffixes, special characters, and bracket noise to produce a clean
 * lowercase string suitable for alias map matching.
 *
 * Every transformation here is regex-based and stateless.
 * Feed it "IT| 4K| RAI 1 UHD (IT)" and you get back "rai 1".
 */
class TitleCleaner
{
    /**
     * Country/group prefixes: "IT|", "UK:", "4K|", "UHD:", "FHD|", etc.
     * Handles optional whitespace around the separator and repeats
     * (some providers stack them: "IT| 4K| RAI 1").
     */
    private const PREFIX_PATTERN = '/^(?:'
        . '[A-Z]{2,3}\s*[|:]\s*'   // IT|, UK:, USA|
        . '|4K\s*[|:]\s*'           // 4K|
        . '|UHD\s*[|:]\s*'          // UHD|
        . '|FHD\s*[|:]\s*'          // FHD|
        . '|HD\s*[|:]\s*'           // HD|
        . '|SD\s*[|:]\s*'           // SD|
        . '|HEVC\s*[|:]\s*'         // HEVC|
        . ')+/i';

    /**
     * Quality suffixes at end of title: "UHD", "4K", "HD", "FHD", etc.
     * Also catches combos like "UHD/4K" or "4K NM".
     */
    private const QUALITY_SUFFIX_PATTERN = '/\s*(?:'
        . 'UHD(?:\/4K)?'   // UHD, UHD/4K
        . '|4K(?:\s*NM)?'  // 4K, 4K NM
        . '|FHD'
        . '|HD'
        . '|SD'
        . '|HEVC'
        . '|H\.?265'       // H265, H.265
        . '|H\.?264'       // H264, H.264
        . ')\s*$/i';

    /** Decorative unicode junk some providers love sprinkling in. */
    private const SPECIAL_CHARS_PATTERN = '/[◉●★▶►▪■□◆◇♦✦✧⚡🔴🟢🔵⬤]+/u';

    /** Parenthetical/bracket suffixes: "(IT)", "[HD]", "(Multi-Sub)". */
    private const BRACKET_SUFFIX_PATTERN = '/\s*[\(\[].+?[\)\]]\s*$/';

    /**
     * Trailing country codes left behind after quality suffixes are stripped.
     * "RAI 1 IT HD" → strip HD → "RAI 1 IT" → strip IT → "RAI 1".
     */
    private const TRAILING_COUNTRY_CODE = '/\s+(?:IT|ES|UK|RO|FR|US|DE|PT|NL|BE|AT|CH|PL|CZ|HU|BG|HR|RS|GR|TR|SE|NO|DK|FI)\s*$/i';

    /** Words that should always be uppercase in display titles. */
    private const ALL_CAPS_WORDS = [
        'rai', 'bbc', 'sky', 'rtl', 'hbo', 'cnn', 'fox', 'mtv',
        'tnt', 'rtp', 'tvr', 'tvn', 'tve', 'zdf', 'ard', 'orf',
        'nbc', 'abc', 'cbs', 'pbs', 'espn', 'dmax', 'arte',
        'pro', 'digi', 'antena', 'prima', 'nova', 'canal',
    ];

    /**
     * Strip all the provider noise from a raw channel title.
     *
     * Applies regex passes in a specific order — prefixes first, then brackets,
     * then quality suffixes (looped because "UHD/4K NM" needs multiple bites),
     * then trailing country codes, then whitespace cleanup.
     *
     * @param string $rawTitle - The raw channel title straight from the provider
     * @return string Cleaned, lowercase name ready for alias map lookup
     */
    public static function clean(string $rawTitle): string
    {
        if ($rawTitle === '') {
            return '';
        }

        $text = trim($rawTitle);

        // 1. Kill decorative unicode
        $text = preg_replace(self::SPECIAL_CHARS_PATTERN, '', $text);

        // 2. Strip country/quality prefixes (loop — they can stack)
        $prev = '';
        while ($prev !== $text) {
            $prev = $text;
            $text = trim(preg_replace(self::PREFIX_PATTERN, '', $text));
        }

        // 3. Remove bracket suffixes like "(IT)" or "[HD]"
        $text = trim(preg_replace(self::BRACKET_SUFFIX_PATTERN, '', $text));

        // 4. Remove quality suffixes (loop for multi-part ones)
        $prev = '';
        while ($prev !== $text) {
            $prev = $text;
            $text = trim(preg_replace(self::QUALITY_SUFFIX_PATTERN, '', $text));
        }

        // 5. Strip trailing country codes
        $text = trim(preg_replace(self::TRAILING_COUNTRY_CODE, '', $text));

        // 6. Collapse whitespace and lowercase
        $text = strtolower(trim(preg_replace('/\s+/', ' ', $text)));

        return $text;
    }

    /**
     * Turn a cleaned lowercase title back into something presentable.
     *
     * Known broadcast network names get uppercased ("rai" → "RAI"),
     * everything else gets title-cased. Digits stay as-is.
     *
     * @param string $cleaned - Cleaned lowercase title (e.g. "rai 1")
     * @return string Display-friendly title (e.g. "RAI 1")
     */
    public static function displayTitle(string $cleaned): string
    {
        if ($cleaned === '') {
            return '';
        }

        $words = explode(' ', $cleaned);
        $result = [];

        foreach ($words as $word) {
            if (in_array($word, self::ALL_CAPS_WORDS, true)) {
                $result[] = strtoupper($word);
            } else {
                $result[] = ctype_digit($word) ? $word : ucfirst($word);
            }
        }

        return implode(' ', $result);
    }
}
