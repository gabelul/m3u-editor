<?php

use App\Services\ChannelTitleSimilarityService;

beforeEach(function () {
    $this->service = new ChannelTitleSimilarityService;
});

it('strips source prefixes and quality decorations down to the channel name', function (string $title, string $expected) {
    expect($this->service->coreName($title))->toBe($expected);
})->with([
    ['DE| SYFY HEVC', 'syfy'],
    ['JOYN| SYFY FHD ᴿᴬᵂ', 'syfy'],
    ['VIP: SKY SPORTS F1 ᴴᴰ ʰᵉᵛᶜ', 'skysportsf1'],
    ['UK: BBC 3 / CBBC HD ◉', 'bbc3cbbc'],
    ['FR - W9 HEVC', 'w9'],
    ['DE: DISCOVERY HD (720P)', 'discovery'],
    ['IT| CIELO UHD', 'cielo'],
]);

it('returns null when a title has no usable content', function (?string $title) {
    expect($this->service->coreName($title))->toBeNull();
})->with([[null], [''], ['   '], ['HD'], ['4K']]);

it('treats a zero threshold as the guard being switched off', function () {
    expect($this->service->titlesMatch('UK: BBC 3', 'UK: FOOD NETWORK', 0.0))->toBeTrue();
});

it('rejects titles that describe genuinely different channels', function (string $master, string $candidate) {
    expect($this->service->titlesMatch($master, $candidate, 0.4))->toBeFalse();
})->with([
    // The case this guard exists for: a mis-parsed stream id grouped these.
    ['UK: BBC 3 / CBBC HD ◉', 'UK: FOOD NETWORK +1 ◉'],
    ['UK: BBC 3 / CBBC HD ◉', 'UK: ITV LONDON 4K ◉'],
    ['FR| TLC HD', 'FR - DISCOVERY SCIENCE'],
    ['ES| 13 TV SD', 'ES| CALLE 13 HEVC'],
    ['UK| TRUE CRIME', 'UK - CBS DRAMA'],
    // Two unrelated films sharing only a language prefix.
    ['EN - Pitch Perfect 3  (2017)', 'ES - Tenéis que venir a verla (2023)'],
    ['EN - Detective Chinatown  (2015)', '4K-EN - Thirteen Lives  (2022)'],
]);

it('accepts the same channel across sources and quality variants', function (string $master, string $candidate) {
    expect($this->service->titlesMatch($master, $candidate, 0.4))->toBeTrue();
})->with([
    ['DE| SYFY HEVC', 'DE| SYFY FHD'],
    ['FR| W9 4K', 'FR - W9 HEVC'],
    ['FR| LCI HD ᴿᴬᵂ', 'FR| LCI FHD'],
    ['IT| CIELO UHD', 'IT: CIELO HEVC'],
    ['VIP: SKY SPORTS F1 ᴴᴰ', 'SKYGO: SKY SPORT F1 4K'],
    ['FR| NAT GEO WILD HD', 'BE| NAT GEOGRAPHIC WILD HD'],
    ['UK| NICKELODEON FHD', 'NOW: NICKELODEON ᴴᴰ'],
]);

it('accepts an event feed matched against its base channel', function () {
    // Comparing the stripped form of both titles would reduce each to its
    // language prefix; the base has to be matched against the whole name.
    expect($this->service->titlesMatch('BE| DAZN 7 - OH Leuven - Standard', 'BE - DAZN 7', 0.4))->toBeTrue();
    expect($this->service->titlesMatch('BE: DAZN 11', 'BE| DAZN 11 - Union SG - RSC Anderlecht', 0.4))->toBeTrue();
});

it('does not let a shared language prefix carry a match on its own', function () {
    // Both titles reduce to "en" before the separator. If the base were
    // compared against the other base rather than the other full name, these
    // two unrelated films would be declared the same channel.
    expect($this->service->titlesMatch('EN - Nine Days  (2021)', 'EN - Hungry Dog Blues  (2022)', 0.4))->toBeFalse();
});

it('accepts an abbreviation contained in its expanded form', function () {
    expect($this->service->titlesMatch('FR| E! FHD', 'FR - E! ENTERTAINMENT FHD', 0.4))->toBeTrue();
    expect($this->service->titlesMatch('UK| NICK JR FHD', 'UK| NICKELODEON JUNIOR HD', 0.4))->toBeTrue();
});

it('abstains only when both names are too short to compare', function () {
    // Both short and unrelated - no signal either way, so keep the pair.
    expect($this->service->titlesMatch('FR| OCS HD', 'RO - TNT', 0.4))->toBeTrue();

    // One short, one long, nothing in common - that is real evidence.
    expect($this->service->titlesMatch('UK: BBC 3 / CBBC HD ◉', 'UK: RT UK HD ◉', 0.4))->toBeFalse();
});

it('scores identical names as a perfect match', function () {
    expect($this->service->similarity('syfy', 'syfy'))->toBe(1.0);
});
