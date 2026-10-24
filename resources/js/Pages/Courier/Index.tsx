import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { StatTiles, Stats, Couriers, ageDays } from '@/Components/CourierStats';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, td, th } from '@/Components/ui';
import { dateTime, num } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Row = Stats & { courier?: string; dispatch_batch_id?: number };
type Batch = { id: number; courier: string; parcel_count: number; piece_count: number; note: string | null; created_at: string; created_by: number; stats: Row | null };
type RetBatch = { id: number; courier: string; parcel_count: number; piece_count: number; note: string | null; created_at: string; created_by: number };
type Overdue = { id: number; sales_order_id: number; courier: string; tracking_id: string; piece_count: number; handed_over_at: string; dispatch_batch_id: number | null; order: { customer: { name: string; phone: string } } };
type Props = { filters: { from: string; to: string; courier: string | null; days: number }; couriers: Couriers; total: Stats; byCourier: Row[]; batches: Batch[]; returns: RetBatch[]; overdue: Overdue[]; users: Record<string, string> };

export default function CourierHub({ filters, couriers, total, byCourier, batches, returns, overdue, users, role }: PageProps<Props>) {
    const [f, setF] = useState({ from: filters.from, to: filters.to, courier: filters.courier ?? '', days: String(filters.days) });
    const isWh = ['owner', 'manager', 'warehouse'].includes(role ?? '');
    const canReturn = isWh || role === 'qc';
    const apply = (e: FormEvent) => { e.preventDefault(); router.get('/courier', f, { preserveState: true }); };
    const act = (id: number, what: 'lost' | 'delivered', text: string) => { if (window.confirm(text)) router.post(`/courier/parcels/${id}/${what}`, {}, { preserveScroll: true }); };
    const cell = (s: Row | null | undefined, k: 'delivered' | 'returned' | 'lost' | 'open' | 'overdue', tone = '') =>
        <td className={`${td} text-right tabular-nums ${s && s[`${k}_parcels`] && tone ? tone : ''}`}>{num(s?.[`${k}_parcels`])}<span className="text-xs text-slate-400"> / {num(s?.[`${k}_pieces`])}</span></td>;

    return (
        <AuthenticatedLayout>
            <Head title="Courier hub" />
            <PageHeader title="Courier hub" subtitle="Parcels sent = delivered + returned + lost + still with courier. Anything still with the courier after the return window is overdue."
                actions={<>
                    {isWh && <LinkButton href="/courier/dispatch">Hand over to courier</LinkButton>}
                    {canReturn && <LinkButton variant="secondary" href="/courier/returns">Receive returns</LinkButton>}
                    {isWh && <LinkButton variant="secondary" href="/courier/delivered">Mark delivered</LinkButton>}
                    {isWh && <Button variant="secondary" onClick={() => router.post('/courier/sync', {}, { preserveScroll: true })}>Sync with couriers</Button>}
                </>} />

            <form onSubmit={apply} className="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <Field label="From"><Input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                <Field label="To"><Input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                <Field label="Courier"><Select value={f.courier} onChange={(e) => setF({ ...f, courier: e.target.value })}><option value="">All couriers</option>
                    {Object.entries(couriers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                <Field label="Overdue after (days)"><Input type="number" min="1" max="60" className="!w-24" value={f.days} onChange={(e) => setF({ ...f, days: e.target.value })} /></Field>
                <Button variant="secondary">Apply</Button>
            </form>

            <StatTiles s={total} days={filters.days} />

            {overdue.length > 0 && (
                <Card className="mt-6 border-rose-200" title={`Overdue parcels — still with courier after ${filters.days} days (${overdue.length})`}>
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Order</th><th className={th}>Courier / tracking</th><th className={th}>Customer</th><th className={`${th} text-right`}>Pcs</th><th className={th}>Handed over</th>{isWh && <th className={th} />}</tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {overdue.map((o) => (
                                <tr key={o.id}>
                                    <td className={td}><Link href={`/orders/${o.sales_order_id}`} className="font-semibold text-brand-700">#{o.sales_order_id}</Link></td>
                                    <td className={`${td} text-xs`}>{couriers[o.courier] ?? o.courier}<div className="font-mono">{o.tracking_id}</div></td>
                                    <td className={`${td} text-xs`}>{o.order.customer.name}<div className="text-slate-500">{o.order.customer.phone}</div></td>
                                    <td className={`${td} text-right tabular-nums`}>{o.piece_count}</td>
                                    <td className={`${td} text-xs`}>{dateTime(o.handed_over_at)}<div className="font-semibold text-rose-600">{ageDays(o.handed_over_at)} days</div></td>
                                    {isWh && <td className={`${td} whitespace-nowrap text-right`}>
                                        <Button variant="ghost" className="!px-2 !py-1 text-xs" onClick={() => act(o.id, 'delivered', `Mark ${o.tracking_id} as delivered?`)}>Delivered</Button>
                                        <Button variant="ghost" className="!px-2 !py-1 text-xs text-rose-700" onClick={() => act(o.id, 'lost', `Courier lost ${o.tracking_id}? Its ${o.piece_count} pcs leave stock (claim from courier).`)}>Lost</Button>
                                    </td>}
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                </Card>
            )}

            {byCourier.length > 1 && (
                <Card className="mt-6" title="By courier (parcels / pcs)">
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Courier</th><th className={`${th} text-right`}>Sent</th><th className={`${th} text-right`}>Delivered</th><th className={`${th} text-right`}>Returned</th><th className={`${th} text-right`}>With courier</th><th className={`${th} text-right`}>Overdue</th><th className={`${th} text-right`}>Lost</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {byCourier.map((c) => (
                                <tr key={c.courier}><td className={`${td} font-medium`}>{couriers[c.courier!] ?? c.courier}</td>
                                    <td className={`${td} text-right tabular-nums`}>{num(c.sent_parcels)}<span className="text-xs text-slate-400"> / {num(c.sent_pieces)}</span></td>
                                    {cell(c, 'delivered')}{cell(c, 'returned')}{cell(c, 'open')}{cell(c, 'overdue', 'font-semibold text-rose-600')}{cell(c, 'lost', 'font-semibold text-rose-800')}</tr>
                            ))}
                        </tbody>
                    </table></div>
                </Card>
            )}

            <Card className="mt-6" title="Handovers (bulk) — parcels / pcs">
                {batches.length === 0 ? <Empty>No bulk handovers in this period. Use “Hand over to courier”.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Handover</th><th className={th}>Courier</th><th className={`${th} text-right`}>Sent</th><th className={`${th} text-right`}>Delivered</th><th className={`${th} text-right`}>Returned</th><th className={`${th} text-right`}>With courier</th><th className={`${th} text-right`}>Overdue</th><th className={`${th} text-right`}>Lost</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {batches.map((b) => (
                                <tr key={b.id} className="cursor-pointer hover:bg-slate-50" onClick={() => router.get(`/courier/batches/${b.id}`)}>
                                    <td className={td}><span className="font-semibold text-brand-700">#{b.id}</span><div className="text-xs text-slate-500">{dateTime(b.created_at)} · {users[b.created_by] ?? ''}</div></td>
                                    <td className={td}>{couriers[b.courier] ?? b.courier}</td>
                                    <td className={`${td} text-right font-semibold tabular-nums`}>{num(b.parcel_count)}<span className="text-xs font-normal text-slate-400"> / {num(b.piece_count)}</span></td>
                                    {cell(b.stats, 'delivered')}{cell(b.stats, 'returned')}{cell(b.stats, 'open')}{cell(b.stats, 'overdue', 'font-semibold text-rose-600')}{cell(b.stats, 'lost', 'font-semibold text-rose-800')}
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                )}
            </Card>

            <Card className="mt-6" title="Return receipts (bulk)">
                {returns.length === 0 ? <Empty>No return receipts in this period.</Empty> : (
                    <table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Receipt</th><th className={th}>Courier</th><th className={`${th} text-right`}>Parcels</th><th className={`${th} text-right`}>Pcs back</th><th className={th}>Note</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {returns.map((r) => (
                                <tr key={r.id}><td className={td}><b>#{r.id}</b><div className="text-xs text-slate-500">{dateTime(r.created_at)} · {users[r.created_by] ?? ''}</div></td>
                                    <td className={td}>{couriers[r.courier] ?? r.courier}</td><td className={`${td} text-right tabular-nums`}>{num(r.parcel_count)}</td>
                                    <td className={`${td} text-right tabular-nums`}>{num(r.piece_count)}</td><td className={`${td} text-xs text-slate-500`}>{r.note}</td></tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
