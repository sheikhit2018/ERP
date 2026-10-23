<?php
namespace App\Models;
use App\Tenancy\{TenantContext, TenantScope};
use Illuminate\Database\Eloquent\Model;
abstract class TenantModel extends Model {
    protected $guarded = ['id', 'tenant_id'];
    protected static function booted(): void {
        static::addGlobalScope(new TenantScope);
        static::creating(function (self $model) {
            $id = app(TenantContext::class)->id();
            if ($model->tenant_id !== null && (int)$model->tenant_id !== $id) throw new \LogicException('Tenant mismatch');
            $model->tenant_id = $id;
        });
        static::saving(function (self $model) {
            $id = app(TenantContext::class)->id();
            if ($model->exists && ((int)$model->getOriginal('tenant_id') !== $id || $model->isDirty('tenant_id'))) throw new \LogicException('Tenant cannot change');
        });
        static::deleting(function (self $model) {
            if ((int)$model->tenant_id !== app(TenantContext::class)->id()) throw new \LogicException('Tenant mismatch');
        });
    }
}
