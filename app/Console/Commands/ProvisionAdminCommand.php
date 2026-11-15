<?php

namespace App\Console\Commands;

use App\Models\Auth\Role;
use App\Models\Auth\User;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProvisionAdminCommand extends Command
{
    protected $signature = 'mapr:provision-admin';

    protected $description = 'Interactively create a new active CDRRMO administrator';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Administrator provisioning requires an interactive terminal.');

            return self::FAILURE;
        }

        $role = Role::where('code', 'cdrrmo_admin')->first();
        if ($role === null) {
            $this->error('Seed the reference data before provisioning an administrator.');

            return self::FAILURE;
        }

        $data = [
            'first_name' => trim((string) $this->ask('First name')),
            'last_name' => trim((string) $this->ask('Last name')),
            'email' => trim((string) $this->ask('Email')),
        ];
        // Query-builder uniqueness includes soft-deleted accounts.
        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:191'],
            'last_name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        // Never fall back to visible input when terminal masking is unavailable.
        $data['password'] = $this->secret('Password', false);
        $data['password_confirmation'] = $this->secret('Confirm password', false);
        $validator = Validator::make($data, [
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        try {
            User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => $data['password'], // User's hashed cast uses Laravel hashing.
                'role_id' => $role->id,
                'account_status' => 'active',
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent provision cannot overwrite an account either.
            $this->error('That email is already registered. No account was changed.');

            return self::FAILURE;
        }

        $this->info('Administrator created. Verify login and access to /cdrrmo.');

        return self::SUCCESS;
    }
}
