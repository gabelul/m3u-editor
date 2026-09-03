<?php

namespace App\Services;

use App\Models\Channel;

/**
 * Compares two channel titles to decide whether they plausibly describe the
 * same channel.
 *
 * This exists because merging trusts its grouping key completely. When a
 * provider hands over a bad stream ID — or the importer mis-parses one — every
 * channel sharing that key is treated as the same channel, and unrelated
 * channels end up as each other's failovers. A viewer whose channel drops then
 * gets something entirely different.
 *
 * Comparing the raw titles does not work. Provider titles carry a source
 * prefix and a pile of quality decorations, and those dominate the comparison:
 * "DE| SYFY HEVC" and "DE| SYFY FHD" are the same channel but share little
 * literal text. So titles are reduced to a bare core name first, and only
 * those cores are compared.
 */
class ChannelTitleSimilarityService
{
    /**
     * Quality, format and source tokens that say nothing about channel identity.
     */
    private const NOISE_TOKENS = [
        'fhd', 'uhd', 'hd', 'sd', 'hq', 'lq', '4k', '8k', '1080p', '720p', '576p', '480p',
        'hevc', 'h265', 'h264', 'x265', 'x264', 'raw', 'ts', 'm3u8', 'mpegts',
        'vip', 'backup', 'alt', 'multi', 'plus1', 'live',
    ];

    /**
     * Superscript and decorative characters providers use as quality markers,
     * e.g. "UK: BBC ONE ᴴᴰ ◉" or "VIP: SKY SPORTS F1 ⁴ᵏ".
     */
    private const DECORATIONS = [
        'ᴴ', 'ᴰ', 'ᴿ', 'ᴬ', 'ᵂ', 'ʰ', 'ᵉ', 'ᵛ', 'ᶜ', 'ᵁ', 'ᴾ', 'ᶠ', 'ˢ',
        '⁴', 'ᵏ', '³', '⁸', '⁰', '⁵', '¹', '²', '◉', '★', '☆', '⚽', '●',
    ];

    /**
     * Core names shorter than this can't be compared meaningfully — "E!" reduces
     * to "e", and a one-character string scores near zero against anything.
     * Below this length the guard abstains rather than guessing.
     */
    private const MIN_COMPARABLE_LENGTH = 5;

    /**
     * Decide whether a failover candidate plausibly describes the same channel
     * as its master.
     *
     * Abstains (returns true) whenever it cannot judge safely, so enabling the
     * guard can only ever remove pairs it is confident about.
     *
     * @param  float  $threshold  0.0 disables the check entirely.
     */
    public function isPlausibleMatch(Channel $master, Channel $candidate, float $threshold): bool
    {
        if ($threshold <= 0.0) {
            return true;
        }

        return $this->titlesMatch(
            $this->effectiveTitle($master),
            $this->effectiveTitle($candidate),
            $threshold,
        );
    }

    /**
     * Compare two raw provider titles.
     */
    public function titlesMatch(?string $masterTitle, ?string $candidateTitle, float $threshold): bool
    {
        if ($threshold <= 0.0) {
            return true;
        }

        $master = $this->coreName($masterTitle);
        $candidate = $this->coreName($candidateTitle);

        // Nothing usable left after normalising — don't guess.
        if ($master === null || $candidate === null) {
            return true;
        }

        if ($master === $candidate) {
            return true;
        }

        // Event feeds name the fixture in the title: "DAZN 7 - OH Leuven vs
        // Standard" is the same channel as plain "DAZN 7". Compare the part
        // before the first separator too before ruling the pair out.
        if ($this->eventFeedMatches($masterTitle, $candidateTitle)) {
            return true;
        }

        // One name fully containing the other is an abbreviation or a longer
        // branded form ("NICK JUNIOR" vs "NICKELODEON JUNIOR"), not a mismatch.
        if (str_contains($master, $candidate) || str_contains($candidate, $master)) {
            return true;
        }

        // Two very short names carry too little signal to judge either way —
        // "ocs" against "tnt" is as unscoreable as it looks. Only abstain when
        // *both* are short; a short name against a long unrelated one is
        // genuine evidence of a mismatch, and the abbreviation cases that
        // matter ("e" inside "eentertainment") were already caught above.
        if (mb_strlen($master) < self::MIN_COMPARABLE_LENGTH
            && mb_strlen($candidate) < self::MIN_COMPARABLE_LENGTH) {
            return true;
        }

        return $this->similarity($master, $candidate) >= $threshold;
    }

