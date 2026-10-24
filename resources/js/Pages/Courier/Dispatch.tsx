import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Couriers } from '@/Components/CourierStats';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, td, th } from '@/Components/ui';
import { beep } from '@/lib/scan';
import { num, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useMemo, useRef, useState } from 'react';

type Ready = { id: number; name: string; phone: string; address: string; pieces: number; created_at: string };
type Parcel = { order_id: number; tracking_id: string };

/**
 * Bulk handover: scan/type the order number, then scan the courier tracking label, repeat.
 * One save = one handover record with the parcel and piece count the courier signs for.
 */
export default function Dispatch({ couriers, orders, errors }: PageProps<{ couriers: Couriers; orders: Ready[] }>) {
    const [courier, setCourier] = useState('steadfast');
    const [note, setNote] = useState('');
    const [list, setList] = useState<Parcel[]>([]);
    const [orderNo, setOrderNo] = useState('');
    const [tracking, setTracking] = useState('');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const key = useRef(uuid());
    const orderRef = useRef<HTMLInputElement>(null), trackRef = useRef<HTMLInputElement>(null);
    const byId = useMemo(() => new Map(orders.map((o) => [o.id, o])), [orders]);
    const inList = new Set(list.map((p) => p.order_id));
    const pieces = list.reduce((a, p) => a + (byId.get(p.order_id)?.pieces ?? 0), 0);
    const say = (ok: boolean, text: string) => { setMsg({ ok, text }); beep(ok); };

    const pickOrder = (raw: string) => {
        const id = parseInt(raw.replace(/\D/g, ''));
        const o = byId.get(id);
        if (!o) return say(false, `Order #${raw} is not ready for handover (not confirmed, or already handed over).`);
        if (inList.has(id)) return say(false, `Order #${id} is already in this handover.`);
        setOrderNo(String(id)); setMsg({ ok: true, text: `#${id} ${o.name} · ${o.pieces} pcs — now scan the courier tracking label.` });
        requestAnimationFrame(() => trackRef.current?.focus());
    };
    const addParcel = (e?: FormEvent) => {
        e?.preventDefault();
        const id = parseInt(orderNo), t = tracking.trim();
        if (!byId.has(id)) { orderRef.current?.focus(); return say(false, 'Scan or type the order number first.'); }
        if (!t) return say(false, 'Scan the courier tracking label.');
        if (list.some((p) => p.tracking_id === t)) return say(false, `Tracking ${t} is already used in this handover.`);
        setList((l) => [{ order_id: id, tracking_id: t }, ...l]);
        say(true, `Added #${id} → ${t}`);
        setOrderNo(''); setTracking(''); requestAnimationFrame(() => orderRef.current?.focus());
    };
    const save = () => {
        if (!list.length || busy) return;
        if (!window.confirm(`Hand over ${list.length} parcels (${pieces} pcs) to ${couriers[courier]}? Stock moves to transit.`)) return;
        setBusy(true);
        router.post('/courier/dispatch', { idempotency_key: key.current, courier, note: note || null, parcels: list }, {
            onFinish: () => setBusy(false),
            onError: () => { key.current = uuid(); },
        });
    };
    const q = search.trim().toLowerCase();
    const shown = orders.filter((o) => !inList.has(o.id) && (!q || String(o.id).includes(q) || o.name.toLowerCase().includes(q) || o.phone.includes(q))).slice(0, 200);
    // The layout already shows errors.inventory; show the rest (e.g. validation of a single parcel line) here.
    const err = Object.entries(errors as Record<string, string>).find(([k]) => k !== 'inventory')?.[1];

    return (
        <AuthenticatedLayout>
            <Head title="Hand over to courier" />
            <PageHeader title="Hand over to courier" subtitle="Scan the order number, then the courier tracking label. Repeat for every parcel, then save once."
                actions={<LinkButton variant="secondary" href="/courier">Courier hub</LinkButton>} />
            {err && <p className="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-800">{err}</p>}

            <div className="grid gap-6 lg:grid-cols-5">
                <div className="space-y-6 lg:col-span-3">
                    <Card>
                        <div className="grid gap-4 p-5 sm:grid-cols-2">
                            <Field label="Courier"><Select value={courier} onChange={(e) => setCourier(e.target.value)}>{Object.entries(couriers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                            <Field label="Note (optional)"><Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Rider name, pickup time…" maxLength={190} /></Field>
                        </div>
                        <form onSubmit={addParcel} className="grid gap-3 border-t border-slate-100 p-5 sm:grid-cols-[1fr_1.4fr_auto] sm:items-end">
                            <Field label="1. Order #"><Input ref={orderRef} autoFocus value={orderNo} inputMode="numeric" placeholder="Scan / type order no."
                                onChange={(e) => setOrderNo(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); pickOrder(orderNo); } }} /></Field>
                            <Field label="2. Courier tracking ID"><Input ref={trackRef} value={tracking} placeholder="Scan the courier label" onChange={(e) => setTracking(e.target.value)} /></Field>
                            <Button>Add parcel</Button>
                        </form>
                        {msg && <p className={`mx-5 mb-5 rounded-lg px-3 py-2 text-sm ${msg.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}`}>{msg.text}</p>}
                    </Card>

                    <Card title={`This handover: ${list.length} parcels · ${num(pieces)} pcs`}
                        actions={<Button onClick={save} disabled={!list.length || busy}>{busy ? 'Saving…' : `Hand over ${list.length} parcels`}</Button>}>
                        {list.length === 0 ? <Empty>No parcels yet. Scan the first order number.</Empty> : (
                            <div className="max-h-[480px] overflow-auto"><table className="min-w-full divide-y divide-slate-100">
                                <thead className="sticky top-0 bg-white"><tr><th className={th}>Order</th><th className={th}>Tracking</th><th className={th}>Customer</th><th className={`${th} text-right`}>Pcs</th><th className={th} /></tr></thead>
                                <tbody className="divide-y divide-slate-100">
                                    {list.map((p) => { const o = byId.get(p.order_id)!; return (
                                        <tr key={p.order_id}>
                                            <td className={`${td} font-semibold`}>#{p.order_id}</td><td className={`${td} font-mono text-xs`}>{p.tracking_id}</td>
                                            <td className={`${td} text-xs`}>{o.name}<div className="text-slate-500">{o.phone}</div></td>
                                            <td className={`${td} text-right tabular-nums`}>{o.pieces}</td>
                                            <td className={`${td} text-right`}><button className="text-xs text-rose-600 hover:underline" onClick={() => setList((l) => l.filter((x) => x.order_id !== p.order_id))}>Remove</button></td>
                                        </tr>
                                    ); })}
                                </tbody>
                            </table></div>
                        )}
                    </Card>
                </div>

                <Card className="lg:col-span-2" title={`Ready to ship (${orders.length - inList.size})`}>
                    <div className="p-3"><Input placeholder="Search order no., name or phone" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
                    {shown.length === 0 ? <Empty>No confirmed orders waiting.</Empty> : (
                        <div className="max-h-[640px] overflow-auto"><table className="min-w-full divide-y divide-slate-100">
                            <tbody className="divide-y divide-slate-100">
                                {shown.map((o) => (
                                    <tr key={o.id} className="cursor-pointer hover:bg-slate-50" onClick={() => pickOrder(String(o.id))}>
                                        <td className={`${td} font-semibold text-brand-700`}>#{o.id}</td>
                                        <td className={`${td} text-xs`}>{o.name}<div className="text-slate-500">{o.phone}</div></td>
                                        <td className={`${td} text-right text-xs tabular-nums`}>{o.pieces} pcs</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table></div>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
