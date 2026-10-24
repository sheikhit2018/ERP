import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Select, td, th } from '@/Components/ui';
import { dateTime, num, taka, toMinor } from '@/lib/format';
import { PageProps, SubscriptionState } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';

type Props = { tenant: { id: number; name: string; plan_id: number | null; status: string; trial_ends_at: string | null; paid_until: string | null; contact_phone: string | null; contact_email: string | null; note: string | null };
    state: SubscriptionState; plans: { id: number; name: string; price_minor: number }[]; domains: { id: number; hostname: string }[];
    members: { id: number; name: string; email: string; role: string; active: boolean; two_factor_confirmed_at: string | null }[];
    payments: { id: number; amount_minor: number; months: number; method: string; reference: string | null; paid_on: string; period_end: string }[];
    usage: { orders_month: number; orders_total: number; products: number; locations: number }; activity: { action: string; created_at: string; ip: string | null; name: string | null }[] };

export default function PlatformTenant({ tenant, state, plans, domains, members, payments, usage, activity }: PageProps<Props>) {
    const f = useForm({ name: tenant.name, plan_id: tenant.plan_id ? String(tenant.plan_id) : '', status: tenant.status, trial_ends_at: tenant.trial_ends_at ?? '', paid_until: tenant.paid_until ?? '',
        contact_phone: tenant.contact_phone ?? '', contact_email: tenant.contact_email ?? '', note: tenant.note ?? '' });
    const plan = plans.find((p) => String(p.id) === f.data.plan_id);
    const pay = useForm({ amount: plan ? String(plan.price_minor / 100) : '', months: '1', method: 'bkash', reference: '', paid_on: new Date().toLocaleDateString('en-CA'), note: '' });
    const dom = useForm({ hostname: '' });
    return (
        <AuthenticatedLayout>
            <Head title={tenant.name} />
            <div className="mb-2"><Link href="/platform" className="text-sm text-slate-500">← Platform</Link></div>
            <PageHeader title={tenant.name} subtitle={`${state.plan?.name ?? 'Internal / unlimited'} · ${state.suspended ? 'suspended' : state.read_only ? 'expired — read-only' : state.status}${state.until ? ` · until ${state.until}` : ''}`} />
            <div className="mb-6 grid gap-3 sm:grid-cols-4">{([['Orders this month', `${num(usage.orders_month)}${state.plan?.orders_limit ? ` / ${num(state.plan.orders_limit)}` : ''}`], ['Orders total', num(usage.orders_total)], ['Products', num(usage.products)], ['Users', `${members.filter((m) => m.active).length}${state.plan?.users_limit ? ` / ${state.plan.users_limit}` : ''}`]] as const).map(([l, v]) => (
                <div key={l} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><div className="text-xs text-slate-500">{l}</div><div className="mt-1 text-xl font-bold tabular-nums">{v}</div></div>))}</div>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Subscription">
                    <form className="grid gap-3 p-5 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); f.transform((d) => ({ ...d, plan_id: d.plan_id ? Number(d.plan_id) : null, trial_ends_at: d.trial_ends_at || null, paid_until: d.paid_until || null,
                        contact_phone: d.contact_phone || null, contact_email: d.contact_email || null, note: d.note || null })); f.put(`/platform/tenants/${tenant.id}`, { preserveScroll: true }); }}>
                        <Field label="Company name"><Input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} /></Field>
                        <Field label="Plan"><Select value={f.data.plan_id} onChange={(e) => f.setData('plan_id', e.target.value)}><option value="">Internal / unlimited</option>{plans.map((p) => <option key={p.id} value={p.id}>{p.name} — {taka(p.price_minor)}</option>)}</Select></Field>
                        <Field label="Status" error={(f.errors as any).status}><Select value={f.data.status} onChange={(e) => f.setData('status', e.target.value)}><option value="trial">Trial</option><option value="active">Active (paid)</option><option value="suspended">Suspended</option></Select></Field>
                        <Field label="Trial ends"><Input type="date" value={f.data.trial_ends_at} onChange={(e) => f.setData('trial_ends_at', e.target.value)} /></Field>
                        <Field label="Paid until"><Input type="date" value={f.data.paid_until} onChange={(e) => f.setData('paid_until', e.target.value)} /></Field>
                        <Field label="Phone"><Input value={f.data.contact_phone} onChange={(e) => f.setData('contact_phone', e.target.value)} /></Field>
                        <Field label="Email"><Input value={f.data.contact_email} onChange={(e) => f.setData('contact_email', e.target.value)} /></Field>
                        <Field label="Internal note"><Input value={f.data.note} onChange={(e) => f.setData('note', e.target.value)} /></Field>
                        <div className="sm:col-span-2"><Button className="w-full" disabled={f.processing}>Save</Button></div>
                    </form>
                </Card>
                <Card title="Record payment">
                    <form className="grid gap-3 p-5 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); pay.transform((d) => ({ amount_minor: toMinor(d.amount), months: Number(d.months), method: d.method, reference: d.reference || null, paid_on: d.paid_on, note: d.note || null }));
                        pay.post(`/platform/tenants/${tenant.id}/payments`, { preserveScroll: true, onSuccess: () => pay.reset('reference', 'note') }); }}>
                        <Field label="Amount ৳"><Input type="number" step="0.01" value={pay.data.amount} onChange={(e) => pay.setData('amount', e.target.value)} required /></Field>
                        <Field label="Months"><Input type="number" min="1" value={pay.data.months} onChange={(e) => pay.setData('months', e.target.value)} /></Field>
                        <Field label="Method"><Select value={pay.data.method} onChange={(e) => pay.setData('method', e.target.value)}>{['bkash', 'nagad', 'bank', 'cash', 'card', 'other'].map((m) => <option key={m}>{m}</option>)}</Select></Field>
                        <Field label="Trx ID / reference"><Input value={pay.data.reference} onChange={(e) => pay.setData('reference', e.target.value)} /></Field>
                        <Field label="Paid on"><Input type="date" value={pay.data.paid_on} onChange={(e) => pay.setData('paid_on', e.target.value)} /></Field>
                        <div className="flex items-end"><Button className="w-full" disabled={pay.processing}>Save payment</Button></div>
                    </form>
                    {payments.length === 0 ? <Empty>No payments yet.</Empty> : <table className="min-w-full divide-y divide-slate-100 border-t border-slate-100"><tbody className="divide-y divide-slate-100">{payments.map((p) => (
                        <tr key={p.id}><td className={`${td} text-xs`}>{p.paid_on}</td><td className={td}>{taka(p.amount_minor)} · {p.months} mo</td><td className={`${td} text-xs`}>{p.method} {p.reference}</td><td className={`${td} text-xs text-slate-500`}>→ {p.period_end}</td></tr>))}</tbody></table>}
                </Card>
                <Card title="Domains">
                    <ul className="divide-y divide-slate-100">{domains.map((d) => <li key={d.id} className="flex justify-between px-4 py-2 text-sm"><a className="text-brand-700" href={`https://${d.hostname}`} target="_blank">{d.hostname}</a>
                        {domains.length > 1 && <button className="text-xs text-rose-700" onClick={() => window.confirm(`Remove ${d.hostname}?`) && router.post(`/platform/tenants/${tenant.id}/domains`, { remove: d.id }, { preserveScroll: true })}>Remove</button>}</li>)}</ul>
                    <form className="flex gap-2 border-t border-slate-100 p-3" onSubmit={(e) => { e.preventDefault(); dom.post(`/platform/tenants/${tenant.id}/domains`, { preserveScroll: true, onSuccess: () => dom.reset() }); }}>
                        <Input placeholder="erp.client.com" value={dom.data.hostname} onChange={(e) => dom.setData('hostname', e.target.value)} required /><Button disabled={dom.processing}>Add</Button></form>
                    {(dom.errors as any).hostname && <p className="px-3 pb-3 text-xs text-rose-600">{(dom.errors as any).hostname}</p>}
                </Card>
                <Card title="Team">
                    <table className="min-w-full divide-y divide-slate-100"><tbody className="divide-y divide-slate-100">{members.map((m) => (
                        <tr key={m.id} className={m.active ? '' : 'opacity-50'}><td className={td}>{m.name}<div className="text-xs text-slate-500">{m.email}</div></td><td className={`${td} text-xs capitalize`}>{m.role}</td><td className={`${td} text-xs`}>{m.two_factor_confirmed_at ? '2FA on' : ''}</td></tr>))}</tbody></table>
                </Card>
                <Card title="Recent activity" className="lg:col-span-2">
                    {activity.length === 0 ? <Empty>No activity yet.</Empty> : <table className="min-w-full divide-y divide-slate-100"><thead><tr><th className={th}>When</th><th className={th}>Who</th><th className={th}>Action</th><th className={th}>IP</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">{activity.map((a, i) => <tr key={i}><td className={`${td} text-xs`}>{dateTime(a.created_at)}</td><td className={td}>{a.name ?? '—'}</td><td className={`${td} font-mono text-xs`}>{a.action}</td><td className={`${td} text-xs`}>{a.ip}</td></tr>)}</tbody></table>}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
