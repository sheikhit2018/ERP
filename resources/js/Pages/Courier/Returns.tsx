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

type Found = { order_id: number; tracking_id: string; status: string; pieces: number; customer: string; phone: string; handed_over_at: string; items: Item[] };
type Pick = { code: string; label: string; parcels: Found[] };

async function getJson<T>(url: string): Promise<T> {
    const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const j = await r.json().catch(() => null);
    if (!r.ok) { const e = new Error(j?.message || `Lookup failed (${r.status})`) as Error & { status?: number }; e.status = r.status; throw e; }
    return j as T;
}
const matches = (l: Item, c: string) => l.barcode === c || l.sku.toLowerCase() === c.toLowerCase();
/** New parcel row; when opened by a product scan, that first piece is already counted. */
const toParcel = (f: Found, firstPiece?: string): Parcel => {
    let counted = false;
    return { order_id: f.order_id, tracking_id: f.tracking_id, status: f.status, pieces: f.pieces, customer: f.customer, short: '',
        lines: f.items.map((i) => { const hit = !counted && !!firstPiece && matches(i, firstPiece); if (hit) counted = true; return { ...i, back: hit ? 1 : 0, damaged: 0 }; }) };
};
const backOf = (p: Parcel) => p.lines.reduce((a, l) => a + l.back, 0);
const shortOf = (p: Parcel) => p.pieces - backOf(p);

