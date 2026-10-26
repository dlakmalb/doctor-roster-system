<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates an administrator through secure interactive prompts', function () {
    $this->artisan('app:create-admin')
        ->expectsQuestion('Admin name', 'Roster Admin')
        ->expectsQuestion('Admin email', 'admin@example.com')
        ->expectsQuestion('Password', 'Secure-password-123')
        ->expectsQuestion('Confirm password', 'Secure-password-123')
        ->assertSuccessful();

    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    expect($admin->name)->toBe('Roster Admin')
        ->and(Hash::check('Secure-password-123', $admin->password))->toBeTrue();
});

it('rejects a duplicate administrator email', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    $this->artisan('app:create-admin')
        ->expectsQuestion('Admin name', 'Another Admin')
        ->expectsQuestion('Admin email', 'admin@example.com')
        ->expectsQuestion('Password', 'Secure-password-123')
        ->expectsQuestion('Confirm password', 'Secure-password-123')
        ->assertFailed();

    $this->assertDatabaseCount('users', 1);
});
