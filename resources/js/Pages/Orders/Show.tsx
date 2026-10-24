import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input, LinkButton, PageHeader, Select, StatusBadge, td, th } from '@/Components/ui';
import { STATUS_LABEL, dateTime, num, taka } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import ScanChecklist, { useScanChecklist } from '@/Components/ScanChecklist';
import BarcodeSvg from '@/Components/BarcodeSvg';

type Order = {
    id: number; status: string; source: string; note: string | null; created_at: string; delivery_charge_minor: number; discount_minor: number;
    customer: { name: string; phone: string; address: string };
    items: { id: number; quantity: number; unit_price_minor: number; product: { id: number; sku: string; name: string; label: string; barcode: string | null } }[];
    shipment: { courier: string; tracking_id: string; status: string; handed_over_at: string; courier_return: { id: number; status: string } | null } | null;
};

const COURIERS = [['steadfast', 'Steadfast'], ['pathao', 'Pathao'], ['redx', 'RedX'], ['paperfly', 'Paperfly'], ['ecourier', 'eCourier'], ['sundarban', 'Sundarban'], ['self', 'Own delivery']];

export default function ShowOrder({ order, logs, role }: PageProps<{ order: Order; logs: { id: number; event: string; created_at: string }[] }>) {
    const sub = order.items.reduce((a, i) => a + i.quantity * i.unit_price_minor, 0);
    const total = sub + (order.delivery_charge_minor || 0) - (order.discount_minor || 0);
    const ship = useForm({ courier: 'steadfast', tracking_id: '' });
    const isSales = ['owner', 'manager', 'sales'].includes(role ?? '');
    const isWh = ['owner', 'manager', 'warehouse'].includes(role ?? '');
    const act = (path: string, confirmText?: string) => { if (!confirmText || window.confirm(confirmText)) router.post(`/orders/${order.id}/${path}`, {}, { preserveScroll: true }); };
    const checkItems = order.items.map((i) => ({ product_id: i.product.id, label: i.product.label ?? i.product.name, sku: i.product.sku, barcode: i.product.barcode, quantity: i.quantity }));
    const check = useScanChecklist(checkItems);
    const handover = (e: FormEvent) => {
        e.preventDefault(); if (order.status !== 'packed' && !check.done) return;
        ship.transform((d) => (order.status === 'packed' ? d : { ...d, scans: check.scans }));
        ship.post(`/orders/${order.id}/handover`, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title={`Order #${order.id}`} />
            <div className="mb-2 print:hidden"><Link href="/orders" className="text-sm text-slate-500 hover:text-slate-700">← All orders</Link></div>
            <PageHeader title={`Order #${order.id}`} subtitle={`${dateTime(order.created_at)} · source: ${order.source}`}
                actions={<>
                    <StatusBadge status={order.status} />
                    <Button variant="secondary" className="print:hidden" onClick={() => window.print()}>Print invoice</Button>
                    <div className="w-40" title="Scan at the packing station"><BarcodeSvg value={String(order.id)} fmt="CODE128" height={34} /></div>
                </>} />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card title="Items">
                        <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Product</th><th className={`${th} text-right`}>Qty</th><th className={`${th} text-right`}>Price</th><th className={`${th} text-right`}>Amount</th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {order.items.map((i) => (
                                    <tr key={i.id}><td className={td}>{i.product.label ?? i.product.name}<div className="text-xs text-slate-500">{i.product.sku}</div></td>
                                        <td className={`${td} text-right tabular-nums`}>{num(i.quantity)}</td><td className={`${td} text-right tabular-nums`}>{taka(i.unit_price_minor)}</td>
                                        <td className={`${td} text-right font-medium tabular-nums`}>{taka(i.quantity * i.unit_price_minor)}</td></tr>
                                ))}
                            </tbody>
                        </table></div>
                        <dl className="space-y-1 border-t border-slate-100 px-5 py-4 text-sm">
                            <div className="flex justify-between"><dt className="text-slate-500">Subtotal</dt><dd>{taka(sub)}</dd></div>
                            <div className="flex justify-between"><dt className="text-slate-500">Delivery</dt><dd>{taka(order.delivery_charge_minor)}</dd></div>
                            <div className="flex justify-between"><dt className="text-slate-500">Discount</dt><dd>−{taka(order.discount_minor)}</dd></div>
                            <div className="flex justify-between text-base font-semibold"><dt>Total to collect</dt><dd>{taka(total)}</dd></div>
                        </dl>
                    </Card>

                    {order.shipment && (
                        <Card title="Courier & timeline">
                            <div className="p-5 text-sm">
                                <p><b className="capitalize">{order.shipment.courier}</b> · tracking <span className="font-mono">{order.shipment.tracking_id}</span> · handed over {dateTime(order.shipment.handed_over_at)}</p>
                                <ol className="mt-4 space-y-2 border-l-2 border-slate-100 pl-4">
                                    {logs.map((l) => <li key={l.id}><span className="font-medium">{STATUS_LABEL[l.event] ?? l.event.replace(/_/g, ' ')}</span> <span className="text-slate-400">· {dateTime(l.created_at)}</span></li>)}
                                </ol>
                            </div>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card title="Customer">
                        <div className="space-y-1 p-5 text-sm">
                            <div className="font-semibold text-slate-900">{order.customer.name}</div>
                            <a href={`tel:${order.customer.phone}`} className="block text-brand-700">{order.customer.phone}</a>
                            <div className="text-slate-600">{order.customer.address}</div>
                            {order.note && <div className="mt-3 rounded-lg bg-amber-50 p-2 text-amber-900">{order.note}</div>}
                        </div>
                    </Card>

                    <Card title="Next step" className="print:hidden">
                        <div className="space-y-3 p-5">
                            {order.status === 'pending' && isSales && <Button className="w-full" onClick={() => act('confirm')}>Confirm order</Button>}
                            {order.status === 'confirmed' && isWh && <LinkButton href="/courier/pack">Pack at the packing station</LinkButton>}
                            {order.status === 'packed' && isWh && (
                                <form onSubmit={handover} className="space-y-3">
                                    <p className="rounded-lg bg-indigo-50 px-3 py-2 text-sm text-indigo-900">Packed and scan-verified. Hand it to the courier here, or in bulk on <Link href="/courier/dispatch" className="font-semibold underline">Hand over to courier</Link>.</p>
                                    {(ship.errors as any).inventory && <p className="text-sm text-rose-600">{(ship.errors as any).inventory}</p>}
                                    <Field label="Courier"><Select value={ship.data.courier} onChange={(x) => ship.setData('courier', x.target.value)}>
                                        {COURIERS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
                                    <Field label="Tracking / consignment ID" error={(ship.errors as any).tracking_id}>
                                        <Input value={ship.data.tracking_id} onChange={(x) => ship.setData('tracking_id', x.target.value)} required placeholder="Scan or type" /></Field>
                                    <Button className="w-full" disabled={ship.processing}>Hand over to courier (stock out)</Button>
                                </form>
                            )}
                            {order.status === 'confirmed' && isWh && (
                                <form onSubmit={handover} className="space-y-3" onKeyDown={(e) => { if (e.key === 'Enter' && (e.target as HTMLElement).tagName === 'INPUT') e.preventDefault(); }}>
                                    <div className="pt-2 text-sm font-medium text-slate-700">Or pack & hand over here — 1. scan every item into the parcel</div>
                                    <ScanChecklist items={checkItems} state={check} />
                                    {((ship.errors as any).scans || (ship.errors as any).inventory) && <p className="text-sm text-rose-600">{(ship.errors as any).scans || (ship.errors as any).inventory}</p>}
                                    <div className="pt-2 text-sm font-medium text-slate-700">2. Courier</div>
                                    <Field label="Courier"><Select value={ship.data.courier} onChange={(x) => ship.setData('courier', x.target.value)}>
                                        {COURIERS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
                                    <Field label="Tracking / consignment ID" error={(ship.errors as any).tracking_id}>
                                        <Input value={ship.data.tracking_id} onChange={(x) => ship.setData('tracking_id', x.target.value)} required placeholder="Scan or type" /></Field>
                                    <Button className="w-full" disabled={ship.processing || !check.done}>{check.done ? 'Hand over to courier (stock out)' : `Scan items first (${check.count}/${check.total})`}</Button>
                                    <p className="text-xs text-slate-500">Use this when the parcel physically leaves your warehouse. Stock moves from reserved → transit.</p>
                                </form>
                            )}
                            {order.status === 'shipped' && isWh && <Button className="w-full" onClick={() => act('delivered', 'Mark this order as delivered?')}>Mark delivered</Button>}
                            {order.status === 'shipped' && <p className="text-xs text-slate-500">If the parcel comes back, scan it on <Link href="/returns" className="text-brand-700">Returns & QC</Link>.</p>}
                            {['pending', 'confirmed', 'packed'].includes(order.status) && isSales && <Button variant="secondary" className="w-full text-rose-700" onClick={() => act('cancel', 'Cancel this order and release the reserved stock?')}>Cancel order</Button>}
                            {['delivered', 'partial_delivered', 'returned', 'returned_short', 'return_damaged', 'lost', 'cancelled'].includes(order.status) && <p className="text-sm text-slate-500">This order is closed.</p>}
                        </div>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
