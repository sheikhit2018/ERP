<?php
namespace App\Console\Commands;

use App\Support\TenantProvisioner;
use Illuminate\Console\Command;

class CreateTenant extends Command {
    protected $signature = 'opryx:tenant {company} {domain} {owner_email} {--owner-name=Owner} {--password=}';
    protected $description = 'Create a company (tenant) with its domain and owner login';
    public function handle(): int {
        $password = $this->option('password') ?: $this->secret('Owner password (min 8 chars)');
        if (strlen((string) $password) < 8) { $this->error('Password must be at least 8 characters.'); return 1; }
        $id = TenantProvisioner::create($this->argument('company'), $this->argument('domain'), $this->option('owner-name'), $this->argument('owner_email'), $password);
        $this->info("Company #$id created. Log in at https://".TenantProvisioner::normalizeHost($this->argument('domain')).'/login');
        return 0;
    }
}
