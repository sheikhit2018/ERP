import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, Empty, PageHeader, td } from '@/Components/ui';
import { num, taka } from '@/lib/format';
import { PageProps, SubscriptionState } from '@/types';
import { Head } from '@inertiajs/react';

type Plan = { id: number; name: string; price_minor: number; users_limit: number | null; orders_limit: number | null; locations_limit: number | null; features: string | null };
type Props = { state: SubscriptionState; usage: { users: number; orders: number; locations: number }; plans: Plan[];
    payments: { amount_minor: number; months: number; method: string; reference: string | null; paid_on: string; period_end: string }[]; note: string };

function Meter({ label, used, limit }: { label: string; used: number; limit: number | null | undefined }) {
    const pct = limit ? Math.min(100, Math.round((100 * used) / limit)) : 0;
    return <div><div className="flex justify-between text-sm"><span>{label}</span><span className="tabular-nums">{num(used)} / {limit ? num(limit) : '∞'}</span></div>
        <div className="mt-1 h-2 rounded bg-slate-100"><div className={`h-2 rounded ${pct >= 90 ? 'bg-rose-500' : 'bg-brand-600'}`} style={{ width: `${limit ? pct : 4}%` }} /></div></div>;
}

export default function Billing({ state, usage, plans, payments, note }: PageProps<Props>) {
    const p = state.plan;
    return (
        <AuthenticatedLayout>
            <Head title="Plan & billing" />
            <PageHeader title="Plan & billing" subtitle={p ? `${p.name} plan · ${taka(p.price_minor)}/month` : 'Unlimited plan'} />
            <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Your subscription"><div className="space-y-4 p-5">
                    <div className={`rounded-lg p-3 text-sm ${state.read_only ? 'bg-rose-50 text-rose-800' : state.status === 'trial' || (state.days_left ?? 99) <= 7 ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800'}`}>
                        {!p ? 'No limits on this account.' : state.read_only ? 'Expired — your data is safe but read-only until you renew.' : state.status === 'trial' ? `Free trial: ${state.days_left} days left (until ${state.until}).` : state.until ? `Paid until ${state.until} (${state.days_left} days).` : 'Active.'}
                    </div>
                    {p && <><Meter label="Active users" used={usage.users} limit={p.users_limit} /><Meter label="Orders this month" used={usage.orders} limit={p.orders_limit} /><Meter label="Shops / extra warehouses" used={usage.locations} limit={p.locations_limit} /></>}
                    <p className="text-sm text-slate-600">{note}</p>
                </div></Card>
                <Card title="Plans">
                    <ul className="divide-y divide-slate-100">{plans.map((x) => (
                        <li key={x.id} className={`px-5 py-3 ${p?.id === x.id ? 'bg-brand-50' : ''}`}><div className="flex justify-between"><span className="font-semibold">{x.name}{p?.id === x.id && ' (current)'}</span><span className="tabular-nums">{taka(x.price_minor)}/mo</span></div>
                            <div className="text-xs text-slate-500">{x.users_limit ?? 'Unlimited'} users · {x.orders_limit ? num(x.orders_limit) : 'unlimited'} orders/month · {x.locations_limit ?? 'unlimited'} shops{x.features ? ` · ${x.features}` : ''}</div></li>))}</ul>
                </Card>
                <Card title="Payment history" className="lg:col-span-2">
                    {payments.length === 0 ? <Empty>No payments yet.</Empty> : <table className="min-w-full divide-y divide-slate-100"><tbody className="divide-y divide-slate-100">{payments.map((x, i) => (
                        <tr key={i}><td className={td}>{x.paid_on}</td><td className={td}>{taka(x.amount_minor)}</td><td className={td}>{x.months} month(s)</td><td className={`${td} text-xs`}>{x.method} {x.reference}</td><td className={`${td} text-xs text-slate-500`}>covers until {x.period_end}</td></tr>))}</tbody></table>}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
