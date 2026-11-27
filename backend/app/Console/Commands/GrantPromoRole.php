<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant or revoke the promotional_customer role (User::PROMO_ROLE).
 *
 * The role exempts the user from plan, card and post-limit gating, and, for a
 * user whose promo_expires_at is null, it opens a free window that never
 * closes (User::hasActivePromo). The command says when that applies so
 * open-ended free access is never granted by accident.
 */
class GrantPromoRole extends Command
{
    protected $signature = 'users:promo {email : The user\'s email address} {--revoke : Take the role away instead}';

    protected $description = 'Grant (or revoke) the promotional_customer role, which exempts a user from plan and card gating.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user with the email {$email}.");

            return self::FAILURE;
        }

        $role = Role::firstOrCreate(
            ['name' => User::PROMO_ROLE],
            ['display_name' => 'Promotional customer', 'description' => 'Free access + early platform access'],
        );

        if ($this->option('revoke')) {
            $user->roles()->detach($role->id);
            $this->info("Revoked the promotional_customer role from {$email}.");

            return self::SUCCESS;
        }

        // syncWithoutDetaching keeps the user's other roles and makes a repeat
        // run a no-op rather than a duplicate pivot row.
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->info("Granted the promotional_customer role to {$email}.");

        if (is_null($user->promo_expires_at)) {
            $this->warn('  → promo_expires_at is null, so this also gives free access with no end date.');
            $this->line('    Set promo_expires_at if they should still be billed.');
        }

        return self::SUCCESS;
    }
}
