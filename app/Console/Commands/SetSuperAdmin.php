<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetSuperAdmin extends Command
{
    protected $signature = 'businessos:super-admin {email} {--revoke : Remove platform super-admin access}';

    protected $description = 'Grant or revoke BusinessOS platform super-admin access for an existing user';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $this->error('No BusinessOS user exists with that email.');

            return self::FAILURE;
        }

        $enabled = ! (bool) $this->option('revoke');

        $user->forceFill(['is_super_admin' => $enabled])->save();

        $this->info($enabled
            ? "Platform super-admin enabled for {$user->email}."
            : "Platform super-admin revoked for {$user->email}.");

        return self::SUCCESS;
    }
}
