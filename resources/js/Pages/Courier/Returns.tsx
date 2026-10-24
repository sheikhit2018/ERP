import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Couriers } from '@/Components/CourierStats';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, StatusBadge, td, th } from '@/Components/ui';
import { beep } from '@/lib/scan';
import { num, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useRef, useState } from 'react';

type Parcel = { shipment_id: number; order_id: number; courier: string; tracking_id: string; status: string; pieces: number; customer: string; items: { label: string; quantity: number }[]; damaged: boolean };

async function lookup(tracking: string, courier: string): Promise<Omit<Parcel, 'damaged'>> {
    const r = await fetch(`/courier/parcel?tracking=${encodeURIComponent(tracking)}&courier=${courier}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const j = await r.json().catch(() => null);
    if (!r.ok) throw new Error(j?.message || `Lookup failed (${r.status})`);
    return j;
}

/** Courier brings back undelivered parcels: scan each tracking label, mark damaged ones, save once → stock in. */
export default function Returns({ couriers, errors }: PageProps<{ couriers: Couriers }>) {
    const [courier, setCourier] = useState('steadfast');
    const [note, setNote] = useState('');
    const [code, setCode] = useState('');
    const [list, setList] = useState<Parcel[]>([]);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const key = useRef(uuid());
    const input = useRef<HTMLInputElement>(null);
    const pieces = list.reduce((a, p) => a + p.pieces, 0), damaged = list.filter((p) => p.damaged);

    const scan = async (e: FormEvent) => {
        e.preventDefault();
        const t = code.trim(); setCode(''); if (!t) return;
        if (list.some((p) => p.tracking_id === t)) { setMsg({ ok: false, text: `${t} is already scanned.` }); beep(false); return; }
        try {
            const p = await lookup(t, courier);
            if (!['shipped', 'lost'].includes(p.status)) throw new Error(`${t} (order #${p.order_id}) is already ${p.status.replace('_', ' ')} — it cannot come back again.`);
            setList((l) => [{ ...p, damaged: false }, ...l]);
            setMsg({ ok: true, text: `#${p.order_id} ${p.customer} · ${p.pieces} pcs${p.status === 'lost' ? ' (was marked lost — found again)' : ''}` }); beep(true);
        } catch (err) { setMsg({ ok: false, text: err instanceof Error ? err.message : 'Lookup failed' }); beep(false); }
        input.current?.focus();
    };
    const save = () => {
        if (!list.length || busy) return;
        if (!window.confirm(`Receive ${list.length} returned parcels (${pieces} pcs)? Good items go back to sellable stock${damaged.length ? `, ${damaged.length} parcels to damaged stock` : ''}.`)) return;
        setBusy(true);
        router.post('/courier/returns', { idempotency_key: key.current, courier, note: note || null, parcels: list.map((p) => ({ tracking_id: p.tracking_id, damaged: p.damaged })) }, {
            onFinish: () => setBusy(false), onError: () => { key.current = uuid(); },
        });
    };
    // The layout already shows errors.inventory; show the rest (e.g. validation of a single parcel line) here.
    const err = Object.entries(errors as Record<string, string>).find(([k]) => k !== 'inventory')?.[1];

    return (
        <AuthenticatedLayout>
            <Head title="Receive returns" />
            <PageHeader title="Receive courier returns" subtitle="Scan every returned parcel's tracking label. Tick “Damaged” for parcels that fail the check, then save — stock comes back in one go."
                actions={<LinkButton variant="secondary" href="/courier">Courier hub</LinkButton>} />
            {err && <p className="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-800">{err}</p>}
            <Card className="mb-6">
                <div className="grid gap-4 p-5 sm:grid-cols-3">
                    <Field label="Courier"><Select value={courier} onChange={(e) => { setCourier(e.target.value); setList([]); }} disabled={list.length > 0}>
                        {Object.entries(couriers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                    <form onSubmit={scan} className="sm:col-span-2"><Field label="Scan tracking ID"><Input ref={input} autoFocus value={code} onChange={(e) => setCode(e.target.value)} placeholder="Scan the returned parcel's courier label" /></Field></form>
                    <div className="sm:col-span-3"><Field label="Note (optional)"><Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Return challan no., rider name…" maxLength={190} /></Field></div>
                </div>
                {msg && <p className={`mx-5 mb-5 rounded-lg px-3 py-2 text-sm ${msg.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}`}>{msg.text}</p>}
            </Card>
            <Card title={`Returned: ${list.length} parcels · ${num(pieces)} pcs${damaged.length ? ` · ${damaged.length} damaged` : ''}`}
                actions={<Button onClick={save} disabled={!list.length || busy}>{busy ? 'Saving…' : `Receive ${list.length} parcels`}</Button>}>
                {list.length === 0 ? <Empty>Scan the first returned parcel.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Order</th><th className={th}>Tracking</th><th className={th}>Items</th><th className={`${th} text-right`}>Pcs</th><th className={th}>Status</th><th className={th}>Damaged?</th><th className={th} /></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {list.map((p) => (
                                <tr key={p.tracking_id} className={p.damaged ? 'bg-rose-50/50' : ''}>
                                    <td className={td}><b>#{p.order_id}</b><div className="text-xs text-slate-500">{p.customer}</div></td>
                                    <td className={`${td} font-mono text-xs`}>{p.tracking_id}</td>
                                    <td className={`${td} text-xs`}>{p.items.map((i) => `${i.quantity} × ${i.label}`).join(', ')}</td>
                                    <td className={`${td} text-right tabular-nums`}>{p.pieces}</td>
                                    <td className={td}><StatusBadge status={p.status} /></td>
                                    <td className={td}><input type="checkbox" checked={p.damaged} className="rounded border-slate-300 text-rose-600"
                                        onChange={(e) => setList((l) => l.map((x) => x.tracking_id === p.tracking_id ? { ...x, damaged: e.target.checked } : x))} /></td>
                                    <td className={`${td} text-right`}><button className="text-xs text-rose-600 hover:underline" onClick={() => setList((l) => l.filter((x) => x.tracking_id !== p.tracking_id))}>Remove</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
