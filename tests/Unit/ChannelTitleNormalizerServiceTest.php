<?php

use App\Services\ChannelTitleNormalizerService;

/**
 * Tests for ChannelTitleNormalizerService — the multi-stage IPTV channel
 * title cleanup pipeline. Each test group targets a specific normalization
 * phase to ensure provider junk is stripped correctly.
 */

// ─── Country/Group Prefix Removal ────────────────────────────────

it('strips country prefixes with pipe separator', function (string $input, string $expected) {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize($input))->toBe($expected);
})->with([
    ['IT| RAI 1', 'rai 1'],
    ['UK| BBC One', 'bbc one'],
    ['ES: Canal Sur', 'canal sur'],
    ['USA| CNN', 'cnn'],
    ['RO| TVR 1', 'tvr 1'],
]);

it('strips stacked prefixes (multiple passes)', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize('IT| 4K| RAI 1'))->toBe('rai 1');
    expect($normalizer->normalize('UK| HD| BBC Two'))->toBe('bbc two');
    expect($normalizer->normalize('FHD| GO: Sky Cinema'))->toBe('sky cinema');
});

it('strips provider-specific prefixes', function (string $input, string $expected) {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize($input))->toBe($expected);
})->with([
    ['GO: CNN International', 'cnn international'],
    ['PLAY+: Discovery', 'discovery'],
    ['OD| Sky Sport', 'sky sport'],
    ['ZONE: History', 'history'],
    ['NL| NPO 1', 'npo 1'],
]);

// ─── Quality/Format Suffix Removal ──────────────────────────────

it('strips quality suffixes', function (string $input, string $expected) {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize($input))->toBe($expected);
})->with([
    ['RAI 1 HD', 'rai 1'],
    ['CNN FHD', 'cnn'],
    ['Discovery 4K', 'discovery'],
    ['BBC One UHD', 'bbc one'],
    ['Sky Cinema SD', 'sky cinema'],
    ['Fox HEVC', 'fox'],
    ['History H.265', 'history'],
    ['Sport 1 FHD 50FPS', 'sport 1'],
    ['Channel 8K+', 'channel'],
]);

it('strips combo quality suffixes like UHD/4K', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize('RAI 1 UHD/4K'))->toBe('rai 1');
    expect($normalizer->normalize('Sky Sport 4K NM'))->toBe('sky sport');
});

// ─── Special Unicode Characters ─────────────────────────────────

it('strips decorative unicode symbols', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize('◉ RAI 1'))->toBe('rai 1');
    expect($normalizer->normalize('★ CNN ★'))->toBe('cnn');
    expect($normalizer->normalize('🔴 BBC One'))->toBe('bbc one');
    expect($normalizer->normalize('▶ Discovery'))->toBe('discovery');
});

it('strips unicode superscripts (RAW, HD, VIP, etc.)', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize('CNN ᴿᴬᵂ'))->toBe('cnn');
    expect($normalizer->normalize('Sky ᴴᴰ'))->toBe('sky');
    expect($normalizer->normalize('Fox ⱽᴵᴾ'))->toBe('fox');
});

it('strips pipe-wrapped identifiers', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize('┃UK┃ BBC One'))->toBe('bbc one');
    expect($normalizer->normalize('┃SPORTS┃ ESPN'))->toBe('espn');
});

// ─── Bracket/Parenthetical Removal ──────────────────────────────

it('strips bracket suffixes', function (string $input, string $expected) {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize($input))->toBe($expected);
})->with([
    ['RAI 1 (IT)', 'rai 1'],
    ['CNN [HD]', 'cnn'],
    ['Fox (Multi-Sub)', 'fox'],
    ['Sky Sport [EN]', 'sky sport'],
]);

// ─── Trailing Country Code Removal ──────────────────────────────

it('strips trailing country codes after quality stripping', function () {
    $normalizer = new ChannelTitleNormalizerService;

    // "RAI 1 IT HD" → strip HD → "RAI 1 IT" → strip IT → "RAI 1"
    expect($normalizer->normalize('RAI 1 IT HD'))->toBe('rai 1');
    expect($normalizer->normalize('Canal+ FR'))->toBe('canal+');
    expect($normalizer->normalize('Sky Sport DE'))->toBe('sky sport');
});

// ─── Accent/Diacritics Normalization ────────────────────────────

it('normalizes accented characters to ASCII equivalents', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize('Télé Monte Carlo'))->toBe('tele monte carlo');
    expect($normalizer->normalize('České televize'))->toBe('ceske televize');
    expect($normalizer->normalize('Románia TV'))->toBe('romania tv');
    expect($normalizer->normalize('Rai Südtirol'))->toBe('rai sudtirol');
});

// ─── Event/PPV Channel Preservation ─────────────────────────────

it('preserves event channel names (minimal normalization)', function () {
    $normalizer = new ChannelTitleNormalizerService;

    // Event channels should keep their content — the name IS the event
    $result = $normalizer->normalize('UFC 300 PPV @Mar 2024');
    expect($result)->toContain('ppv');
    expect($result)->toContain('@mar');
});

// ─── Combined Real-World Scenarios ──────────────────────────────

it('handles complex real-world IPTV titles', function (string $input, string $expected) {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize($input))->toBe($expected);
})->with([
    ['IT| GO: CNN INT ᴿᴬᵂ HD', 'cnn int'],
    ['UK| ★ BBC One FHD', 'bbc one'],
    ['RO| 4K| TVR 1 UHD (RO)', 'tvr 1'],
    ['◉ IT| RAI Premium HEVC [IT]', 'rai premium'],
    ['ES: Canal+ Series HD', 'canal+ series'],
    ['NL| NPO 1 Extra', 'npo 1 extra'],
    ['DE| ┃SPORTS┃ Sky Sport 1 HD', 'sky sport 1'],
]);

// ─── Edge Cases ─────────────────────────────────────────────────

it('returns empty string for empty input', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize(''))->toBe('');
});

it('handles titles that are only prefixes/suffixes', function () {
    $normalizer = new ChannelTitleNormalizerService;

    // After stripping everything, very short results are still returned
    expect($normalizer->normalize('IT| HD'))->toBe('');
});

it('preserves channel numbers and plus signs', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->normalize('Canal+ 1'))->toBe('canal+ 1');
    expect($normalizer->normalize('Sport 2'))->toBe('sport 2');
    expect($normalizer->normalize('BBC Three'))->toBe('bbc three');
});

// ─── Display Title Generation ───────────────────────────────────

it('generates display titles with proper brand casing', function (string $normalized, string $expected) {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->makeDisplayTitle($normalized))->toBe($expected);
})->with([
    ['rai 1', 'RAI 1'],
    ['bbc one', 'BBC One'],
    ['cnn international', 'CNN International'],
    ['sky sport 1', 'SKY Sport 1'],
    ['discovery channel', 'Discovery Channel'],
    ['hbo max', 'HBO Max'],
    ['espn 2', 'ESPN 2'],
]);

it('returns empty string for empty display title input', function () {
    $normalizer = new ChannelTitleNormalizerService;

    expect($normalizer->makeDisplayTitle(''))->toBe('');
});

// ─── Convenience Method ─────────────────────────────────────────

it('returns both normalized and display titles from normalizeWithDisplay', function () {
    $normalizer = new ChannelTitleNormalizerService;

    $result = $normalizer->normalizeWithDisplay('IT| GO: BBC One HD');

    expect($result)->toBeArray()
        ->toHaveKeys(['normalized', 'display']);
    expect($result['normalized'])->toBe('bbc one');
    expect($result['display'])->toBe('BBC One');
});
