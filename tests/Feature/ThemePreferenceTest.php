<?php

use App\Models\User;

test('login forces the light theme', function () {
    $this->get(route('login'))->assertSee('data-forced-theme="light"', false);
});

test('navbar offers one toggle for all themes instead of the location label', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'))
        ->assertSee('data-theme-toggle', false)
        ->assertSee('theme-switch-thumb', false)
        ->assertSee('Tema gelap')
        ->assertDontSee('data-theme-matcha', false)
        ->assertDontSee('Bubble Gum')
        ->assertSee('Tema terang')
        ->assertDontSee('Makassar, ID');

    expect(substr_count($response->getContent(), 'data-theme-toggle'))->toBe(1);
});
