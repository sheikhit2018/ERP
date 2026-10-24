import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Couriers } from '@/Components/CourierStats';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, StatusBadge } from '@/Components/ui';
import { beep } from '@/lib/scan';
import { num, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useRef, useState } from 'react';

type Item = { product_id: number; label: string; sku: string; barcode: string | null; quantity: number };
type Line = Item & { back: number; damaged: number };
type Parcel = { order_id: number; tracking_id: string; status: string; pieces: number; customer: string; lines: Line[]; short: '' | 'kept' | 'missing' };

async function lookup(tracking: string, courier: string) {
    const r = await fetch(`/courier/parcel?tracking=${encodeURIComponent(tracking)}&courier=${courier}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const j = await r.json().catch(() => null);
    if (!r.ok) { const e = new Error(j?.message || `Lookup failed (${r.status})`) as Error & { status?: number }; e.status = r.status; throw e; }
    return j as { order_id: number; tracking_id: string; status: string; pieces: number; customer: string; items: Item[] };
}
const backOf = (p: Parcel) => p.lines.reduce((a, l) => a + l.back, 0);
const shortOf = (p: Parcel) => p.pieces - backOf(p);

/**
 * Courier returns, piece by piece.
 * One scan box: a courier tracking label opens that parcel; a product barcode counts a piece of the open parcel.
 * Pieces that did not come back are either kept by the customer (partial delivery) or missing (courier claim).
 */
export default function Returns({ couriers }: PageProps<{ couriers: Couriers }>) {
    const [courier, setCourier] = useState('steadfast');
    const [note, setNote] = useState('');
    const [code, setCode] = useState('');
    const [list, setList] = useState<Parcel[]>([]);
    const [active, setActive] = useState<string | null>(null);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const key = useRef(uuid());
    const input = useRef<HTMLInputElement>(null);
    const say = (ok: boolean, text: string) => { setMsg({ ok, text }); beep(ok); };
    const update = (t: string, fn: (p: Parcel) => Parcel) => setList((l) => l.map((p) => (p.tracking_id === t ? fn(p) : p)));

    const countPiece = (p: Parcel, c: string) => {
        const line = p.lines.find((l) => l.barcode === c || l.sku.toLowerCase() === c.toLowerCase());
        if (!line) return false;
        if (line.back >= line.quantity) { say(false, `Extra piece: ${line.label} — only ${line.quantity} were sent in #${p.order_id}.`); return true; }
        update(p.tracking_id, (x) => ({ ...x, lines: x.lines.map((l) => (l.product_id === line.product_id ? { ...l, back: l.back + 1 } : l)) }));
        say(true, `✓ ${line.label} (${line.back + 1}/${line.quantity}) · #${p.order_id}`);
        return true;
    };

    const scan = async (e: FormEvent) => {
        e.preventDefault();
        const c = code.trim(); setCode(''); if (!c) return;
        const cur = list.find((p) => p.tracking_id === active);
        if (cur && countPiece(cur, c)) return input.current?.focus();
        if (list.some((p) => p.tracking_id === c)) { setActive(c); say(true, `Parcel ${c} selected — scan its pieces.`); return; }
        try {
            const p = await lookup(c, courier);
            if (!['shipped', 'lost'].includes(p.status)) throw new Error(`${c} (order #${p.order_id}) is already ${p.status.replace(/_/g, ' ')} — it cannot come back again.`);
            setList((l) => [{ order_id: p.order_id, tracking_id: p.tracking_id, status: p.status, pieces: p.pieces, customer: p.customer, short: '',
                lines: p.items.map((i) => ({ ...i, back: 0, damaged: 0 })) }, ...l]);
            setActive(p.tracking_id);
            say(true, `Parcel #${p.order_id} (${p.pieces} pcs) opened — now scan every piece inside.${p.status === 'lost' ? ' (Was marked lost — found again.)' : ''}`);
        } catch (err) {
            const notFound = (err as { status?: number }).status === 404;
            say(false, notFound ? (cur ? `${c} is not in parcel #${cur.order_id} and is not a tracking ID. Keep it aside (wrong item).` : `${c}: tracking ID not found for ${couriers[courier]}.`) : (err as Error).message);
        }
        input.current?.focus();
    };

    const problems = list.filter((p) => shortOf(p) > 0 && !p.short);
    const pieces = list.reduce((a, p) => a + backOf(p), 0);
    const save = () => {
        if (!list.length || busy) return;
        if (problems.length) { say(false, `Choose what happened to the missing pieces in ${problems.map((p) => '#' + p.order_id).join(', ')}.`); return; }
        if (!window.confirm(`Receive ${list.length} parcels — ${pieces} pcs back in stock?`)) return;
        setBusy(true);
        router.post('/courier/returns', {
            idempotency_key: key.current, courier, note: note || null,
            parcels: list.map((p) => ({ tracking_id: p.tracking_id, short: shortOf(p) > 0 ? p.short : null,
                items: p.lines.map((l) => ({ product_id: l.product_id, returned: l.back, damaged: Math.min(l.damaged, l.back) })) })),
        }, { onFinish: () => setBusy(false), onError: () => { key.current = uuid(); } });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Receive returns" />
            <PageHeader title="Receive courier returns" subtitle="Scan the parcel's tracking label, open it, scan every piece inside. Stock comes back only for pieces you scanned."
                actions={<LinkButton variant="secondary" href="/courier">Courier hub</LinkButton>} />
            <Card className="mb-6">
                <div className="grid gap-4 p-5 sm:grid-cols-3">
                    <Field label="Courier"><Select value={courier} onChange={(e) => setCourier(e.target.value)} disabled={list.length > 0}>
                        {Object.entries(couriers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                    <form onSubmit={scan} className="sm:col-span-2"><Field label="Scan tracking label, then each piece">
                        <Input ref={input} autoFocus value={code} onChange={(e) => setCode(e.target.value)} placeholder="Tracking ID or product barcode" /></Field></form>
                    <div className="sm:col-span-3"><Field label="Note (optional)"><Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Return challan no., rider name…" maxLength={190} /></Field></div>
                </div>
                {msg && <p className={`mx-5 mb-5 rounded-lg px-3 py-2 text-sm ${msg.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}`}>{msg.text}</p>}
            </Card>

            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div className="text-sm text-slate-700"><b>{list.length}</b> parcels · <b>{num(pieces)}</b> pcs scanned back</div>
                <Button onClick={save} disabled={!list.length || busy}>{busy ? 'Saving…' : `Receive ${list.length} parcels`}</Button>
            </div>
            {list.length === 0 ? <Card><Empty>Scan the first returned parcel's tracking label.</Empty></Card> : (
                <div className="space-y-4">
                    {list.map((p) => {
                        const back = backOf(p), short = shortOf(p), isActive = p.tracking_id === active;
                        return (
                            <Card key={p.tracking_id} className={isActive ? 'ring-2 ring-brand-400' : ''}>
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
                                    <button onClick={() => { setActive(p.tracking_id); input.current?.focus(); }} className="text-left">
                                        <div className="font-semibold text-slate-900">#{p.order_id} · {p.customer} {isActive && <span className="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-xs text-brand-800">scanning</span>}</div>
                                        <div className="font-mono text-xs text-slate-500">{p.tracking_id}</div>
                                    </button>
                                    <div className="flex items-center gap-3 text-sm">
                                        <StatusBadge status={p.status} />
                                        <span className={`font-semibold tabular-nums ${back === p.pieces ? 'text-emerald-700' : 'text-slate-600'}`}>{back}/{p.pieces} pcs back</span>
                                        <button className="text-xs text-rose-600 hover:underline" onClick={() => { setList((l) => l.filter((x) => x.tracking_id !== p.tracking_id)); if (isActive) setActive(null); }}>Remove</button>
                                    </div>
                                </div>
                                <table className="min-w-full text-sm">
                                    <thead className="text-xs uppercase text-slate-500"><tr><th className="px-5 py-2 text-left">Item</th><th className="px-3 py-2 text-right">Sent</th><th className="px-3 py-2 text-right">Back</th><th className="px-3 py-2 text-right">Damaged</th><th className="px-5 py-2 text-right">Not back</th></tr></thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {p.lines.map((l) => (
                                            <tr key={l.product_id} className={l.back === l.quantity ? 'bg-emerald-50/50' : ''}>
                                                <td className="px-5 py-2"><div className="font-medium">{l.label}</div><div className="font-mono text-[11px] text-slate-400">{l.barcode}</div></td>
                                                <td className="px-3 py-2 text-right tabular-nums">{l.quantity}</td>
                                                <td className="px-3 py-2 text-right font-semibold tabular-nums">{l.back}</td>
                                                <td className="px-3 py-2 text-right">
                                                    <input type="number" min={0} max={l.back} value={l.damaged} disabled={!l.back} aria-label={`Damaged ${l.label}`}
                                                        onChange={(e) => { const v = Math.max(0, Math.min(l.back, parseInt(e.target.value) || 0)); update(p.tracking_id, (x) => ({ ...x, lines: x.lines.map((y) => (y.product_id === l.product_id ? { ...y, damaged: v } : y)) })); }}
                                                        className="w-16 rounded-md border-slate-300 py-1 text-right text-sm disabled:bg-slate-50" />
                                                </td>
                                                <td className={`px-5 py-2 text-right tabular-nums ${l.quantity - l.back ? 'font-semibold text-rose-600' : 'text-slate-400'}`}>{l.quantity - l.back}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {short > 0 && (
                                    <div className={`flex flex-wrap items-center gap-4 border-t px-5 py-3 text-sm ${p.short ? 'border-slate-100' : 'border-rose-200 bg-rose-50'}`}>
                                        <b className="text-rose-700">{short} pcs did not come back:</b>
                                        <label className="flex items-center gap-2"><input type="radio" name={`s-${p.tracking_id}`} checked={p.short === 'kept'} onChange={() => update(p.tracking_id, (x) => ({ ...x, short: 'kept' }))} /> Customer kept them (partial delivery)</label>
                                        <label className="flex items-center gap-2"><input type="radio" name={`s-${p.tracking_id}`} checked={p.short === 'missing'} onChange={() => update(p.tracking_id, (x) => ({ ...x, short: 'missing' }))} /> Missing — claim from courier</label>
                                    </div>
                                )}
                            </Card>
                        );
                    })}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
