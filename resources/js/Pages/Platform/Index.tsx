import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Select, td, th } from '@/Components/ui';
import { dateTime, num, taka, toMinor } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Plan = { id: number; name: string; price_minor: number; users_limit: number | null; orders_limit: number | null; locations_limit: number | null; features: string | null; sort: number; active: boolean };
type T = { id: number; name: string; active: boolean; status: string; plan: string | null; until: string | null; days_left: number | null; read_only: boolean; orders_30d: number; users: number;
    last_activity: string | null; domains: string[]; contact_phone: string | null; created_at: string };
type Props = { tenants: T[]; plans: Plan[]; stats: { companies: number; paying: number; trial: number; mrr: number; revenue_30d: number }; signup: { host: string | null; root: string | null } };

const tone = (t: T) => t.status === 'suspended' ? 'bg-slate-200 text-slate-600' : t.read_only ? 'bg-rose-100 text-rose-700' : t.status === 'trial' ? 'bg-amber-100 text-amber-800' : (t.days_left ?? 99) <= 7 ? 'bg-orange-100 text-orange-800' : 'bg-emerald-100 text-emerald-800';

export default function PlatformIndex({ tenants, plans, stats, signup }: PageProps<Props>) {
    const f = useForm({ company: '', domain: '', owner_name: '', owner_email: '', owner_password: '', plan_id: String(plans[0]?.id ?? ''), trial_days: '14', contact_phone: '' });
    const pf = useForm({ id: 0, name: '', price: '', users_limit: '', orders_limit: '', locations_limit: '', features: '', active: true });
    const [q, setQ] = useState('');
    const err = f.errors as Record<string, string>;
    const shown = tenants.filter((t) => !q || `${t.name} ${t.domains.join(' ')} ${t.contact_phone ?? ''}`.toLowerCase().includes(q.toLowerCase()));
    return (
        <AuthenticatedLayout>
            <Head title="Platform" />
            <PageHeader title="Platform admin" subtitle="All client companies on this Opryx installation." />
            <div className="mb-6 grid gap-3 sm:grid-cols-5">{([['Companies', num(stats.companies)], ['Paying', num(stats.paying)], ['On trial', num(stats.trial)], ['Monthly recurring', taka(stats.mrr)], ['Collected (30 days)', taka(stats.revenue_30d)]] as const).map(([l, v]) => (
                <div key={l} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><div className="text-xs text-slate-500">{l}</div><div className="mt-1 text-xl font-bold tabular-nums">{v}</div></div>))}</div>
            <Card className="mb-6" actions={<Input className="!w-56" placeholder="Search company / domain…" value={q} onChange={(e) => setQ(e.target.value)} />} title="Companies">
                {shown.length === 0 ? <Empty>No companies.</Empty> : <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                    <thead><tr><th className={th}>Company</th><th className={th}>Plan</th><th className={th}>Status</th><th className={`${th} text-right`}>Users</th><th className={`${th} text-right`}>Orders 30d</th><th className={th}>Last activity</th></tr></thead>
                    <tbody className="divide-y divide-slate-100">{shown.map((t) => (
                        <tr key={t.id}><td className={td}><Link href={`/platform/tenants/${t.id}`} className="font-semibold text-brand-700">{t.name}</Link><div className="text-xs text-slate-500">{t.domains.join(', ')}</div></td>
                            <td className={td}>{t.plan ?? <span className="text-slate-400">Internal / unlimited</span>}</td>
                            <td className={td}><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tone(t)}`}>{t.status === 'suspended' ? 'Suspended' : t.read_only ? 'Expired (read-only)' : t.status === 'trial' ? `Trial · ${t.days_left}d` : t.until ? `Paid · ${t.days_left}d left` : 'Active'}</span></td>
                            <td className={`${td} text-right tabular-nums`}>{t.users}</td><td className={`${td} text-right tabular-nums`}>{num(t.orders_30d)}</td>
                            <td className={`${td} text-xs`}>{t.last_activity ? dateTime(t.last_activity) : '—'}</td></tr>))}</tbody>
                </table></div>}
            </Card>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Onboard a new client company">
                    <form className="grid gap-3 p-5 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); f.transform((d) => ({ ...d, plan_id: d.plan_id ? Number(d.plan_id) : null, trial_days: Number(d.trial_days) || 0 })); f.post('/platform/tenants'); }}>
                        <Field label="Company name" error={err.company}><Input value={f.data.company} onChange={(e) => f.setData('company', e.target.value)} required /></Field>
                        <Field label="Domain" error={err.domain} hint="e.g. erp.clientbrand.com"><Input value={f.data.domain} onChange={(e) => f.setData('domain', e.target.value)} required /></Field>
                        <Field label="Owner name" error={err.owner_name}><Input value={f.data.owner_name} onChange={(e) => f.setData('owner_name', e.target.value)} required /></Field>
                        <Field label="Owner email (login)" error={err.owner_email}><Input type="email" value={f.data.owner_email} onChange={(e) => f.setData('owner_email', e.target.value)} required /></Field>
                        <Field label="First password" error={err.owner_password} hint="Owner changes it after login"><Input type="text" value={f.data.owner_password} onChange={(e) => f.setData('owner_password', e.target.value)} required minLength={8} /></Field>
                        <Field label="Phone"><Input value={f.data.contact_phone} onChange={(e) => f.setData('contact_phone', e.target.value)} /></Field>
                        <Field label="Plan"><Select value={f.data.plan_id} onChange={(e) => f.setData('plan_id', e.target.value)}><option value="">Internal / unlimited</option>{plans.filter((p) => p.active).map((p) => <option key={p.id} value={p.id}>{p.name} — {taka(p.price_minor)}/mo</option>)}</Select></Field>
                        <Field label="Free trial days"><Input type="number" min="0" value={f.data.trial_days} onChange={(e) => f.setData('trial_days', e.target.value)} /></Field>
                        <div className="sm:col-span-2"><Button className="w-full" disabled={f.processing}>Create company</Button></div>
                    </form>
                    <p className="px-5 pb-4 text-xs text-slate-500">Then add the domain in hosting (Hostinger → Domains → add as alias / parked domain pointing to this app) and its DNS A record. Self sign-up: {signup.host ? `on ${signup.host} → *.${signup.root}` : 'off (set OPRYX_SIGNUP_HOST and OPRYX_TENANT_ROOT with a wildcard subdomain to enable)'}.</p>
                </Card>
                <Card title="Plans">
                    <ul className="divide-y divide-slate-100">{plans.map((p) => (
                        <li key={p.id} className={`flex items-center justify-between px-4 py-2.5 text-sm ${p.active ? '' : 'opacity-50'}`}>
                            <div><span className="font-semibold">{p.name}</span> <span className="tabular-nums">{taka(p.price_minor)}/mo</span>
                                <div className="text-xs text-slate-500">{p.users_limit ?? '∞'} users · {p.orders_limit ? num(p.orders_limit) : '∞'} orders/mo · {p.locations_limit ?? '∞'} shops{p.features ? ` · ${p.features}` : ''}</div></div>
                            <button className="text-xs text-brand-700" onClick={() => pf.setData({ id: p.id, name: p.name, price: String(p.price_minor / 100), users_limit: p.users_limit?.toString() ?? '', orders_limit: p.orders_limit?.toString() ?? '', locations_limit: p.locations_limit?.toString() ?? '', features: p.features ?? '', active: p.active })}>Edit</button>
                        </li>))}</ul>
                    <form className="grid gap-2 border-t border-slate-100 p-4 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); pf.transform((d) => ({ id: d.id || null, name: d.name, price_minor: toMinor(d.price), users_limit: d.users_limit ? Number(d.users_limit) : null,
                        orders_limit: d.orders_limit ? Number(d.orders_limit) : null, locations_limit: d.locations_limit !== '' ? Number(d.locations_limit) : null, features: d.features || null, active: d.active })); pf.post('/platform/plans', { preserveScroll: true, onSuccess: () => pf.reset() }); }}>
                        <Input placeholder="Plan name" value={pf.data.name} onChange={(e) => pf.setData('name', e.target.value)} required />
                        <Input type="number" step="0.01" placeholder="Price ৳/month" value={pf.data.price} onChange={(e) => pf.setData('price', e.target.value)} required />
                        <Input type="number" placeholder="Users (blank = ∞)" value={pf.data.users_limit} onChange={(e) => pf.setData('users_limit', e.target.value)} />
                        <Input type="number" placeholder="Orders / month" value={pf.data.orders_limit} onChange={(e) => pf.setData('orders_limit', e.target.value)} />
                        <Input type="number" placeholder="Shops" value={pf.data.locations_limit} onChange={(e) => pf.setData('locations_limit', e.target.value)} />
                        <Input placeholder="Features" value={pf.data.features} onChange={(e) => pf.setData('features', e.target.value)} />
                        <label className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={pf.data.active} onChange={(e) => pf.setData('active', e.target.checked)} /> Active</label>
                        <Button className="sm:col-span-2" disabled={pf.processing}>{pf.data.id ? 'Save plan' : 'Add plan'}</Button>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
