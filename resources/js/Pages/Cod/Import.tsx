import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SheetMapper, { Column, moneyMinor } from '@/Components/SheetMapper';
import { Button, Card, Field, Input, LinkButton, PageHeader, Select } from '@/Components/ui';
import { num, taka, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';

const COLUMNS: Column[] = [
    { key: 'tracking', label: 'Tracking / consignment ID', guess: /consign|track|cn\b|parcel id|awb/i },
    { key: 'invoice', label: 'Invoice / order no.', guess: /invoice|order|merchant/i },
    { key: 'collected', label: 'Collected amount (COD)', guess: /collect|cod|cash|amount/i, required: true },
    { key: 'charge', label: 'Delivery charge', guess: /deliver.*charge|charge|fee/i },
    { key: 'charge2', label: 'COD / other charge', guess: /cod.*charge|cod fee|return charge/i },
];

export default function CodImport({ couriers }: PageProps<{ couriers: Record<string, string> }>) {
    const [courier, setCourier] = useState('steadfast');
    const [reference, setReference] = useState('');
    const [paidOn, setPaidOn] = useState(new Date().toLocaleDateString('en-CA'));
    const [markDelivered, setMarkDelivered] = useState(true);
    const [busy, setBusy] = useState(false);
    const key = useRef(uuid());

    return (
        <AuthenticatedLayout>
            <Head title="Import courier payment" />
            <PageHeader title="Import courier payment statement" subtitle="Download the payment / invoice statement from the courier panel (Excel or CSV) and upload it here. Each row is matched to your parcel by tracking ID or invoice number."
                actions={<LinkButton variant="secondary" href="/cod">COD & payments</LinkButton>} />
            <Card>
                <div className="grid gap-4 border-b border-slate-100 p-5 sm:grid-cols-4">
                    <Field label="Courier"><Select value={courier} onChange={(e) => setCourier(e.target.value)}>{Object.entries(couriers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                    <Field label="Payment / invoice reference"><Input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="e.g. INV-2026-10-24" /></Field>
                    <Field label="Paid on"><Input type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} /></Field>
                    <label className="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" checked={markDelivered} onChange={(e) => setMarkDelivered(e.target.checked)} className="rounded border-slate-300 text-brand-600" /> Mark these parcels delivered</label>
                </div>
                <div className="p-5">
                    <SheetMapper columns={COLUMNS} hint="Needs the collected amount and either the tracking ID or the invoice (order) number. Charges are optional."
                        template={{ name: 'courier-payment-template.csv', csv: 'tracking_id,invoice,collected,delivery_charge,cod_charge\nSF123456,1001,1250,60,12\n' }}
                        onRows={(rows) => {
                            const lines = rows.map((r) => ({ tracking: r.values.tracking || null, invoice: r.values.invoice || null,
                                collected: Math.max(0, moneyMinor(r.values.collected)), charge: Math.max(0, moneyMinor(r.values.charge) + moneyMinor(r.values.charge2)) }))
                                .filter((l) => l.tracking || l.invoice);
                            const collected = lines.reduce((a, l) => a + l.collected, 0), charge = lines.reduce((a, l) => a + l.charge, 0);
                            const save = () => {
                                if (!lines.length || busy) return;
                                setBusy(true);
                                router.post('/cod/import', { idempotency_key: key.current, courier, reference: reference || null, paid_on: paidOn, mark_delivered: markDelivered, lines },
                                    { onFinish: () => setBusy(false), onError: () => { key.current = uuid(); } });
                            };
                            return (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                                    <span><b>{num(lines.length)}</b> rows · collected <b>{taka(collected)}</b> · charges <b>{taka(charge)}</b> · net <b className="text-emerald-700">{taka(collected - charge)}</b></span>
                                    <Button onClick={save} disabled={!lines.length || busy}>{busy ? 'Saving…' : `Import ${num(lines.length)} rows`}</Button>
                                </div>
                            );
                        }} />
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
