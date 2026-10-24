<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

/** User recorded as "created by" for automatic actions (webhooks, scheduled sync): the company's first active owner. */
final class SystemActor {
    public static function id(): int {
        $t = app(\App\Tenancy\TenantContext::class)->id();
        $id = DB::table('tenant_user')->where('tenant_id', $t)->where('active', true)->orderByRaw("case when role = 'owner' then 0 else 1 end")->orderBy('id')->value('user_id');
        abort_unless($id, 409, 'Company has no active user.');
        return (int) $id;
    }
}
