import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, td, th } from '@/Components/ui';
import { dateTime, num, taka } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, ReactNode, useState } from 'react';

type Props = { tab: string; filters: { from: string; to: string; dead: number }; data: any; couriers: Record<string, string> };
const TABS: [string, string][] = [['sales', 'Sales'], ['profit', 'Profit'], ['products', 'Products & sizes'], ['couriers', 'Couriers'], ['stock', 'Stock value']];

function Tile({ label, value, sub, tone = 'text-slate-900' }: { label: string; value: ReactNode; sub?: ReactNode; tone?: string }) {
    return <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><div className="text-xs font-medium text-slate-500">{label}</div>
        <div className={`mt-1 text-xl font-semibold tabular-nums ${tone}`}>{value}</div>{sub && <div className="text-xs text-slate-500">{sub}</div>}</div>;
}

/** Simple bar chart (no library): one bar per day. */
function Bars({ rows }: { rows: { day: string; value: number; orders: number }[] }) {
    if (!rows.length) return <Empty>No orders in this period.</Empty>;
    const max = Math.max(...rows.map((r) => r.value), 1);
    return (
        <div className="flex h-48 items-end gap-[2px] px-5 pb-6 pt-4">
            {rows.map((r) => (
                <div key={r.day} className="group relative flex h-full flex-1 flex-col justify-end" title={`${r.day}: ${taka(r.value)} · ${r.orders} orders`}>
                    <div className="rounded-t bg-brand-500 transition group-hover:bg-brand-700" style={{ height: `${Math.max(2, (100 * r.value) / max)}%` }} />
                    {rows.length <= 31 && <div className="absolute -bottom-5 left-1/2 -translate-x-1/2 text-[9px] text-slate-400">{r.day.slice(8, 10)}</div>}
                </div>
            ))}
        </div>
    );
}

const Pct = ({ v, bad = 20 }: { v: number | null; bad?: number }) => v === null ? <span className="text-slate-300">—</span> : <span className={v >= bad ? 'font-semibold text-rose-600' : ''}>{v}%</span>;

