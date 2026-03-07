<?php

namespace App\Services;

/**
 * Deterministic channel title normalization service.
 *
 * Strips provider-specific junk from IPTV channel titles: country prefixes,
 * quality suffixes, special unicode characters, bracket annotations, and
 * trailing country codes. Produces a clean lowercase title suitable for
 * EPG matching and duplicate detection.
 *
 * Ported from the external Python normalizer (normalizer/cleaner.py) and
 * m3u-proxy normalization pipeline, adapted for PHP with additional patterns
 * from real-world IPTV provider data.
 *
 * @see \App\Services\SimilaritySearchService — uses normalized titles for EPG matching
 */
class ChannelTitleNormalizerService
{
    /**
     * Country/group prefixes commonly prepended by IPTV providers.
     * Matches patterns like "IT|", "UK:", "USA|", "4K|", "HEVC:", etc.
     * Allows optional whitespace around the separator.
     */
    private const PREFIX_PATTERN = '/^(?:'
        . '[A-Z]{2,3}\s*[|:]\s*'     // IT|, UK:, USA|
        . '|4K\s*[|:]\s*'            // 4K|
        . '|UHD\s*[|:]\s*'           // UHD|
        . '|FHD\s*[|:]\s*'           // FHD|
        . '|HD\s*[|:]\s*'            // HD|
        . '|SD\s*[|:]\s*'            // SD|
        . '|HEVC\s*[|:]\s*'          // HEVC|
        . '|PLAY\+?\s*[|:]\s*'       // PLAY+|, PLAY:
        . '|OD\s*[|:]\s*'            // OD| (on-demand prefix)
        . '|ZONE\+?\s*[|:]\s*'       // ZONE|, ZONE+|
        . '|GO\s*[|:]\s*'            // GO: (common provider prefix)
        . '|NL\s*[|:]\s*'            // NL| (Dutch prefix)
        . ')+/iu';

    /**
     * Quality/format suffixes appended to channel names.
     * Handles UHD, 4K, HD, FHD, SD, HEVC, H.265, H.264, and combos like "UHD/4K".
     */
    private const QUALITY_SUFFIX_PATTERN = '/\s*(?:'
        . 'UHD(?:\/4K)?'              // UHD, UHD/4K
        . '|4K(?:\s*NM)?'             // 4K, 4K NM
        . '|FHD(?:\s*50FPS)?'         // FHD, FHD 50FPS
        . '|HD'                        // HD
        . '|SD'                        // SD
        . '|LQ'                        // LQ (low quality)
        . '|HEVC'                      // HEVC
        . '|H\.?265'                   // H265, H.265
        . '|H\.?264'                   // H264, H.264
        . '|8K(?:\+)?'                 // 8K, 8K+
        . ')\s*$/iu';

    /**
     * Decorative unicode symbols used by IPTV providers.
     */
    private const SPECIAL_CHARS_PATTERN = '/[◉●★▶►▪■□◆◇♦✦✧⚡🔴🟢🔵⬤☰≡⏺]+/u';

    /**
     * Unicode superscript characters commonly used in channel names.
     * Covers ᴿᴬᵂ (RAW), ᴴᴰ (HD), ᵁᴴᴰ (UHD), ⱽᴵᴾ (VIP), ᴳᴼᴸᴰ (GOLD), etc.
     */
    private const SUPERSCRIPT_PATTERN = '/[\x{1D2C}-\x{1D6A}\x{2070}\x{2071}\x{2074}-\x{207F}\x{1D43}-\x{1D5C}]+/u';

    /**
     * Pipe-wrapped identifiers like ┃UK┃ or ┃SPORTS┃.
     */
    private const PIPE_WRAPPER_PATTERN = '/┃[^┃]+┃\s*/u';

    /**
     * Bracket/parenthetical suffixes like "(IT)", "[HD]", "(Multi-Sub)".
     */
    private const BRACKET_SUFFIX_PATTERN = '/\s*[\(\[].+?[\)\]]\s*$/u';

    /**
     * Trailing country codes after quality stripping.
     * e.g., "RAI 1 IT" → "RAI 1" (after "HD" was already stripped).
     */
    private const TRAILING_COUNTRY_CODE_PATTERN = '/\s+(?:'
        . 'IT|ES|UK|RO|FR|US|DE|PT|NL|BE|AT|CH|PL|CZ|HU|BG|HR|RS|GR|TR|SE|NO|DK|FI'
        . '|AR|BR|MX|CO|CL|PE|VE|EC|UY|PY|BO|CR|PA|DO|GT|SV|HN|NI|CU|PR'
        . '|JP|KR|CN|IN|PH|TH|VN|ID|MY|SG|TW|HK|PK|BD|LK'
        . '|ZA|NG|KE|GH|EG|MA|TN|DZ|LY|ET|TZ|UG|CM|SN|CI|MG'
        . '|AU|NZ|CA|IE|GB|IL|SA|AE|QA|KW|BH|OM|JO|LB|IQ|IR'
        . ')\s*$/iu';

    /**
     * Event/PPV indicator patterns — skip normalization for these.
     * Sports events with dates, PPV markers, etc.
     */
    private const EVENT_INDICATORS = ['@jan', '@feb', '@mar', '@apr', '@may', '@jun',
        '@jul', '@aug', '@sep', '@oct', '@nov', '@dec', 'ppv', 'f1tv', 'motorgp'];

