import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Select, td, th } from '@/Components/ui';
import { dateTime, num, taka, uuid } from '@/lib/format';
import { Lookup, Variant, beep, lookupBarcode, uniq } from '@/lib/scan';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';

type Receipt = { id: number; reason: string; note: string | null; total_quantity: number; line_count: number; created_by: number; created_at: string };
type Group = { key: string; title: string; code: string; variants: Variant[] };

const REASONS: [string, string][] = [['purchase', 'Purchase / supplier'], ['production', 'From own production'], ['return', 'Customer return (not courier)'], ['opening', 'Opening stock'], ['recount', 'Recount (add)']];

export default function StockIn({ receipts, users, errors }: PageProps<{ receipts: Receipt[]; users: Record<string, string> }>) {
    const [code, setCode] = useState('');
    const [groups, setGroups] = useState<Group[]>([]);
    const [qty, setQty] = useState<Record<number, string>>({});
    const [flash, setFlash] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const [reason, setReason] = useState('purchase');
    const [note, setNote] = useState('');
    const [key, setKey] = useState(uuid);
    const scanRef = useRef<HTMLInputElement>(null);
    const cellRefs = useRef<Record<number, HTMLInputElement | null>>({});
    useEffect(() => scanRef.current?.focus(), []);

    const addGroup = (r: Lookup): Group => {
        const g: Group = { key: r.style ? `s${r.style.id}` : `p${r.variants[0].id}`, title: r.style?.name ?? r.variants[0].label, code: r.style?.code ?? r.variants[0].sku, variants: r.variants };
        setGroups((gs) => (gs.some((x) => x.key === g.key) ? gs : [g, ...gs]));
        return g;
    };

    const scan = async (e: FormEvent) => {
        e.preventDefault();
        const c = code.trim(); if (!c || busy) return;
        setBusy(true); setCode('');
        try {
            const r = await lookupBarcode(c);
            addGroup(r);
            if (r.matched_id) {
                // A variant label scanned: count one piece (scan each piece) and highlight that cell.
                const v = r.variants.find((x) => x.id === r.matched_id)!;
                setQty((q) => ({ ...q, [v.id]: String((parseInt(q[v.id]) || 0) + 1) }));
                setFlash({ ok: true, text: `+1 ${v.label}` });
            } else {
                setFlash({ ok: true, text: `${r.style?.name}: type quantities for each colour and size below.` });
                setTimeout(() => { const first = r.variants[0]; cellRefs.current[first.id]?.focus(); }, 50);
                beep(true); setBusy(false); return;
            }
            beep(true);
        } catch (err) {
            beep(false); setFlash({ ok: false, text: err instanceof Error ? err.message : 'Not found' });
        } finally { setBusy(false); scanRef.current?.focus(); }
    };

    const lines = Object.entries(qty).map(([id, q]) => ({ product_id: Number(id), quantity: parseInt(q) || 0 })).filter((l) => l.quantity > 0);
    const total = lines.reduce((a, l) => a + l.quantity, 0);

    const save = () => {
        if (!lines.length) return;
        router.post('/stock-in', { idempotency_key: key, reason, note: note || null, lines }, {
            preserveScroll: true,
            onSuccess: () => { setGroups([]); setQty({}); setNote(''); setKey(uuid()); setFlash(null); scanRef.current?.focus(); },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Stock In" />
            <PageHeader title="Stock In (scan)" subtitle="Scan a product's style barcode, then type quantity for each colour and size. Or scan each piece's label: every scan adds 1." />
            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-5 lg:col-span-2">
                    <Card>
                        <form onSubmit={scan} className="flex gap-2 p-4">
                            <Input ref={scanRef} value={code} onChange={(e) => setCode(e.target.value)} placeholder="Scan barcode or type SKU / style code, press Enter" autoComplete="off" className="text-base" />
                            <Button disabled={busy || !code.trim()}>Find</Button>
                        </form>
                        {flash && <div role="status" className={`mx-4 mb-4 rounded-lg px-3 py-2 text-sm ${flash.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}`}>{flash.text}</div>}
                    </Card>

                    {groups.length === 0 && <Card><Empty>Scan a barcode to start receiving stock.</Empty></Card>}
                    {groups.map((g) => {
                        const colors = uniq(g.variants.map((v) => v.color ?? '—')), sizes = uniq(g.variants.map((v) => v.size ?? '—'));
                        const find = (c: string, s: string) => g.variants.find((v) => (v.color ?? '—') === c && (v.size ?? '—') === s);
                        return (
                            <Card key={g.key} title={`${g.title} · ${g.code} · ${taka(g.variants[0]?.price_minor)}`} actions={<button className="text-sm text-slate-400 hover:text-rose-600" onClick={() => setGroups((gs) => gs.filter((x) => x.key !== g.key))}>Remove</button>}>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full">
                                        <thead><tr><th className={th}>Colour</th>{sizes.map((s) => <th key={s} className={`${th} text-center`}>{s}</th>)}</tr></thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {colors.map((c) => (
                                                <tr key={c}>
                                                    <td className={`${td} font-medium`}>{c}</td>
                                                    {sizes.map((s) => {
                                                        const v = find(c, s);
                                                        return (
                                                            <td key={s} className="px-1.5 py-2 text-center">
                                                                {v ? <>
                                                                    <Input ref={(el) => { cellRefs.current[v.id] = el; }} type="number" min="0" inputMode="numeric" value={qty[v.id] ?? ''}
                                                                        onChange={(e) => setQty((q) => ({ ...q, [v.id]: e.target.value }))} placeholder="0"
                                                                        className={`min-w-[76px] text-center font-semibold ${qty[v.id] ? 'border-brand-400 bg-brand-50' : ''}`} />
                                                                    <div className="mt-0.5 text-[10px] text-slate-400">now {num(v.available)}</div>
                                                                </> : <span className="text-slate-300">—</span>}
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </Card>
                        );
                    })}
                </div>

                <div className="space-y-5">
                    <Card title="Save stock in">
                        <div className="space-y-4 p-5">
                            <Field label="Source"><Select value={reason} onChange={(e) => setReason(e.target.value)}>{REASONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field>
                            <Field label="Note / supplier / challan no."><Input value={note} maxLength={190} onChange={(e) => setNote(e.target.value)} /></Field>
                            <div className="rounded-lg bg-slate-50 p-3 text-sm">
                                <div className="flex justify-between"><span className="text-slate-500">Variants</span><b>{lines.length}</b></div>
                                <div className="flex justify-between"><span className="text-slate-500">Total pieces</span><b className="text-lg">{num(total)}</b></div>
                            </div>
                            {(errors as any)?.inventory && <p className="text-sm text-rose-600">{(errors as any).inventory}</p>}
                            <Button className="w-full" onClick={save} disabled={!lines.length}>Save Stock In</Button>
                        </div>
                    </Card>
                    <Card title="Recent stock in">
                        {receipts.length === 0 ? <Empty>None yet.</Empty> : (
                            <ul className="divide-y divide-slate-100 text-sm">
                                {receipts.map((r) => (
                                    <li key={r.id} className="px-5 py-2.5">
                                        <div className="flex justify-between"><span className="font-medium">#{r.id} · {r.reason}</span><span className="font-semibold tabular-nums">+{num(r.total_quantity)}</span></div>
                                        <div className="text-xs text-slate-500">{r.line_count} variants · {users[r.created_by]} · {dateTime(r.created_at)}{r.note ? ` · ${r.note}` : ''}</div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
