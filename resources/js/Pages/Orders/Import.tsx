import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SheetMapper, { Column, moneyMinor } from '@/Components/SheetMapper';
import { Button, Card, Empty, Field, LinkButton, PageHeader, Select, StatusBadge, td, th } from '@/Components/ui';
import { dateTime, num } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Inbox = { id: number; source: string; external_id: string; reference: string | null; status: string; error: string | null; sales_order_id: number | null; created_at: string };

const COLUMNS: Column[] = [
    { key: 'ref', label: 'Order ref (rows with same ref = one order)', guess: /^(order|ref|invoice|order.?(no|id|#))/i },
    { key: 'name', label: 'Customer name', guess: /name|customer|নাম/i, required: true },
    { key: 'phone', label: 'Phone', guess: /phone|mobile|contact|ফোন|মোবাইল/i, required: true },
    { key: 'address', label: 'Address', guess: /address|ঠিকানা/i, required: true },
    { key: 'code', label: 'Product SKU / barcode', guess: /sku|barcode|code|product/i, required: true },
    { key: 'qty', label: 'Quantity', guess: /qty|quantity|pcs|পরিমাণ/i },
    { key: 'price', label: 'Unit price (empty = product price)', guess: /price|rate|দাম/i },
    { key: 'delivery', label: 'Delivery charge', guess: /delivery|shipping/i },
    { key: 'discount', label: 'Discount', guess: /discount|ছাড়/i },
    { key: 'note', label: 'Note', guess: /note|remark/i },
];

export default function OrderImport({ inbox, failed, flash }: PageProps<{ inbox: Inbox[]; failed: number }>) {
    const [status, setStatus] = useState('confirmed');
    const [busy, setBusy] = useState(false);
    return (
        <AuthenticatedLayout>
            <Head title="Import orders" />
            <PageHeader title="Import orders" subtitle="Create many orders at once from Excel / CSV, and see orders that arrived from your website."
                actions={<LinkButton variant="secondary" href="/orders">All orders</LinkButton>} />
            {flash.report && flash.report.length > 0 && (
                <div className="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900"><b>{flash.report.length} orders were not created:</b>
                    <ul className="mt-1 list-disc pl-5">{flash.report.slice(0, 100).map((r) => <li key={r}>{r}</li>)}</ul></div>
            )}
            <Card className="mb-6" title="From Excel / CSV">
                <div className="space-y-4 p-5">
                    <Field label="Create orders as"><Select value={status} onChange={(e) => setStatus(e.target.value)} className="max-w-sm">
                        <option value="confirmed">Confirmed — ready to pack</option><option value="pending">Pending — call to confirm</option></Select></Field>
                    <SheetMapper columns={COLUMNS} hint="One row per product. For an order with several products, repeat the same order ref on each row. Stock is reserved immediately; an order without enough stock is skipped and listed."
                        template={{ name: 'opryx-orders-template.csv', csv: 'order_ref,name,phone,address,sku,qty,price,delivery_charge,discount,note\nA1,Karim,01711000000,"House 5, Road 2, Mirpur 10, Dhaka",SH-WHITE-L,2,,60,0,\nA1,Karim,01711000000,"House 5, Road 2, Mirpur 10, Dhaka",SH-BLACK-M,1,,,,\nA2,Rahima,01811000000,"Agrabad, Chattogram",SH-WHITE-M,1,550,120,50,call first\n' }}
                        onRows={(rows) => {
                            const data = rows.map((r) => ({ line: r.line, ref: r.values.ref || null, name: r.values.name, phone: r.values.phone, address: r.values.address, code: r.values.code,
                                qty: parseInt(r.values.qty) || 1, price: r.values.price ? moneyMinor(r.values.price) : null, delivery: r.values.delivery ? moneyMinor(r.values.delivery) : null,
                                discount: r.values.discount ? moneyMinor(r.values.discount) : null, note: r.values.note || null })).filter((r) => r.code || r.name);
                            const orders = new Set(data.map((d) => d.ref ?? `#${d.line}`)).size;
                            return (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                                    <span><b>{num(data.length)}</b> rows → <b>{num(orders)}</b> orders</span>
                                    <Button disabled={!data.length || busy} onClick={() => { setBusy(true); router.post('/orders/import', { status, rows: data }, { onFinish: () => setBusy(false), preserveScroll: true }); }}>
                                        {busy ? 'Importing…' : `Create ${num(orders)} orders`}</Button>
                                </div>
                            );
                        }} />
                </div>
            </Card>
            <Card title={`Website orders inbox${failed ? ` — ${failed} need attention` : ''}`}>
                {inbox.length === 0 ? <Empty>No website orders yet. Connect WooCommerce or Shopify in Settings → Online store.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Received</th><th className={th}>From</th><th className={th}>Store ref</th><th className={th}>Result</th><th className={th} /></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {inbox.map((i) => (
                                <tr key={i.id} className={i.status === 'failed' ? 'bg-rose-50/50' : ''}>
                                    <td className={`${td} text-xs`}>{dateTime(i.created_at)}</td><td className={`${td} capitalize`}>{i.source}</td><td className={`${td} font-mono text-xs`}>{i.reference ?? i.external_id}</td>
                                    <td className={td}>{i.sales_order_id ? <Link href={`/orders/${i.sales_order_id}`} className="font-semibold text-brand-700">Order #{i.sales_order_id}</Link>
                                        : <span className="text-sm text-rose-700">{i.error}</span>}</td>
                                    <td className={`${td} text-right`}>{i.status === 'failed' && <Button variant="secondary" onClick={() => router.post(`/orders/import/${i.id}/retry`, {}, { preserveScroll: true })}>Retry</Button>}
                                        {i.status === 'imported' && <StatusBadge status="completed" />}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
