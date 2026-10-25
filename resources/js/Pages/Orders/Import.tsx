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

type LinkRow = { id: number; source: string; external_id: string; external_name: string | null; style_id: number | null; style: string | null; color: string | null; auto: boolean; linked: boolean };
type Style = { id: number; name: string; code: string; colors: string[] };

/** One website product → Opryx style + colour. Size is read from each order (variation or the checkout size field). */
function LinkPicker({ l, styles }: { l: LinkRow; styles: Style[] }) {
    const [style, setStyle] = useState(l.style_id ? String(l.style_id) : '');
    const [color, setColor] = useState(l.color ?? '');
    const [edit, setEdit] = useState(!l.linked);
    const st = styles.find((x) => String(x.id) === style);
    if (!edit) return <span className="text-sm">{l.style}{l.color ? ` · ${l.color}` : ''} {l.auto && <span className="text-[11px] text-slate-400">(auto)</span>}
        <button className="ml-2 text-xs text-brand-700" onClick={() => setEdit(true)}>Change</button></span>;
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Select className="!w-56" value={style} onChange={(e) => { setStyle(e.target.value); setColor(''); }}><option value="">Choose Opryx product…</option>
                {styles.map((x) => <option key={x.id} value={x.id}>{x.name} ({x.code})</option>)}</Select>
            {st && st.colors.length > 1 && <Select className="!w-40" value={color} onChange={(e) => setColor(e.target.value)}><option value="">Colour…</option>{st.colors.map((c) => <option key={c}>{c}</option>)}</Select>}
            <Button disabled={!style || (!!st && st.colors.length > 1 && !color)} onClick={() => router.post(`/orders/import/links/${l.id}`, { product_style_id: Number(style), color: color || null }, { preserveScroll: true })}>Save link</Button>
        </div>
    );
}

type Hook = { id: number; source: string; status: number | null; created_at: string; woo_order?: string | number | null; woo_status?: string; topic?: string | null; result?: string };

export default function OrderImport({ inbox, failed, flash, hooks = [], links = [], styles = [] }: PageProps<{ inbox: Inbox[]; failed: number; hooks: Hook[]; links: LinkRow[]; styles: Style[] }>) {
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
            <Card className="mt-6" title={`Website products ↔ Opryx${links.some((l) => !l.linked) ? ` — ${links.filter((l) => !l.linked).length} need a link` : ''}`}
                actions={<span className="text-xs text-slate-500">Link once; the size is taken from each order</span>}>
                {links.length === 0 ? <Empty>Website products without an Opryx SKU appear here the first time they are ordered.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Website product</th><th className={th}>Opryx product · colour</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">{links.map((l) => (
                            <tr key={l.id} className={l.linked ? '' : 'bg-amber-50/60'}><td className={td}>{l.external_name}<div className="text-[11px] text-slate-400 capitalize">{l.source} #{l.external_id}</div></td>
                                <td className={td}><LinkPicker l={l} styles={styles} /></td></tr>))}</tbody>
                    </table></div>
                )}
            </Card>
            <Card className="mt-6" title="Website connection log" actions={<span className="text-xs text-slate-500">Every call your online store makes to Opryx</span>}>
                {hooks.length === 0 ? <Empty>Nothing received from your website yet. Check that the webhook in WooCommerce is Active and its URL / secret match Settings → Online store.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>When</th><th className={th}>Store order</th><th className={th}>Store status</th><th className={th}>What Opryx did</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">{hooks.map((h) => (
                            <tr key={h.id}><td className={`${td} whitespace-nowrap text-xs`}>{dateTime(h.created_at)}</td>
                                <td className={td}>{h.woo_order ? `${h.source === 'woocommerce' ? 'WC' : ''}#${h.woo_order}` : '—'}<div className="text-[11px] text-slate-400">{h.topic}</div></td>
                                <td className={`${td} text-xs`}>{h.woo_status ?? '—'}</td>
                                <td className={`${td} text-xs ${h.status && h.status >= 400 ? 'text-rose-600' : h.result?.startsWith('imported') ? 'text-emerald-700' : h.result?.startsWith('failed') ? 'text-rose-600' : 'text-slate-600'}`}>{h.result}</td></tr>))}</tbody>
                    </table></div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