export default function Reports({ tab, filters, data, couriers }: PageProps<Props>) {
    const [f, setF] = useState({ from: filters.from, to: filters.to, dead: String(filters.dead) });
    const go = (t: string, extra: Record<string, string> = {}) => router.get('/reports', { tab: t, ...f, ...extra }, { preserveState: true });
    const apply = (e: FormEvent) => { e.preventDefault(); go(tab); };
    const csv = `/reports?${new URLSearchParams({ tab, from: f.from, to: f.to, dead: f.dead, export: 'csv' })}`;

    return (
        <AuthenticatedLayout>
            <Head title="Reports" />
            <PageHeader title="Reports" subtitle="Sales, profit, best and worst products, courier performance and stock value." actions={<a href={csv} className="inline-flex items-center gap-2 rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50">Download Excel (CSV)</a>} />
            <div className="mb-4 inline-flex flex-wrap rounded-lg bg-slate-100 p-1 text-sm">
                {TABS.map(([k, l]) => <button key={k} onClick={() => go(k)} className={`rounded-md px-3 py-1.5 ${tab === k ? 'bg-white font-semibold shadow-sm' : 'text-slate-600'}`}>{l}</button>)}
            </div>
            <form onSubmit={apply} className="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                {tab !== 'stock' ? <>
                    <Field label="From"><Input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                    <Field label="To"><Input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                </> : <Field label="Dead stock = not sold for (days)"><Input type="number" min="7" max="365" value={f.dead} onChange={(e) => setF({ ...f, dead: e.target.value })} className="!w-28" /></Field>}
                <Button variant="secondary">Apply</Button>
            </form>

            {tab === 'sales' && <>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Tile label="Orders" value={num(data.totals.orders)} sub={`${num(data.totals.cancelled)} cancelled`} />
                    <Tile label="Order value" value={taka(data.totals.value)} />
                    <Tile label="Pieces" value={num(data.totals.pieces)} />
                    <Tile label="Average order" value={taka(data.totals.orders ? Math.round(data.totals.value / data.totals.orders) : 0)} />
                </div>
                <Card className="mt-6" title="Order value per day"><Bars rows={data.days} /></Card>
                <Card className="mt-6" title="By source">
                    <table className="min-w-full divide-y divide-slate-100"><thead><tr><th className={th}>Source</th><th className={`${th} text-right`}>Orders</th><th className={`${th} text-right`}>Value</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">{data.bySource.map((s: any) => <tr key={s.source}><td className={`${td} capitalize`}>{s.source}</td><td className={`${td} text-right tabular-nums`}>{num(s.orders)}</td><td className={`${td} text-right tabular-nums`}>{taka(s.value)}</td></tr>)}</tbody></table>
                </Card>
            </>}

            {tab === 'profit' && <>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Tile label="Delivered sales" value={taka(data.revenue)} sub={`${num(data.delivered_orders)} delivered · ${num(data.partial_orders)} partial`} />
                    <Tile label="Gross profit" value={taka(data.gross_profit)} sub={data.margin !== null ? `${data.margin}% margin` : ''} tone={data.gross_profit < 0 ? 'text-rose-600' : 'text-emerald-700'} />
                    <Tile label="Return losses" value={taka(data.damaged_cost + data.lost_cost)} sub="damaged + lost / missing, at cost" tone="text-rose-600" />
                    <Tile label="Profit after losses" value={taka(data.net_after_losses)} tone={data.net_after_losses < 0 ? 'text-rose-600' : 'text-emerald-800'} />
                </div>
                <Card className="mt-6 max-w-2xl" title="Profit statement (parcels delivered in this period)">
                    <dl className="divide-y divide-slate-100 text-sm">
                        {([['Product sales', data.revenue], ['+ Delivery charge collected', data.delivery_income], ['− Discount', -data.discount], ['− Cost of goods', -data.cogs],
                            ['− Courier charges', -data.courier_charges], ['= Gross profit', data.gross_profit, true], ['− Damaged returns (cost)', -data.damaged_cost],
                            ['− Lost / missing (cost)', -data.lost_cost], ['= Profit after losses', data.net_after_losses, true]] as [string, number, boolean?][]).map(([l, v, b]) => (
                            <div key={l} className={`flex justify-between px-5 py-2 ${b ? 'bg-slate-50 font-semibold' : ''}`}><dt>{l}</dt><dd className={`tabular-nums ${v < 0 ? 'text-rose-600' : ''}`}>{taka(v)}</dd></div>
                        ))}
                    </dl>
                    <p className="px-5 pb-4 text-xs text-slate-500">Cost of goods uses each product's cost price at the time of the order. Set cost prices on Products to get correct profit.</p>
                </Card>
            </>}

            {tab === 'products' && <>
                <div className="grid gap-6 lg:grid-cols-2">
                    {(['sizes', 'colors'] as const).map((k) => (
                        <Card key={k} title={k === 'sizes' ? 'By size' : 'By colour'}>
                            <table className="min-w-full divide-y divide-slate-100"><thead><tr><th className={th}>{k === 'sizes' ? 'Size' : 'Colour'}</th><th className={`${th} text-right`}>Sold</th><th className={`${th} text-right`}>Returned</th><th className={`${th} text-right`}>Return %</th></tr></thead>
                                <tbody className="divide-y divide-slate-100">{data[k].map((r: any) => <tr key={r.size ?? r.color}><td className={td}>{r.size ?? r.color}</td><td className={`${td} text-right tabular-nums`}>{num(r.qty)}</td><td className={`${td} text-right tabular-nums`}>{num(r.returned)}</td><td className={`${td} text-right`}><Pct v={r.qty ? Math.round(1000 * r.returned / r.qty) / 10 : null} /></td></tr>)}</tbody></table>
                        </Card>
                    ))}
                </div>
                <Card className="mt-6" title="Every colour / size">
                    {data.rows.length === 0 ? <Empty>No sales in this period.</Empty> : (
                        <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Product</th><th className={`${th} text-right`}>Sold</th><th className={`${th} text-right`}>Returned</th><th className={`${th} text-right`}>Return %</th><th className={`${th} text-right`}>Revenue</th><th className={`${th} text-right`}>Profit</th></tr></thead>
                            <tbody className="divide-y divide-slate-100">{data.rows.map((p: any) => (
                                <tr key={p.id}><td className={td}>{p.name}<div className="text-xs text-slate-500">{[p.color, p.size].filter(Boolean).join(' / ')} · {p.sku}</div></td>
                                    <td className={`${td} text-right font-semibold tabular-nums`}>{num(p.qty)}</td><td className={`${td} text-right tabular-nums`}>{num(p.returned)}</td>
                                    <td className={`${td} text-right`}><Pct v={p.return_rate} /></td><td className={`${td} text-right tabular-nums`}>{taka(p.revenue)}</td>
                                    <td className={`${td} text-right tabular-nums ${p.profit < 0 ? 'text-rose-600' : ''}`}>{taka(p.profit)}</td></tr>))}</tbody>
                        </table></div>
                    )}
                </Card>
            </>}

            {tab === 'couriers' && (
                <Card title="Courier performance (parcels handed over in this period)">
                    {data.rows.length === 0 ? <Empty>No parcels in this period.</Empty> : (
                        <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Courier</th><th className={`${th} text-right`}>Parcels</th><th className={`${th} text-right`}>Delivered</th><th className={`${th} text-right`}>Returned</th><th className={`${th} text-right`}>Lost</th><th className={`${th} text-right`}>With courier</th><th className={`${th} text-right`}>Success</th><th className={`${th} text-right`}>Avg days</th><th className={`${th} text-right`}>Charges</th></tr></thead>
                            <tbody className="divide-y divide-slate-100">{data.rows.map((c: any) => (
                                <tr key={c.courier}><td className={`${td} font-medium`}>{couriers[c.courier] ?? c.courier}</td><td className={`${td} text-right tabular-nums`}>{num(c.sent)}</td>
                                    <td className={`${td} text-right tabular-nums`}>{num(c.delivered)}</td><td className={`${td} text-right tabular-nums`}>{num(c.returned)}</td><td className={`${td} text-right tabular-nums`}>{num(c.lost)}</td>
                                    <td className={`${td} text-right tabular-nums`}>{num(c.open)}</td><td className={`${td} text-right font-semibold`}>{c.success === null ? '—' : `${c.success}%`}</td>
                                    <td className={`${td} text-right tabular-nums`}>{c.avg_days ?? '—'}</td><td className={`${td} text-right tabular-nums`}>{taka(c.charges)}</td></tr>))}</tbody>
                        </table></div>
                    )}
                </Card>
            )}

            {tab === 'stock' && <>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Tile label="Pieces in stock" value={num(data.totals.pieces)} sub={`${num(data.totals.transit)} with courier · ${num(data.totals.damaged)} damaged`} />
                    <Tile label="Stock value at cost" value={taka(data.totals.cost_value)} />
                    <Tile label="Stock value at sale price" value={taka(data.totals.sale_value)} />
                    <Tile label={`Dead stock (> ${data.dead_days} days)`} value={taka(data.totals.dead_cost)} sub={`${num(data.totals.dead_items)} colour/size lines`} tone={data.totals.dead_items ? 'text-amber-700' : 'text-slate-400'} />
                </div>
                <Card className="mt-6" title="Stock by colour / size">
                    <div className="max-h-[600px] overflow-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead className="sticky top-0 bg-white"><tr><th className={th}>Product</th><th className={`${th} text-right`}>Available</th><th className={`${th} text-right`}>Reserved</th><th className={`${th} text-right`}>Transit</th><th className={`${th} text-right`}>Cost value</th><th className={`${th} text-right`}>Sale value</th><th className={th}>Last sold</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">{data.rows.map((s: any) => (
                            <tr key={s.id} className={s.dead ? 'bg-amber-50/50' : ''}><td className={td}>{s.name}<div className="text-xs text-slate-500">{[s.color, s.size].filter(Boolean).join(' / ')}</div></td>
                                <td className={`${td} text-right font-semibold tabular-nums`}>{num(s.available)}</td><td className={`${td} text-right tabular-nums`}>{num(s.reserved)}</td><td className={`${td} text-right tabular-nums`}>{num(s.transit)}</td>
                                <td className={`${td} text-right tabular-nums`}>{taka(s.cost_value)}</td><td className={`${td} text-right tabular-nums`}>{taka(s.sale_value)}</td>
                                <td className={`${td} text-xs`}>{s.last_sold ? dateTime(s.last_sold) : '—'}{s.dead && <div className="font-semibold text-amber-700">dead stock</div>}</td></tr>))}</tbody>
                    </table></div>
                </Card>
            </>}
        </AuthenticatedLayout>
    );
}
