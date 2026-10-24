<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Owner-only: add staff to this company and set their role. Users are global identities. */
class TeamController extends Controller {
    public const ROLES = ['owner', 'manager', 'sales', 'warehouse', 'manufacturer', 'qc'];

    public function index() {
        $members = DB::table('tenant_user as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', app(TenantContext::class)->id())
            ->orderBy('u.name')->get(['m.id', 'u.name', 'u.email', 'm.role', 'm.active']);
        return Inertia::render('Team/Index', ['members' => $members, 'roles' => self::ROLES]);
    }
    public function store(Request $r) {
        $d = $r->validate([
            'name' => 'required|string|max:150', 'email' => 'required|email|max:190',
            'password' => ['nullable', Password::min(8)], 'role' => 'required|in:'.implode(',', self::ROLES),
        ]);
        $tenant = app(TenantContext::class)->id();
        \App\Support\Subscription::assertWithin($tenant, 'users');
        DB::transaction(function () use ($d, $tenant) {
            $user = User::where('email', strtolower($d['email']))->first();
            if (!$user) {
                if (empty($d['password'])) throw ValidationException::withMessages(['password' => 'Password is required for a new user.']);
                $user = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'password' => Hash::make($d['password'])]);
            }
            if (DB::table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $user->id)->exists()) throw ValidationException::withMessages(['email' => 'This person is already a member.']);
            DB::table('tenant_user')->insert(['tenant_id' => $tenant, 'user_id' => $user->id, 'role' => $d['role'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        });
        return back()->with('success', 'Team member added.');
    }
    public function update(Request $r, int $member) {
        $d = $r->validate(['role' => 'required|in:'.implode(',', self::ROLES), 'active' => 'required|boolean']);
        $tenant = app(TenantContext::class)->id();
        $row = DB::table('tenant_user')->where('tenant_id', $tenant)->where('id', $member)->first();
        abort_unless($row, 404);
        if ((int) $row->user_id === (int) $r->user()->id && ($d['role'] !== 'owner' || !$d['active'])) throw ValidationException::withMessages(['role' => 'You cannot demote or disable yourself.']);
        DB::table('tenant_user')->where('tenant_id', $tenant)->where('id', $member)->update(['role' => $d['role'], 'active' => $d['active'], 'updated_at' => now()]);
        return back()->with('success', 'Member updated.');
    }
}
