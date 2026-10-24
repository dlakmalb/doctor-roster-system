<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

it('creates a hashed admin user and preserves it when seeded again', function () {
    $seeder = new AdminUserSeeder;

    $seeder->run();
    $admin = User::query()->where('email', 'admin@roster.test')->firstOrFail();
    $originalPasswordHash = $admin->password;

    expect($admin->name)->toBe('Admin')
        ->and(Hash::check('12345678', $admin->password))->toBeTrue();

    $admin->forceFill(['password' => Hash::make('changed-password')])->save();
    $changedPasswordHash = $admin->fresh()->password;

    $seeder->run();

    expect(User::query()->where('email', 'admin@roster.test')->count())->toBe(1)
        ->and($admin->fresh()->password)->toBe($changedPasswordHash)
        ->and($changedPasswordHash)->not->toBe($originalPasswordHash);
});

it('rejects execution outside local and testing environments', function (string $environment) {
    $this->app['env'] = $environment;

    expect(fn () => (new AdminUserSeeder)->run())
        ->toThrow(RuntimeException::class, 'The admin user seeder may only run in local or testing environments.');
})->with(['production', 'staging']);
