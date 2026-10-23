<?php
namespace App\Tenancy;
use Illuminate\Database\Eloquent\{Builder, Model, Scope};
final class TenantScope implements Scope {
    public function apply(Builder $builder, Model $model): void {
        $builder->where($model->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
    }
}