    /**
     * Reduce a provider title to a bare channel name: no source prefix, no
     * quality tokens, no punctuation.
     *
     * "VIP: SKY SPORTS F1 ᴴᴰ ʰᵉᵛᶜ" becomes "skysportsf1".
     */
    public function coreName(?string $title): ?string
    {
        $value = mb_strtolower(trim((string) $title));

        if ($value === '') {
            return null;
        }

        // Strip a leading source or country prefix: "VIP:", "DE|", "UK - ".
        $value = preg_replace('/^[\p{L}\p{N} +._!-]{1,12}[|:]\s*/u', '', $value) ?? $value;
        $value = preg_replace('/^[\p{L}]{2,6}\s+-\s+/u', '', $value) ?? $value;

        // Drop parenthesised and bracketed asides: "(SAT)", "[EVENTS]".
        $value = preg_replace('/\((?:[^()]*)\)|\[(?:[^\[\]]*)\]/u', ' ', $value) ?? $value;

        $value = str_replace(self::DECORATIONS, ' ', $value);

        // Remove quality tokens as whole words only, so "HD" goes but the "hd"
        // inside a real name does not.
        foreach (self::NOISE_TOKENS as $token) {
            $value = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($token, '/').'(?![\p{L}\p{N}])/u', ' ', $value) ?? $value;
        }

        // Keep letters and digits; everything else was formatting.
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';

        return $value !== '' ? $value : null;
    }

    /**
     * Similarity between two normalised names, 0.0 to 1.0.
     */
    public function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 4);
    }

    /**
     * True when one title is an event-specific feed of the other, e.g.
     * "BE| DAZN 7 - OH Leuven - Standard Liège" against "BE - DAZN 7".
     *
     * The comparison is deliberately asymmetric: the *stripped* form of one
     * title is matched against the *whole* core name of the other. Comparing
     * both stripped forms instead would collapse anything sharing a leading
     * language code — "EN - Detective Chinatown" and "EN - Thirteen Lives"
     * would both reduce to "en" and be declared the same channel.
     *
     * The base also has to be long enough to mean something, so a bare "en"
     * or "4k" prefix can never carry a match on its own.
     */
    private function eventFeedMatches(?string $masterTitle, ?string $candidateTitle): bool
    {
        $masterFull = $this->coreName($masterTitle);
        $candidateFull = $this->coreName($candidateTitle);
        $masterBase = $this->coreName($this->beforeSeparator($masterTitle));
        $candidateBase = $this->coreName($this->beforeSeparator($candidateTitle));

        foreach ([[$masterBase, $candidateFull], [$candidateBase, $masterFull]] as [$base, $full]) {
            if ($base === null || $full === null) {
                continue;
            }

            if (mb_strlen($base) < self::MIN_COMPARABLE_LENGTH) {
                continue;
            }

            if ($base === $full) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything before the first " - ", which is where providers append
     * fixture or event detail.
     */
    private function beforeSeparator(?string $title): ?string
    {
        $value = (string) $title;
        $position = mb_strpos($value, ' - ');

        return $position === false ? $value : mb_substr($value, 0, $position);
    }

    /**
     * The title actually shown for a channel, honouring the user's override.
     */
    private function effectiveTitle(Channel $channel): ?string
    {
        return $channel->title_custom ?: $channel->title;
    }
}
