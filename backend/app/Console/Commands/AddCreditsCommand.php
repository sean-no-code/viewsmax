<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CreditService;
use Illuminate\Console\Command;

class AddCreditsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'credits:add 
                            {user : The user email or ID}
                            {amount : The amount of credits to add}
                            {--description= : Optional description for the credit addition}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add credits to a user account';

    /**
     * Execute the console command.
     */
    public function handle(CreditService $creditService)
    {
        $userIdentifier = $this->argument('user');
        $amount = (int) $this->argument('amount');
        $description = $this->option('description') ?? 'Credits added via command line';

        // Find user by email or ID
        $user = is_numeric($userIdentifier)
            ? User::find($userIdentifier)
            : User::where('email', $userIdentifier)->first();

        if (!$user) {
            $this->error("User not found: {$userIdentifier}");
            return 1;
        }

        // Get current balance
        $currentBalance = $creditService->getRemainingCredits($user);

        // Confirm the action
        $this->info("User: {$user->email} (ID: {$user->id})");
        $this->info("Current balance: {$currentBalance} credits");
        $this->info("Amount to add: {$amount} credits");
        $this->info("New balance will be: " . ($currentBalance + $amount) . " credits");

        if (!$this->confirm('Do you want to proceed?', true)) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Add credits
        try {
            $creditService->addCredits($user, $amount, $description);
            
            // Refresh to get updated balance
            $user->refresh();
            $newBalance = $creditService->getRemainingCredits($user);

            $this->info("✓ Successfully added {$amount} credits to user {$user->email}");
            $this->info("New balance: {$newBalance} credits");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to add credits: " . $e->getMessage());
            return 1;
        }
    }
}

