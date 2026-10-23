import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, Empty, PageHeader, StatusBadge, LinkButton, td, th } from '@/Components/ui';
import { dateTime, num, taka } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';

type Props = PageProps<{
    kpi: { orders_today: number; value_today_minor: number; pending: number; confirmed: number; shipped: number; returns_pending: number };
    stock: { available: number; reserved: number; transit: number; damaged: number };
    lowStock: { id: number; sku: string; name: string; available: number; level: number }[];
    rawLow: { id: number; name: string; unit: string; quantity: number }[];
    recent: { id: number; status: string; customer: { name: string; phone: string }; created_at: string }[];
}>;

function Kpi({ label, value, href, tone = 'text-slate-900' }: { label: string; value: string; href?: string; tone?: string }) {
    const body = (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-300">
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
            <div className={`mt-2 text-2xl font-semibold tabular-nums ${tone}`}>{value}</div>
        </div>
    );
    return href ? <Link href={href}>{body}</Link> : body;
}

export default function Dashboard({ kpi, stock, lowStock, rawLow, recent, tenant, role }: Props) {
    const flow = [
        { k: 'Available', v: stock.available, c: 'bg-brand-500' },
        { k: 'Reserved', v: stock.reserved, c: 'bg-sky-500' },
        { k: 'In transit', v: stock.transit, c: 'bg-violet-500' },
        { k: 'Damaged', v: stock.damaged, c: 'bg-rose-400' },
    ];
    const total = flow.reduce((a, b) => a + b.v, 0) || 1;
    const canSell = role !== 'manufacturer' && role !== 'qc';
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <PageHeader title={`Good day, ${tenant?.name ?? ''}`} subtitle="Today's operations at a glance."
                actions={canSell ? <LinkButton href="/orders/create">+ New order</LinkButton> : undefined} />

            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <Kpi label="Orders today" value={num(kpi.orders_today)} href="/orders" />
                <Kpi label="Sales today" value={taka(kpi.value_today_minor)} />
                <Kpi label="Pending" value={num(kpi.pending)} href="/orders?status=pending" tone="text-amber-700" />
                <Kpi label="Ready to ship" value={num(kpi.confirmed)} href="/orders?status=confirmed" tone="text-sky-700" />
                <Kpi label="With courier" value={num(kpi.shipped)} href="/orders?status=shipped" tone="text-violet-700" />
                <Kpi label="Returns to QC" value={num(kpi.returns_pending)} href="/returns" tone="text-orange-700" />
            </div>

            <Card className="mt-6" title="Finished-goods stock position">
                <div className="p-5">
                    <div className="flex h-3 overflow-hidden rounded-full bg-slate-100">
                        {flow.map((f) => <div key={f.k} className={f.c} style={{ width: `${(f.v / total) * 100}%` }} />)}
                    </div>
                    <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {flow.map((f) => (
                            <div key={f.k} className="flex items-center gap-2">
                                <span className={`h-2.5 w-2.5 rounded-full ${f.c}`} />
                                <span className="text-sm text-slate-500">{f.k}</span>
                                <span className="ml-auto text-sm font-semibold tabular-nums">{num(f.v)}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </Card>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2" title="Recent orders" actions={<Link href="/orders" className="text-sm font-medium text-brand-700">View all</Link>}>
                    {recent.length === 0 ? <Empty>No orders yet.</Empty> : (
                        <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>#</th><th className={th}>Customer</th><th className={th}>Status</th><th className={th}>Created</th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {recent.map((o) => (
                                    <tr key={o.id} className="hover:bg-slate-50">
                                        <td className={td}><Link href={`/orders/${o.id}`} className="font-semibold text-brand-700">#{o.id}</Link></td>
                                        <td className={td}>{o.customer?.name}<div className="text-xs text-slate-500">{o.customer?.phone}</div></td>
                                        <td className={td}><StatusBadge status={o.status} /></td>
                                        <td className={`${td} text-slate-500`}>{dateTime(o.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table></div>
                    )}
                </Card>
                <div className="space-y-6">
                    <Card title="Low stock — products">
                        {lowStock.length === 0 ? <Empty>All products above alert level.</Empty> : (
                            <ul className="divide-y divide-slate-100">
                                {lowStock.map((p) => (
                                    <li key={p.id} className="flex items-center justify-between px-5 py-2.5 text-sm">
                                        <span className="truncate">{p.name} <span className="text-xs text-slate-400">{p.sku}</span></span>
                                        <span className={`font-semibold tabular-nums ${p.available === 0 ? 'text-rose-600' : 'text-amber-700'}`}>{num(p.available)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                    {rawLow.length > 0 && (
                        <Card title="Low stock — raw materials">
                            <ul className="divide-y divide-slate-100">
                                {rawLow.map((m) => (
                                    <li key={m.id} className="flex justify-between px-5 py-2.5 text-sm"><span>{m.name}</span><span className="font-semibold text-amber-700">{num(m.quantity)} {m.unit}</span></li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
