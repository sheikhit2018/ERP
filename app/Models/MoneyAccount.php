<?php
namespace App\Models;
class MoneyAccount extends TenantModel {
    protected $table = 'money_accounts';
    protected $casts = ['active' => 'boolean'];
    public function entries() { return $this->hasMany(LedgerEntry::class); }
}
