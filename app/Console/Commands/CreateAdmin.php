<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Create the initial roster administrator';

    public function handle(): int
    {
        $name = text(label: 'Admin name', required: true);
        $email = text(label: 'Admin email', required: true);
        $password = password(label: 'Password', required: true);
        $passwordConfirmation = password(label: 'Confirm password', required: true);

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                error($message);
            }

            return self::FAILURE;
        }

        User::create($validator->safe()->only(['name', 'email', 'password']));
        info('Administrator created successfully.');

        return self::SUCCESS;
    }
}
