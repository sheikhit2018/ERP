import { Button, Card, Input, Select } from '@/Components/ui';
import { dateTime, taka, toMinor } from '@/lib/format';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export type Payment = { id: number; method: string; amount_minor: number; reference: string | null; received_at: string };
const METHODS: [string, string][] = [['bkash', 'bKash'], ['nagad', 'Nagad'], ['rocket', 'Rocket'], ['bank', 'Bank'], ['cash', 'Cash'], ['card', 'Card'], ['other', 'Other']];

/** Advance / prepaid money for the order. Before handover it lowers what the courier must collect. */
export default function OrderPayments({ orderId, total, payments, shipment, canAdd }: {
    orderId: number; total: number; payments: Payment[]; canAdd: boolean;
    shipment: { cod_expected_minor?: number; cod_collected_minor?: number | null; courier_charge_minor?: number | null; settled_at?: string | null } | null;
}) {
    const paid = payments.reduce((a, p) => a + p.amount_minor, 0);
    const f = useForm({ method: 'bkash', amount: '', reference: '' });
    const e = f.errors as Record<string, string>;
    const add = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform((d) => ({ method: d.method, amount_minor: toMinor(d.amount), reference: d.reference.trim() || null }));
        f.post(`/orders/${orderId}/payments`, { preserveScroll: true, onSuccess: () => f.reset() });
    };
    return (
        <Card title="Payment & COD" className="print:hidden">
            <dl className="space-y-1 px-5 pt-4 text-sm">
                <div className="flex justify-between"><dt className="text-slate-500">Order total</dt><dd>{taka(total)}</dd></div>
                <div className="flex justify-between"><dt className="text-slate-500">Advance paid</dt><dd className="text-emerald-700">−{taka(paid)}</dd></div>
                <div className="flex justify-between font-semibold"><dt>{shipment ? 'COD to collect (at handover)' : 'COD to collect'}</dt><dd>{taka(shipment?.cod_expected_minor ?? Math.max(0, total - paid))}</dd></div>
                {shipment?.settled_at && <>
                    <div className="flex justify-between"><dt className="text-slate-500">Courier collected</dt><dd>{taka(shipment.cod_collected_minor)}</dd></div>
                    <div className="flex justify-between"><dt className="text-slate-500">Courier charge</dt><dd>−{taka(shipment.courier_charge_minor)}</dd></div>
                    {(shipment.cod_collected_minor ?? 0) < (shipment.cod_expected_minor ?? 0) && <div className="rounded bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">Short by {taka((shipment.cod_expected_minor ?? 0) - (shipment.cod_collected_minor ?? 0))}</div>}
                </>}
            </dl>
            {payments.length > 0 && (
                <ul className="mx-5 mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200 text-xs">
                    {payments.map((p) => <li key={p.id} className="flex justify-between px-3 py-1.5"><span><b className="capitalize">{p.method}</b> {p.reference && <span className="font-mono">{p.reference}</span>}<span className="ml-1 text-slate-400">{dateTime(p.received_at)}</span></span><span className="font-semibold">{taka(p.amount_minor)}</span></li>)}
                </ul>
            )}
            {canAdd && paid < total && (
                <form onSubmit={add} className="space-y-2 p-5">
                    <div className="text-xs font-medium text-slate-600">Add advance payment {shipment && <span className="text-amber-700">(parcel already with courier — COD amount will not change)</span>}</div>
                    <div className="grid grid-cols-2 gap-2">
                        <Select value={f.data.method} onChange={(x) => f.setData('method', x.target.value)}>{METHODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>
                        <Input type="number" step="0.01" min="0" placeholder="Amount ৳" value={f.data.amount} onChange={(x) => f.setData('amount', x.target.value)} required />
                    </div>
                    <Input placeholder="Transaction ID (optional)" value={f.data.reference} onChange={(x) => f.setData('reference', x.target.value)} />
                    {(e.amount_minor || e.inventory || e.reference) && <p className="text-xs text-rose-600">{e.amount_minor || e.inventory || e.reference}</p>}
                    <Button variant="secondary" className="w-full" disabled={f.processing}>Save payment</Button>
                </form>
            )}
        </Card>
    );
}
