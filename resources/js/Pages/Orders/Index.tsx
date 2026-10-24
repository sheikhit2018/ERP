import { RiskBadge, Risk } from '@/Components/CustomerRisk';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Input, LinkButton, PageHeader, Pagination, StatusBadge, td, th } from '@/Components/ui';
import { STATUS_LABEL, dateTime, taka } from '@/lib/format';
import { PageProps, Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Order = {
    id: number; status: string; source: string; created_at: string; delivery_charge_minor: number; discount_minor: number;
    customer: { name: string; phone: string; address: string }; customer_phone?: string | null;
    items: { quantity: number; unit_price_minor: number }[];
    shipment: { courier: string; tracking_id: string } | null;
};

export default function Orders({ orders, filters, statuses, role, risk }: PageProps<{ orders: Paginated<Order>; filters: { status?: string; q?: string }; statuses: string[]; risk: Record<string, Risk> }>) {
    const [q, setQ] = useState(filters.q ?? '');
    const go = (p: Record<string, string | undefined>) => router.get('/orders', { ...filters, ...p }, { preserveState: true, replace: true });
    const canCreate = role !== 'warehouse';
    return (
        <AuthenticatedLayout>
            <Head title="Orders" />
            <PageHeader title="Orders" subtitle="Every order reserves stock the moment it is saved — no overselling."
                actions={canCreate ? <LinkButton href="/orders/create">+ New order</LinkButton> : undefined} />
            <Card>
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4">
                    <div className="flex flex-wrap gap-1">
                        <button onClick={() => go({ status: undefined })} className={`rounded-full px-3 py-1 text-sm ${!filters.status ? 'bg-ink-900 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>All</button>
                        {statuses.map((s) => (
                            <button key={s} onClick={() => go({ status: s })} className={`rounded-full px-3 py-1 text-sm ${filters.status === s ? 'bg-ink-900 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>{STATUS_LABEL[s]}</button>
                        ))}
                    </div>
                    <form className="ml-auto flex gap-2" onSubmit={(e) => { e.preventDefault(); go({ q }); }}>
                        <Input placeholder="Order #, phone, name, tracking" value={q} onChange={(e) => setQ(e.target.value)} className="w-64" />
                        <Button variant="secondary">Search</Button>
                    </form>
                </div>
                {orders.data.length === 0 ? <Empty>No orders found.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead className="bg-slate-50/60"><tr><th className={th}>Order</th><th className={th}>Customer</th><th className={`${th} text-right`}>Total</th><th className={th}>Status</th><th className={th}>Courier</th><th className={th}>Created</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {orders.data.map((o) => {
                                const total = o.items.reduce((a, i) => a + i.quantity * i.unit_price_minor, 0) + (o.delivery_charge_minor || 0) - (o.discount_minor || 0);
                                return (
                                    <tr key={o.id} className="cursor-pointer hover:bg-slate-50" onClick={() => router.visit(`/orders/${o.id}`)}>
                                        <td className={td}><Link href={`/orders/${o.id}`} className="font-semibold text-brand-700">#{o.id}</Link><div className="text-xs capitalize text-slate-400">{o.source}</div></td>
                                        <td className={td}>{o.customer?.name}<div className="flex items-center gap-1.5 text-xs text-slate-500">{o.customer?.phone} {o.customer_phone && <RiskBadge r={risk[o.customer_phone]} />}</div></td>
                                        <td className={`${td} text-right font-medium tabular-nums`}>{taka(total)}</td>
                                        <td className={td}><StatusBadge status={o.status} /></td>
                                        <td className={`${td} text-xs`}>{o.shipment ? <>{o.shipment.courier}<div className="text-slate-500">{o.shipment.tracking_id}</div></> : <span className="text-slate-300">—</span>}</td>
                                        <td className={`${td} text-slate-500`}>{dateTime(o.created_at)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table></div>
                )}
                <Pagination links={orders.links} />
            </Card>
        </AuthenticatedLayout>
    );
}