    /**
     * Accent/diacritics translation map for European channels.
     * Normalizes accented characters to their ASCII base equivalents.
     */
    private const ACCENT_MAP = [
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ã' => 'a', 'õ' => 'o', 'ñ' => 'n', 'ç' => 'c',
        'ă' => 'a', 'ș' => 's', 'ț' => 't', // Romanian
        'ø' => 'o', 'å' => 'a', 'æ' => 'ae', // Nordic
        'ß' => 'ss', // German
    ];

    /**
     * Known channel brands that should always be uppercased in display titles.
     *
     * @var array<string>
     */
    private const ALL_CAPS_WORDS = [
        'rai', 'bbc', 'sky', 'rtl', 'hbo', 'cnn', 'fox', 'mtv',
        'tnt', 'rtp', 'tvr', 'tvn', 'tve', 'zdf', 'ard', 'orf',
        'nbc', 'abc', 'cbs', 'pbs', 'espn', 'dmax', 'arte',
        'pro', 'digi', 'antena', 'prima', 'nova', 'canal',
        'npo', 'sbs', 'rtv', 'tvp', 'ct', 'svt', 'nrk', 'yle',
        'trt', 'ert', 'hrt', 'rts', 'bnt', 'trt', 'lrt', 'ltv',
    ];

    /**
     * Normalize a channel title by stripping provider junk.
     *
     * Applies a multi-stage pipeline: unicode symbols → pipe wrappers →
     * country prefixes → superscripts → bracket suffixes → quality suffixes →
     * trailing country codes → accent normalization → whitespace cleanup.
     *
     * @param string $rawTitle - The raw channel title from the provider
     * @return string - Clean lowercase title (e.g., "IT| GO: CNN INT ᴿᴬᵂ HD" → "cnn int")
     */
    public function normalize(string $rawTitle): string
    {
        if (empty($rawTitle)) {
            return '';
        }

        $text = trim($rawTitle);

        // Skip normalization for event/PPV channels — their names ARE the content
        $lowerCheck = mb_strtolower($text, 'UTF-8');
        foreach (self::EVENT_INDICATORS as $indicator) {
            if (str_contains($lowerCheck, $indicator)) {
                return mb_strtolower(preg_replace('/\s+/u', ' ', $text), 'UTF-8');
            }
        }

        // Phase 1: Remove decorative unicode symbols (◉, ★, 🔴, etc.)
        $text = preg_replace(self::SPECIAL_CHARS_PATTERN, '', $text);

        // Phase 2: Remove pipe-wrapped identifiers (┃UK┃, ┃SPORTS┃)
        $text = preg_replace(self::PIPE_WRAPPER_PATTERN, '', $text);

        // Phase 3: Strip country/group prefixes (IT|, 4K|, GO:, etc.)
        // Loop because prefixes can stack: "IT| 4K| GO: CNN"
        $prev = '';
        while ($prev !== $text) {
            $prev = $text;
            $text = preg_replace(self::PREFIX_PATTERN, '', trim($text));
        }

        // Phase 4: Remove unicode superscripts (ᴿᴬᵂ, ᴴᴰ, ᵁᴴᴰ, ⱽᴵᴾ)
        $text = preg_replace(self::SUPERSCRIPT_PATTERN, '', $text);

        // Phase 5: Remove bracket/parenthetical suffixes like "(IT)", "[HD]"
        $text = preg_replace(self::BRACKET_SUFFIX_PATTERN, '', trim($text));

        // Phase 6: Remove quality/format suffixes (HD, FHD, 4K, HEVC, etc.)
        // Loop for combos like "UHD/4K NM"
        $prev = '';
        while ($prev !== $text) {
            $prev = $text;
            $text = preg_replace(self::QUALITY_SUFFIX_PATTERN, '', trim($text));
        }

        // Phase 7: Remove trailing country codes (e.g., "RAI 1 IT" after "HD" stripped)
        $text = preg_replace(self::TRAILING_COUNTRY_CODE_PATTERN, '', trim($text));

        // Phase 8: Normalize accents/diacritics to ASCII
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, self::ACCENT_MAP);

        // Phase 9: Collapse whitespace and trim
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Generate a display-friendly title from a normalized title.
     *
     * Applies title-case with known brand exceptions (RAI, BBC, CNN, etc.)
     * so channels display nicely in the UI while still being normalized.
     *
     * @param string $normalized - Clean lowercase title from normalize()
     * @return string - Display-ready title (e.g., "cnn international" → "CNN International")
     */
    public function makeDisplayTitle(string $normalized): string
    {
        if (empty($normalized)) {
            return '';
        }

        $words = explode(' ', $normalized);
        $result = [];

        foreach ($words as $word) {
            if (in_array($word, self::ALL_CAPS_WORDS, true)) {
                $result[] = mb_strtoupper($word, 'UTF-8');
            } elseif (is_numeric($word)) {
                $result[] = $word;
            } else {
                $result[] = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return implode(' ', $result);
    }

    /**
     * Normalize and return both the clean and display versions.
     *
     * Convenience method for import pipelines that need both forms at once.
     *
     * @param string $rawTitle - The raw channel title from the provider
     * @return array{normalized: string, display: string} - Both title forms
     */
    public function normalizeWithDisplay(string $rawTitle): array
    {
        $normalized = $this->normalize($rawTitle);

        return [
            'normalized' => $normalized,
            'display' => $this->makeDisplayTitle($normalized),
        ];
    }
}
