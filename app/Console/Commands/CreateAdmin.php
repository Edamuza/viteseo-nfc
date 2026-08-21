<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'nfc:admin:create';

    protected $description = 'Create an admin user for the NFC card system (interactive)';

    public function handle(): int
    {
        $this->info('Create NFC admin user');
        $this->line('The password is never echoed and never logged.');
        $this->newLine();

        $name  = (string) $this->ask('Name');
        $email = (string) $this->ask('Email');

        $password = (string) $this->secret('Password (min 12 chars)');
        $confirm  = (string) $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name'     => ['required', 'string', 'max:120'],
                'email'    => ['required', 'email', 'max:190', 'unique:users,email'],
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
        ]);

        $this->newLine();
        $this->info('Admin created: '.$email);
        $this->line('Sign in at '.rtrim(config('app.url'), '/').'/admin/login');

        return self::SUCCESS;
    }
}
