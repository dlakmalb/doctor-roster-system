<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('redirects guests away from protected pages', function () {
    $this->get(route('dashboard'))->assertRedirectToRoute('login');
    $this->get(route('monthly-setup.show', ['year' => 2026, 'month' => 10]))
        ->assertRedirectToRoute('login');
});

it('logs in an administrator with valid credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'correct-password',
    ])->assertRedirectToRoute('dashboard');

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid login credentials', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'incorrect-password',
    ])->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => 'The provided credentials do not match our records.',
        ]);

    $this->assertGuest();
});

it('logs out an authenticated administrator', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))
        ->assertRedirectToRoute('login');

    $this->assertGuest();
});

it('does not expose a public registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

it('redirects the root according to authentication state', function () {
    $this->get(route('home'))->assertRedirectToRoute('login');

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirectToRoute('dashboard');
});
