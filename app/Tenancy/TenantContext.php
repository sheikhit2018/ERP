<?php
namespace App\Tenancy;
final class TenantContext {
    private ?int $tenantId = null;
    public function set(int $id): void { $this->tenantId = $id; }
    public function clear(): void { $this->tenantId = null; }
    public function id(): int {
        if ($this->tenantId === null) throw new \LogicException('Tenant context is required.');
        return $this->tenantId;
    }
}