/**
 * Courier returns, piece by piece (same scanning as Stock In).
 * A product barcode counts a piece and finds its parcel; a courier tracking label opens a parcel directly.
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

    const [pick, setPick] = useState<Pick | null>(null);

    /** Count one piece in parcel p if it still needs this item. */
    const countPiece = (p: Parcel, c: string) => {
        const line = p.lines.find((l) => matches(l, c) && l.back < l.quantity);
        if (!line) return false;
        update(p.tracking_id, (x) => ({ ...x, lines: x.lines.map((l) => (l.product_id === line.product_id ? { ...l, back: l.back + 1 } : l)) }));
        setActive(p.tracking_id);
        say(true, `✓ ${line.label} (${line.back + 1}/${line.quantity}) · #${p.order_id} ${p.customer}`);
        return true;
    };
    const openParcel = (f: Found, firstPiece?: string) => {
        setList((l) => [toParcel(f, firstPiece), ...l]);
        setActive(f.tracking_id); setPick(null);
        const line = firstPiece ? f.items.find((i) => matches(i, firstPiece)) : null;
        say(true, `Parcel #${f.order_id} ${f.customer} (${f.pieces} pcs) opened${line ? ` · ✓ ${line.label} (1/${line.quantity})` : ''} — scan every piece inside.${f.status === 'lost' ? ' (Was marked lost — found again.)' : ''}`);
        input.current?.focus();
    };
    const usable = (f: Found) => ['shipped', 'lost'].includes(f.status) && !list.some((p) => p.tracking_id === f.tracking_id);

    /**
     * One scan box, like Stock In:
     *  - a product barcode counts a piece of the open parcel, or finds the parcel it belongs to;
     *  - a courier tracking label opens that parcel.
     */
    const scan = async (e: FormEvent) => {
        e.preventDefault();
        const c = code.trim(); setCode(''); if (!c || busy) return;
        const cur = list.find((p) => p.tracking_id === active);
        if (cur && countPiece(cur, c)) return input.current?.focus();
        const full = cur?.lines.find((l) => matches(l, c));
        if (list.some((p) => p.tracking_id === c)) { setActive(c); say(true, `Parcel ${c} selected — scan its pieces.`); return; }
        // The piece belongs to exactly one other parcel already on screen that still needs it.
        const others = list.filter((p) => p.tracking_id !== active && p.lines.some((l) => matches(l, c) && l.back < l.quantity));
        if (others.length === 1 && countPiece(others[0], c)) return input.current?.focus();
        setBusy(true);
        try {
            try {
                const f = await getJson<Found>(`/courier/parcel?tracking=${encodeURIComponent(c)}&courier=${courier}`);
                if (!usable(f)) throw new Error(`${c} (order #${f.order_id}) is already ${f.status.replace(/_/g, ' ')} — it cannot come back again.`);
                openParcel(f);
                return;
            } catch (err) { if ((err as { status?: number }).status !== 404) throw err; }
            // Not a tracking ID → treat it as a product barcode and find its parcel.
            const r = await getJson<{ product: { label: string }; parcels: Found[] }>(`/courier/parcels/by-product?code=${encodeURIComponent(c)}&courier=${courier}`);
            const cands = r.parcels.filter(usable);
            if (!cands.length) {
                say(false, full ? `Extra piece: ${full.label} — only ${full.quantity} were sent in #${cur!.order_id}, and no other ${couriers[courier]} parcel has it. Keep it aside.`
                    : `${r.product.label}: no ${couriers[courier]} parcel with this item is waiting to come back${cur ? `, and it is not in #${cur.order_id}. Keep it aside (wrong item).` : '.'}`);
            } else if (cands.length === 1) openParcel(cands[0], c);
            else { setPick({ code: c, label: r.product.label, parcels: cands }); say(true, `${cands.length} parcels contain ${r.product.label}. Choose the customer below (check the name on the parcel).`); }
        } catch (err) {
            say(false, (err as Error).message);
        } finally { setBusy(false); input.current?.focus(); }
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
            <PageHeader title="Receive courier returns" subtitle="Scan the product barcode of every returned piece (or the parcel's tracking label first). The system finds the parcel each piece belongs to. Stock comes back only for pieces you scanned."
                actions={<LinkButton variant="secondary" href="/courier">Courier hub</LinkButton>} />
            <Card className="mb-6">
                <div className="grid gap-4 p-5 sm:grid-cols-3">
                    <Field label="Courier"><Select value={courier} onChange={(e) => setCourier(e.target.value)} disabled={list.length > 0}>
                        {Object.entries(couriers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                    <form onSubmit={scan} className="sm:col-span-2"><Field label="Scan product barcode (or tracking label)">
                        <Input ref={input} autoFocus value={code} onChange={(e) => setCode(e.target.value)} placeholder="Scan each returned piece" /></Field></form>
                    <div className="sm:col-span-3"><Field label="Note (optional)"><Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Return challan no., rider name…" maxLength={190} /></Field></div>
                </div>
                {msg && <p className={`mx-5 mb-5 rounded-lg px-3 py-2 text-sm ${msg.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}`}>{msg.text}</p>}
            </Card>

            {pick && (
                <Card className="mb-6 ring-2 ring-amber-300" title={`Which parcel is this ${pick.label} from?`}
                    actions={<button onClick={() => { setPick(null); input.current?.focus(); }} className="text-xs text-slate-500 hover:underline">Cancel</button>}>
                    <div className="max-h-80 overflow-auto"><table className="min-w-full divide-y divide-slate-100 text-sm">
                        <tbody className="divide-y divide-slate-100">
                            {pick.parcels.map((f) => (
                                <tr key={f.tracking_id} className="cursor-pointer hover:bg-amber-50" onClick={() => openParcel(f, pick.code)}>
                                    <td className="px-5 py-2.5"><b>#{f.order_id}</b> · {f.customer}<div className="text-xs text-slate-500">{f.phone}</div></td>
                                    <td className="px-3 py-2.5 font-mono text-xs">{f.tracking_id}</td>
                                    <td className="px-3 py-2.5 text-xs text-slate-600">{f.items.map((i) => `${i.quantity} × ${i.label}`).join(', ')}</td>
                                    <td className="px-5 py-2.5 text-right"><span className="rounded-md bg-brand-600 px-2.5 py-1 text-xs font-semibold text-white">This one</span></td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                </Card>
            )}

            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div className="text-sm text-slate-700"><b>{list.length}</b> parcels · <b>{num(pieces)}</b> pcs scanned back</div>
                <Button onClick={save} disabled={!list.length || busy}>{busy ? 'Saving…' : `Receive ${list.length} parcels`}</Button>
            </div>
            {list.length === 0 ? <Card><Empty>Scan a returned piece's barcode, or the parcel's tracking label.</Empty></Card> : (
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
