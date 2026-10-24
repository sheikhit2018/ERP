import { taka, dateTime } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useEffect } from 'react';

type Item = { id: number; quantity: number; unit_price_minor: number; product: { sku: string; name: string; color: string | null; size: string | null } };
type Sale = { id: number; status: string; subtotal_minor: number; discount_minor: number; total_minor: number; tendered_minor: number; method: string; customer_name: string | null;
    customer_phone: string | null; created_at: string; items: Item[]; location: { name: string; address: string | null } };

/** 80mm thermal receipt. Opens print automatically. */
export default function Receipt({ sale, cashier, tenant, brand }: PageProps<{ sale: Sale; cashier: string | null }>) {
    useEffect(() => { const t = setTimeout(() => window.print(), 300); return () => clearTimeout(t); }, []);
    return (
        <div className="mx-auto w-[300px] bg-white p-3 font-mono text-[12px] text-black">
            <Head title={`Receipt #${sale.id}`} />
            <style>{`@media print { @page { size: 80mm auto; margin: 3mm; } .noprint { display: none } }`}</style>
            <div className="text-center">{brand?.logo && <img src={brand.logo} alt="" className="mx-auto mb-1 h-12 w-auto grayscale" />}<div className="text-base font-bold">{tenant?.name}</div>{brand?.phone && <div>{brand.phone}</div>}<div>{sale.location.name}</div>{sale.location.address && <div>{sale.location.address}</div>}</div>
            <div className="my-2 border-t border-dashed border-black" />
            <div className="flex justify-between"><span>Receipt #{sale.id}</span><span>{dateTime(sale.created_at)}</span></div>
            {sale.customer_name && <div>Customer: {sale.customer_name} {sale.customer_phone}</div>}
            {sale.status === 'void' && <div className="my-1 text-center font-bold">*** VOID ***</div>}
            <div className="my-2 border-t border-dashed border-black" />
            {sale.items.map((i) => (
                <div key={i.id} className="mb-1"><div>{[i.product.name, i.product.color, i.product.size].filter(Boolean).join(' ')}</div>
                    <div className="flex justify-between"><span>{i.quantity} × {taka(i.unit_price_minor)}</span><span>{taka(i.quantity * i.unit_price_minor)}</span></div></div>))}
            <div className="my-2 border-t border-dashed border-black" />
            <div className="flex justify-between"><span>Subtotal</span><span>{taka(sale.subtotal_minor)}</span></div>
            {sale.discount_minor > 0 && <div className="flex justify-between"><span>Discount</span><span>−{taka(sale.discount_minor)}</span></div>}
            <div className="flex justify-between text-sm font-bold"><span>TOTAL</span><span>{taka(sale.total_minor)}</span></div>
            <div className="flex justify-between"><span>Paid ({sale.method})</span><span>{taka(sale.tendered_minor || sale.total_minor)}</span></div>
            {sale.tendered_minor > sale.total_minor && <div className="flex justify-between"><span>Change</span><span>{taka(sale.tendered_minor - sale.total_minor)}</span></div>}
            <div className="my-2 border-t border-dashed border-black" />
            <div className="text-center">Served by {cashier}<br />{brand?.invoice_note ?? 'Thank you!'}</div>
            <div className="noprint mt-4 flex justify-center gap-3 font-sans text-sm"><button onClick={() => window.print()} className="rounded bg-slate-900 px-3 py-1.5 text-white">Print</button><Link href="/pos" className="px-3 py-1.5 text-slate-600">Back to POS</Link></div>
        </div>
    );
}
