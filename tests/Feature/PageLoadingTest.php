<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;

test('dashboard pages include one initially hidden navigation indicator outside livewire components', function (string $routeName) {
    $this->actingAs(User::factory()->admin()->create());

    $response = $this->get(route($routeName))->assertSee('Memuat halaman...')->assertSee('Menyiapkan halaman tujuan.');

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($document);
    $indicators = $xpath->query('//*[@data-page-loading]');
    expect($indicators->length)->toBe(1);
    $indicator = $indicators->item(0);
    expect($indicator->hasAttribute('hidden'))->toBeTrue();
    expect($indicator->getAttribute('role'))->toBe('status');
    expect($xpath->query('ancestor::*[@*[name()="wire:id"]]', $indicator)->length)->toBe(0);
})->with(['home', 'dashboard.vessel', 'sijil.create', 'account.settings']);

test('guest pages also include the navigation indicator', function (string $routeName) {
    $this->get(route($routeName))->assertSee('data-page-loading', false)->assertSee('Memuat halaman...');
})->with(['login', 'register']);

test('download buttons explicitly opt out of page loading', function () {
    $html = Blade::render('<x-dashboard.download-button href="/private/document">Unduh dokumen</x-dashboard.download-button>');

    expect($html)->toContain('data-page-loading-ignore')->toContain('href="/private/document"');
});
