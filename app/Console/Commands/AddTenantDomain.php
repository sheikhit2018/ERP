<?php
namespace App\Console\Commands;

use App\Support\TenantProvisioner;
use Illuminate\Console\Command;

class AddTenantDomain extends Command {
    protected $signature = 'opryx:domain {tenant_id} {domain}';
    protected $description = 'Attach another domain (e.g. opryx.app subdomain or a client custom domain) to a company';
    public function handle(): int {
        TenantProvisioner::addDomain((int) $this->argument('tenant_id'), $this->argument('domain'));
        $this->info('Domain added.');
        return 0;
    }
}
