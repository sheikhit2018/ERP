import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, td, th } from '@/Components/ui';
import { dateTime, num, taka } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Totals = Record<'delivered_parcels' | 'delivered_expected' | 'settled_parcels' | 'collected' | 'charges' | 'net' | 'unsettled_parcels' | 'unsettled_expected' | 'short_parcels' | 'short_amount', number>;
type Row = { id: number; order_id: number; courier: string; tracking_id: string; customer: string; handed_over_at: string; delivered_at: string | null; expected: number; collected: number | null; charge: number | null; partial: boolean };
type Statement = { id: number; courier: string; reference: string | null; paid_on: string; parcel_count: number; collected_minor: number; charge_minor: number; net_minor: number; created_by: number; note: string | null };
type Props = { filters: { from: string; to: string; courier: string | null }; couriers: Record<string, string>; totals: Totals; unsettled: Row[]; short: Row[];
    statements: Statement[]; advance: { method: string; n: number; total: number }[]; users: Record<string, string> };

const days = (iso: string | null) => (iso ? Math.floor((Date.now() - new Date(iso).getTime()) / 86400000) : 0);

export default function Cod({ filters, couriers, totals: t, unsettled, short, statements, advance, users, flash }: PageProps<Props>) {
    const [f, setF] = useState({ from: filters.from, to: filters.to, courier: filters.courier ?? '' });
    const apply = (e: FormEvent) => { e.preventDefault(); router.get('/cod', f, { preserveState: true }); };
    const tiles: [string, string, string, string?][] = [
        ['COD of delivered parcels', taka(t.delivered_expected), `${num(t.delivered_parcels)} parcels`],
        ['Collected (statements)', taka(t.collected), `${num(t.settled_parcels)} parcels settled`, 'text-emerald-700'],
        ['Courier charges', taka(t.charges), 'delivery + COD charge', 'text-slate-700'],
        ['Net paid to you', taka(t.net), 'collected − charges', 'text-emerald-800'],
        ['Not paid yet', taka(t.unsettled_expected), `${num(t.unsettled_parcels)} delivered parcels`, t.unsettled_parcels ? 'text-amber-700' : 'text-slate-400'],
        ['Short paid', taka(t.short_amount), `${num(t.short_parcels)} parcels`, t.short_parcels ? 'text-rose-600' : 'text-slate-400'],
    ];
    const table = (rows: Row[], showPaid: boolean) => (
        <div className="max-h-[480px] overflow-auto"><table className="min-w-full divide-y divide-slate-100">
            <thead className="sticky top-0 bg-white"><tr><th className={th}>Order</th><th className={th}>Courier / tracking</th><th className={th}>Delivered</th><th className={`${th} text-right`}>Expected</th>
                {showPaid && <><th className={`${th} text-right`}>Collected</th><th className={`${th} text-right`}>Short</th></>}</tr></thead>
            <tbody className="divide-y divide-slate-100">
                {rows.map((r) => (
                    <tr key={r.id}>
                        <td className={td}><Link href={`/orders/${r.order_id}`} className="font-semibold text-brand-700">#{r.order_id}</Link><div className="text-xs text-slate-500">{r.customer}</div></td>
                        <td className={`${td} text-xs`}>{couriers[r.courier] ?? r.courier}<div className="font-mono">{r.tracking_id}</div></td>
                        <td className={`${td} text-xs`}>{r.partial ? <span className="text-teal-700">Partly delivered</span> : dateTime(r.delivered_at)}
                            {!showPaid && r.delivered_at && <div className={`font-semibold ${days(r.delivered_at) > 7 ? 'text-rose-600' : 'text-slate-500'}`}>{days(r.delivered_at)} days unpaid</div>}</td>
                        <td className={`${td} text-right tabular-nums`}>{r.partial ? '—' : taka(r.expected)}</td>
                        {showPaid && <><td className={`${td} text-right tabular-nums`}>{taka(r.collected)}</td><td className={`${td} text-right font-semibold tabular-nums text-rose-600`}>{taka(r.expected - (r.collected ?? 0))}</td></>}
                    </tr>
                ))}
            </tbody>
        </table></div>
    );

    return (
        <AuthenticatedLayout>
            <Head title="COD & payments" />
            <PageHeader title="COD & payments" subtitle="Match what couriers collected for your delivered parcels with what they paid you. Import each courier payment statement."
                actions={<LinkButton href="/cod/import">Import courier payment</LinkButton>} />
            <form onSubmit={apply} className="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <Field label="Handed over from"><Input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                <Field label="To"><Input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                <Field label="Courier"><Select value={f.courier} onChange={(e) => setF({ ...f, courier: e.target.value })}><option value="">All couriers</option>
                    {Object.entries(couriers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                <Button variant="secondary">Apply</Button>
            </form>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                {tiles.map(([l, v, sub, tone]) => (
                    <div key={l} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="text-xs font-medium text-slate-500">{l}</div>
                        <div className={`mt-1 text-xl font-semibold tabular-nums ${tone ?? 'text-slate-900'}`}>{v}</div>
                        <div className="text-xs text-slate-500">{sub}</div>
                    </div>
                ))}
            </div>
            {flash.report && flash.report.length > 0 && (
                <div className="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"><b>{flash.report.length} statement rows were skipped:</b>
                    <ul className="mt-1 list-disc pl-5">{flash.report.slice(0, 50).map((r) => <li key={r}>{r}</li>)}</ul></div>
            )}
            {advance.length > 0 && <p className="mt-3 text-sm text-slate-600">Advance received in this period: {advance.map((a) => `${a.method} ${taka(a.total)} (${a.n})`).join(' · ')}</p>}

            <div className="mt-6 grid gap-6 xl:grid-cols-2">
                <Card title={`Delivered but not paid yet (${unsettled.length})`}>{unsettled.length ? table(unsettled, false) : <Empty>All delivered parcels are settled.</Empty>}</Card>
                <Card title={`Short paid (${short.length})`}>{short.length ? table(short, true) : <Empty>No short payments.</Empty>}</Card>
            </div>
            <Card className="mt-6" title="Courier payment statements">
                {statements.length === 0 ? <Empty>No statements yet. Download the payment statement from the courier panel and import it.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Paid on</th><th className={th}>Courier</th><th className={th}>Reference</th><th className={`${th} text-right`}>Parcels</th><th className={`${th} text-right`}>Collected</th><th className={`${th} text-right`}>Charges</th><th className={`${th} text-right`}>Net</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {statements.map((s) => (
                                <tr key={s.id}><td className={td}>{s.paid_on?.slice(0, 10)}<div className="text-xs text-slate-400">{users[s.created_by] ?? ''}</div></td><td className={td}>{couriers[s.courier] ?? s.courier}</td>
                                    <td className={`${td} font-mono text-xs`}>{s.reference}</td><td className={`${td} text-right tabular-nums`}>{num(s.parcel_count)}</td>
                                    <td className={`${td} text-right tabular-nums`}>{taka(s.collected_minor)}</td><td className={`${td} text-right tabular-nums`}>{taka(s.charge_minor)}</td>
                                    <td className={`${td} text-right font-semibold tabular-nums`}>{taka(s.net_minor)}</td></tr>
                            ))}
                        </tbody>
                    </table></div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
