<?php

use App\Support\WhatsApp;

test('un numéro international donne un lien wa.me', function (string $phone, string $expected) {
    expect(WhatsApp::link($phone))->toBe($expected);
})->with([
    ['+22373136789', 'https://wa.me/22373136789'],
    ['+225 07 05 18 37 98', 'https://wa.me/2250705183798'],
    ['0033605758494', 'https://wa.me/33605758494'],
    ['2250705423387', 'https://wa.me/2250705423387'],
    ['+22376131140/70612265', 'https://wa.me/22376131140'],
]);

test('un numéro local sans indicatif ne donne pas de lien', function (?string $phone) {
    expect(WhatsApp::link($phone))->toBeNull();
})->with(['0758071816', '0141737790', '777522544', '76131140/70612265', '', null]);
