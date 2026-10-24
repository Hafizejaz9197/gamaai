<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;

class CreateAccount extends Command
{
    protected $signature = 'gama:account-create {name} {--budget= : Monthly token budget; leave out for no limit}';

    protected $description = 'Create a customer account';

    public function handle(): int
    {
        $budget = $this->option('budget');
        if ($budget !== null && (! ctype_digit((string) $budget))) {
            $this->error('--budget must be a whole number of tokens.');

            return self::FAILURE;
        }

        $account = Account::create([
            'name' => $this->argument('name'),
            'monthly_token_budget' => $budget === null ? null : (int) $budget,
        ]);
        $this->info("Account {$account->id} created.");

        return self::SUCCESS;
    }
}
