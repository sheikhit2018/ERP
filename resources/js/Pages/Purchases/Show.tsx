import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input, PageHeader, StatusBadge, td, th } from '@/Components/ui';
import { num, taka, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef } from 'react';

type Item = { id: number; quantity: number; unit_cost_minor: number; received_quantity: number; product: { sku: string; name: string; color: string | null; size: string | null } | null; material: { sku: string; name: string; base_unit: string } | null };
type PO = { id: number; status: string; order_date: string; expected_date: string | null; note: string | null; supplier: { id: number; name: string }; items: Item[]; receipts: { id: number; received_on: string; value_minor: number; note: string | null }[] };

export default function ShowPurchase({ po }: PageProps<{ po: PO }>) {
    const key = useRef(uuid());
    const open = ['ordered', 'partial'].includes(po.status);
    const f = useForm<{ qty: Record<number, string>; received_on: string; note: string }>({ qty: Object.fromEntries(po.items.map((i) => [i.id, String(i.quantity - i.received_quantity)])), received_on: new Date().toLocaleDateString('en-CA'), note: '' });
    const label = (i: Item) => i.product ? `${i.product.name}${i.product.color || i.product.size ? ` — ${[i.product.color, i.product.size].filter(Boolean).join(' / ')}` : ''}` : `${i.material?.name} (per ${i.material?.base_unit})`;
    const total = po.items.reduce((a, i) => a + i.quantity * i.unit_cost_minor, 0), recv = po.items.reduce((a, i) => a + i.received_quantity * i.unit_cost_minor, 0);
    return (
        <AuthenticatedLayout>
            <Head title={`Purchase #${po.id}`} />
            <div className="mb-2"><Link href="/purchases" className="text-sm text-slate-500">← Purchases</Link></div>
            <PageHeader title={`Purchase #${po.id} · ${po.supplier.name}`} subtitle={`Ordered ${String(po.order_date).slice(0, 10)} · received ${taka(recv)} of ${taka(total)}${po.note ? ` · ${po.note}` : ''}`}
                actions={<><StatusBadge status={po.status} />{po.status === 'ordered' && <Button variant="secondary" onClick={() => window.confirm('Cancel this purchase?') && router.post(`/purchases/${po.id}/cancel`)}>Cancel</Button>}</>} />
            <Card title={open ? 'Receive goods (enter what actually arrived)' : 'Items'}>
                <form onSubmit={(e) => { e.preventDefault(); f.transform((d) => ({ idempotency_key: key.current, received_on: d.received_on, note: d.note || null, lines: po.items.map((i) => ({ item_id: i.id, quantity: parseInt(d.qty[i.id]) || 0 })) }));
                    f.post(`/purchases/${po.id}/receive`, { preserveScroll: true, onSuccess: () => { key.current = uuid(); } }); }}>
                    <table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Item</th><th className={`${th} text-right`}>Ordered</th><th className={`${th} text-right`}>Received</th><th className={`${th} text-right`}>Unit cost</th>{open && <th className={`${th} text-right`}>Receiving now</th>}</tr></thead>
                        <tbody className="divide-y divide-slate-100">{po.items.map((i) => (
                            <tr key={i.id}><td className={td}>{label(i)}<div className="text-xs text-slate-500">{i.product?.sku ?? i.material?.sku}</div></td>
                                <td className={`${td} text-right tabular-nums`}>{num(i.quantity)}</td><td className={`${td} text-right tabular-nums ${i.received_quantity >= i.quantity ? 'text-emerald-700' : ''}`}>{num(i.received_quantity)}</td>
                                <td className={`${td} text-right tabular-nums`}>{taka(i.unit_cost_minor)}</td>
                                {open && <td className={`${td} text-right`}><Input type="number" min="0" max={i.quantity - i.received_quantity} value={f.data.qty[i.id] ?? ''} className="!w-28 text-right"
                                    onChange={(x) => f.setData('qty', { ...f.data.qty, [i.id]: x.target.value })} disabled={i.received_quantity >= i.quantity} /></td>}</tr>))}</tbody>
                    </table>
                    {open && <div className="flex flex-wrap items-end gap-3 border-t border-slate-100 p-5">
                        <Field label="Received on"><Input type="date" value={f.data.received_on} onChange={(x) => f.setData('received_on', x.target.value)} /></Field>
                        <Field label="Challan / note"><Input value={f.data.note} onChange={(x) => f.setData('note', x.target.value)} /></Field>
                        <Button disabled={f.processing}>Receive & add to stock</Button>
                    </div>}
                </form>
            </Card>
            {po.receipts.length > 0 && <Card className="mt-6" title="Goods received">
                <table className="min-w-full divide-y divide-slate-100"><tbody className="divide-y divide-slate-100">{po.receipts.map((r) => <tr key={r.id}><td className={td}>{String(r.received_on).slice(0, 10)}</td><td className={td}>{r.note}</td><td className={`${td} text-right tabular-nums`}>{taka(r.value_minor)}</td></tr>)}</tbody></table>
            </Card>}
        </AuthenticatedLayout>
    );
}
