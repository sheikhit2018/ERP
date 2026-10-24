<?php
namespace App\Models;
class TenantSetting extends TenantModel {
    protected $table = 'tenant_settings';
    protected $casts = ['value' => 'encrypted'];
}
