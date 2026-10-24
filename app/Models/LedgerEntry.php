<?php
namespace App\Models;
class LedgerEntry extends TenantModel {
    protected $table = 'ledger_entries';
    protected $casts = ['entry_date' => 'date'];
    public function account() { return $this->belongsTo(MoneyAccount::class, 'money_account_id'); }
}
