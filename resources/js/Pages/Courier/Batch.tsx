import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { StatTiles, Stats, ageDays } from '@/Components/CourierStats';
import { Button, Card, LinkButton, PageHeader, StatusBadge, td, th } from '@/Components/ui';
import { dateTime, num } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Parcel = { id: number; order_id: number; tracking_id: string; status: string; pieces: number; customer: string; phone: string; address: string; items: string;
    handed_over_at: string; delivered_at: string | null; returned_at: string | null; lost_at: string | null; courier_status?: string | null; alt_tracking?: string | null };
type Props = { batch: { id: number; courier: string; parcel_count: number; piece_count: number; note: string | null; created_at: string; by: string | null };
    courierName: string; days: number; stats: Stats; parcels: Parcel[] };

/** One handover: printable manifest for the courier's signature + live status of every parcel in it. */
export default function Batch({ batch, courierName, days, stats, parcels, role, tenant }: PageProps<Props>) {
    const isWh = ['owner', 'manager', 'warehouse'].includes(role ?? '');
    const [filter, setFilter] = useState<'all' | 'open' | 'overdue'>('all');
    const open = (p: Parcel) => p.status === 'shipped';
    const overdue = (p: Parcel) => open(p) && ageDays(p.handed_over_at) >= days;
    const shown = parcels.filter((p) => filter === 'all' || (filter === 'open' ? open(p) : overdue(p)));
    const act = (id: number, what: 'lost' | 'delivered', text: string) => { if (window.confirm(text)) router.post(`/courier/parcels/${id}/${what}`, {}, { preserveScroll: true }); };

    return (
        <AuthenticatedLayout>
            <Head title={`Handover #${batch.id}`} />
            <style>{`@media print { @page { margin: 10mm; } body { background: #fff !important; } }`}</style>
            <div className="mb-2 print:hidden"><Link href="/courier" className="text-sm text-slate-500 hover:text-slate-700">← Courier hub</Link></div>
            <div className="print:hidden">
                <PageHeader title={`Handover #${batch.id} · ${courierName}`} subtitle={`${dateTime(batch.created_at)}${batch.by ? ` · by ${batch.by}` : ''}${batch.note ? ` · ${batch.note}` : ''}`}
                    actions={<><LinkButton variant="secondary" href="/courier/dispatch">New handover</LinkButton><Button onClick={() => window.print()}>Print manifest</Button></>} />
                <StatTiles s={stats} days={days} />
                <div className="mt-6 mb-3 inline-flex rounded-lg bg-slate-100 p-1 text-sm">
                    {([['all', `All (${parcels.length})`], ['open', `With courier (${stats.open_parcels})`], ['overdue', `Overdue (${stats.overdue_parcels})`]] as const).map(([k, l]) => (
                        <button key={k} onClick={() => setFilter(k)} className={`rounded-md px-3 py-1.5 ${filter === k ? 'bg-white font-medium shadow-sm' : 'text-slate-600'}`}>{l}</button>
                    ))}
                </div>
            </div>

            {/* Printed manifest header */}
            <div className="mb-4 hidden print:block">
                <div className="flex items-start justify-between">
                    <div><div className="text-lg font-bold">{tenant?.name}</div><div className="text-sm">Courier handover manifest</div></div>
                    <div className="text-right text-sm"><div className="font-semibold">Handover #{batch.id}</div><div>{dateTime(batch.created_at)}</div><div>{courierName}</div></div>
                </div>
                <div className="mt-3 rounded border border-black px-3 py-2 text-base font-bold">Total: {num(batch.parcel_count)} parcels · {num(batch.piece_count)} pieces</div>
            </div>

            <Card className="print:rounded-none">
                <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100 print:text-[9pt]">
                    <thead><tr><th className={th}>#</th><th className={th}>Order</th><th className={th}>Tracking</th><th className={th}>Customer</th><th className={th}>Items</th><th className={`${th} text-right`}>Pcs</th>
                        <th className={`${th} print:hidden`}>Status</th>{isWh && <th className={`${th} print:hidden`} />}</tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {shown.map((p, i) => (
                            <tr key={p.id} className={overdue(p) ? 'bg-rose-50/60 print:bg-transparent' : ''}>
                                <td className={`${td} text-xs text-slate-400`}>{i + 1}</td>
                                <td className={td}><Link href={`/orders/${p.order_id}`} className="font-semibold text-brand-700 print:text-black">#{p.order_id}</Link></td>
                                <td className={`${td} font-mono text-xs`}>{p.tracking_id}{p.alt_tracking && <div className="text-slate-400">{p.alt_tracking}</div>}</td>
                                <td className={`${td} text-xs`}>{p.customer}<div className="text-slate-500">{p.phone}</div></td>
                                <td className={`${td} text-xs`}>{p.items}</td>
                                <td className={`${td} text-right tabular-nums`}>{p.pieces}</td>
                                <td className={`${td} print:hidden`}><StatusBadge status={p.status} />
                                    {p.courier_status && <div className="mt-0.5 text-[11px] text-slate-500">Courier: {p.courier_status.replace(/_/g, ' ')}</div>}
                                    {overdue(p) && <div className="mt-0.5 text-xs font-semibold text-rose-600">{ageDays(p.handed_over_at)} days</div>}</td>
                                {isWh && <td className={`${td} whitespace-nowrap text-right print:hidden`}>{open(p) && <>
                                    <Button variant="ghost" className="!px-2 !py-1 text-xs" onClick={() => act(p.id, 'delivered', `Mark ${p.tracking_id} delivered?`)}>Delivered</Button>
                                    <Button variant="ghost" className="!px-2 !py-1 text-xs text-rose-700" onClick={() => act(p.id, 'lost', `Courier lost ${p.tracking_id}? Its ${p.pieces} pcs leave stock (claim from courier).`)}>Lost</Button>
                                </>}</td>}
                            </tr>
                        ))}
                    </tbody>
                </table></div>
            </Card>

            <div className="mt-12 hidden grid-cols-2 gap-16 text-sm print:grid">
                <div className="border-t border-black pt-1">Handed over by (warehouse)</div>
                <div className="border-t border-black pt-1">Received by ({courierName} rider) — name, phone, signature</div>
            </div>
        </AuthenticatedLayout>
    );
}
