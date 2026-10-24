<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Site;
use Illuminate\Console\Command;

class CreateSite extends Command
{
    protected $signature = 'gama:site-create {account : Account id} {name} {url}';

    protected $description = 'Register a WordPress site and print its plugin token (shown once)';

    public function handle(): int
    {
        $account = Account::find($this->argument('account'));
        if ($account === null) {
            $this->error('Account not found.');

            return self::FAILURE;
        }
        if (! filter_var($this->argument('url'), FILTER_VALIDATE_URL)) {
            $this->error('The URL is not valid.');

            return self::FAILURE;
        }

        $site = new Site(['name' => $this->argument('name'), 'url' => $this->argument('url')]);
        $site->account()->associate($account);
        $token = $site->issueToken();
        $site->save();

        $this->info("Site {$site->id} created. Plugin token (store it now, it is not shown again):");
        $this->line($token);

        return self::SUCCESS;
    }
}
