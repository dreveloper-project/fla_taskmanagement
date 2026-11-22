<?php

use App\Models\User;

test('administrators can access finance without billing navigation', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('finance.index'))->assertOk()->assertSee('Finance')->assertDontSee('Service Billings');
    $this->get('/dashboard/finance/reports')->assertNotFound();
});

test('finance requires an authorized user', function () {
    $this->get(route('finance.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('finance.index'))->assertForbidden();
});
