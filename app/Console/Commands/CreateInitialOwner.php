<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class CreateInitialOwner extends Command
{
    protected $signature = 'businessos:create-owner
        {--email=admin@businessos.af : Owner email address}
        {--name=BusinessOS Admin : Owner display name}
        {--business=BusinessOS : Initial business name}';

    protected $description = 'Create the first BusinessOS owner, business, owner role and enabled modules.';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('A user already exists. This bootstrap command only runs on an empty installation.');

            return self::FAILURE;
        }

        if (! Permission::query()->exists()) {
            throw new RuntimeException('Permissions are not seeded. Run the normal application seeders first.');
        }

        $email = strtolower(trim((string) $this->option('email')));
        $name = trim((string) $this->option('name'));
        $businessName = trim((string) $this->option('business'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('The owner email address is invalid.');

            return self::FAILURE;
        }

        $password = Str::password(20, true, true, false, false);

        [$user, $business] = DB::transaction(function () use ($email, $name, $businessName, $password): array {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);

            $business = Business::create(['name' => $businessName]);
            $roles = $business->provisionDefaultRoles();
            $business->provisionDefaultModules();

            $membership = $user->memberships()->create([
                'business_id' => $business->id,
            ]);
            $membership->assignRole($roles['owner']);

            foreach (array_keys(config('modules.registry', [])) as $moduleKey) {
                BusinessModule::updateOrCreate(
                    ['business_id' => $business->id, 'module_key' => $moduleKey],
                    ['enabled' => true],
                );
            }

            return [$user, $business];
        });

        $this->newLine();
        $this->info('Initial BusinessOS owner created.');
        $this->line('Business: '.$business->name);
        $this->line('Email: '.$user->email);
        $this->line('Password: '.$password);
        $this->warn('Store this password securely and change it after the first login.');

        return self::SUCCESS;
    }
}
