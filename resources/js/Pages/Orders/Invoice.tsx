import BarcodeSvg from '@/Components/BarcodeSvg';
import { dateTime, num, taka } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useEffect } from 'react';

type Order = { id: number; status: string; created_at: string; delivery_charge_minor: number; discount_minor: number; note: string | null;
    customer: { name: string; phone: string; address: string };
    items: { id: number; quantity: number; unit_price_minor: number; product: { sku: string; name: string; color: string | null; size: string | null } }[];
    shipment: { courier: string; tracking_id: string } | null; payments: { amount_minor: number; method: string }[] };

/** Printable A5 / A4 invoice with company logo, address and invoice note (Settings → Company). */
export default function Invoice({ order, tenant, brand }: PageProps<{ order: Order }>) {
    useEffect(() => { const t = setTimeout(() => window.print(), 400); return () => clearTimeout(t); }, []);
    const sub = order.items.reduce((a, i) => a + i.quantity * i.unit_price_minor, 0);
    const total = sub + (order.delivery_charge_minor || 0) - (order.discount_minor || 0);
    const paid = order.payments.reduce((a, p) => a + p.amount_minor, 0);
    return (
        <div className="mx-auto max-w-[720px] bg-white p-8 text-[13px] text-slate-900">
            <Head title={`Invoice #${order.id}`} />
            <style>{`@media print { @page { size: A5; margin: 8mm; } .noprint { display: none } body { background: #fff } }`}</style>
            <div className="flex items-start justify-between gap-6 border-b-2 border-slate-900 pb-4">
                <div className="flex items-center gap-3">
                    {brand?.logo && <img src={brand.logo} alt="" className="h-14 w-auto max-w-[140px] object-contain" />}
                    <div><div className="text-xl font-bold">{tenant?.name}</div>
                        <div className="text-xs leading-relaxed text-slate-600">{brand?.address}{brand?.phone && <><br />{brand.phone}</>}{brand?.email && ` · ${brand.email}`}</div></div>
                </div>
                <div className="text-right"><div className="text-lg font-bold uppercase tracking-wide">Invoice</div><div>#{order.id}</div><div className="text-xs text-slate-600">{dateTime(order.created_at)}</div>
                    <div className="ml-auto mt-1 w-36"><BarcodeSvg value={String(order.id)} fmt="CODE128" height={28} /></div></div>
            </div>
            <div className="mt-4 grid grid-cols-2 gap-6">
                <div><div className="text-[11px] font-semibold uppercase text-slate-500">Bill / ship to</div><div className="font-semibold">{order.customer.name}</div><div>{order.customer.phone}</div><div className="text-slate-700">{order.customer.address}</div></div>
                {order.shipment && <div className="text-right"><div className="text-[11px] font-semibold uppercase text-slate-500">Courier</div><div className="capitalize">{order.shipment.courier}</div><div className="font-mono">{order.shipment.tracking_id}</div></div>}
            </div>
            <table className="mt-5 w-full">
                <thead><tr className="border-b border-slate-300 text-left text-[11px] uppercase text-slate-500"><th className="py-1.5">Item</th><th className="py-1.5 text-right">Qty</th><th className="py-1.5 text-right">Price</th><th className="py-1.5 text-right">Amount</th></tr></thead>
                <tbody>{order.items.map((i) => (
                    <tr key={i.id} className="border-b border-slate-100"><td className="py-1.5">{[i.product.name, i.product.color, i.product.size].filter(Boolean).join(' · ')}<div className="text-[11px] text-slate-500">{i.product.sku}</div></td>
                        <td className="py-1.5 text-right">{num(i.quantity)}</td><td className="py-1.5 text-right">{taka(i.unit_price_minor)}</td><td className="py-1.5 text-right">{taka(i.quantity * i.unit_price_minor)}</td></tr>))}</tbody>
            </table>
            <div className="ml-auto mt-3 w-64 space-y-1">
                <div className="flex justify-between"><span>Subtotal</span><span>{taka(sub)}</span></div>
                {order.delivery_charge_minor > 0 && <div className="flex justify-between"><span>Delivery</span><span>{taka(order.delivery_charge_minor)}</span></div>}
                {order.discount_minor > 0 && <div className="flex justify-between"><span>Discount</span><span>−{taka(order.discount_minor)}</span></div>}
                <div className="flex justify-between border-t border-slate-900 pt-1 text-base font-bold"><span>Total</span><span>{taka(total)}</span></div>
                {paid > 0 && <div className="flex justify-between"><span>Paid in advance</span><span>−{taka(paid)}</span></div>}
                <div className="flex justify-between rounded bg-slate-900 px-2 py-1 font-bold text-white"><span>Collect (COD)</span><span>{taka(Math.max(0, total - paid))}</span></div>
            </div>
            {brand?.invoice_note && <p className="mt-6 border-t border-slate-200 pt-3 text-xs text-slate-600">{brand.invoice_note}</p>}
            <div className="noprint mt-6 flex justify-center gap-3 text-sm"><button onClick={() => window.print()} className="rounded bg-slate-900 px-3 py-1.5 text-white">Print</button><Link href={`/orders/${order.id}`} className="px-3 py-1.5 text-slate-600">Back to order</Link></div>
        </div>
    );
}
