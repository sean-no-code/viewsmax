<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\Plan;
use App\Services\CreditService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Only seed users if not in production environment
        if (config('app.env') === 'production') {
            $this->command->info('Skipping user seeding in production environment');
            return;
        }

        // Get roles and plans
        $adminRole = Role::where('name', 'admin')->first();
        $customerRole = Role::where('name', 'customer')->first();
        $freePlan = Plan::where('name', 'free')->first();
        $starterPlan = Plan::where('name', 'starter')->first();

        // Create admin user
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Assign admin role
        if ($adminRole && !$adminUser->hasRole('admin')) {
            $adminUser->roles()->attach($adminRole->id);
        }

        // Assign starter (pro) plan to admin user
        if ($starterPlan) {
            // Remove any existing plans first
            $adminUser->plans()->detach();
            
            // Attach starter plan
            $adminUser->plans()->attach($starterPlan->id, [
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => now()->addMonth(), // Monthly billing cycle
            ]);
        }

        // Add 500 credits to admin user
        $creditService = app(CreditService::class);
        $creditService->addCredits($adminUser, 500, 'Initial credits for admin user');

        // Create customer user with free plan
        $freeCustomer = User::firstOrCreate(
            ['email' => 'free@customer.com'],
            [
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Assign customer role and free plan
        if ($customerRole && !$freeCustomer->hasRole('customer')) {
            $freeCustomer->roles()->attach($customerRole->id);
        }
        
        if ($freePlan && !$freeCustomer->hasActivePlan()) {
            $freeCustomer->plans()->attach($freePlan->id, [
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => null, // Free plan doesn't expire
            ]);
        }

        // Create customer user with paid plan
        $paidCustomer = User::firstOrCreate(
            ['email' => 'customer@paid.com'],
            [
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Create customer user with paid plan
        $paidCustomer2 = User::firstOrCreate(
            ['email' => 'customer.paid@testaccount.com'],
            [
                'password' => Hash::make('Test1234$'),
                'email_verified_at' => now(),
            ]
        );

        // Assign customer role and starter plan
        if ($customerRole && !$paidCustomer->hasRole('customer')) {
            $paidCustomer->roles()->attach($customerRole->id);
        }

        // Assign customer role and starter plan (without a plan or a free window
        // this account would be access_expired and locked to billing).
        if ($customerRole && !$paidCustomer2->hasRole('customer')) {
            $paidCustomer2->roles()->attach($customerRole->id);
        }

        if ($starterPlan && !$paidCustomer2->hasActivePlan()) {
            $paidCustomer2->plans()->attach($starterPlan->id, [
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => now()->addMonth(), // Monthly billing cycle
            ]);
        }

        // A self signup whose 7-day card-free window has just closed: no plan row
        // (an active "free" plan would count as access), onboarding finished
        // during the window. Logging in should land on Billing (access_expired).
        // Reset on every run so the window is always an hour in the past.
        $expiredTrial = User::firstOrCreate(
            ['email' => 'expired@customer.com'],
            ['password' => Hash::make('password')]
        );
        $expiredTrial->forceFill([
            'email_verified_at' => now()->subDays(User::CARD_FREE_DAYS),
            'onboarding_completed_at' => now()->subDays(User::CARD_FREE_DAYS),
            'promo_expires_at' => now()->subHour(),
        ])->save();
        $expiredTrial->plans()->detach();
        if ($customerRole && !$expiredTrial->hasRole('customer')) {
            $expiredTrial->roles()->attach($customerRole->id);
        }

        // Create tokens for testing
        $adminToken = $adminUser->createToken('admin-token-' . now()->timestamp)->plainTextToken;
        $freeToken = $freeCustomer->createToken('free-customer-token-' . now()->timestamp)->plainTextToken;
        $paidToken = $paidCustomer->createToken('paid-customer-token-' . now()->timestamp)->plainTextToken;
        $paidToken2 = $paidCustomer2->createToken('paid-customer-token-' . now()->timestamp)->plainTextToken;

        $this->command->info("Admin user: {$adminUser->email}");
        $this->command->info("Admin token: {$adminToken}");
        $this->command->info("Free customer: {$freeCustomer->email}");
        $this->command->info("Free customer token: {$freeToken}");
        $this->command->info("Paid customer: {$paidCustomer->email}");
        $this->command->info("Paid customer token: {$paidToken}");
        $this->command->info("Paid customer 2: {$paidCustomer2->email}");
        $this->command->info("Paid customer 2 token: {$paidToken2}");
        $this->command->info("Expired trial: {$expiredTrial->email} / password (window closed {$expiredTrial->promo_expires_at->diffForHumans()})");
    }
}
