import { Button, Select } from '@/Components/ui';
import { jsonApi } from '@/lib/jsonApi';
import { num } from '@/lib/format';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Bookable = { id: number; status: string; name: string; phone: string; address: string; pieces: number };
type Result = { order_id: number; ok: boolean; consignment_id?: string; tracking_code?: string | null; error?: string; already?: boolean };

/**
 * Book parcels with a courier's API (Steadfast / Pathao / RedX): the courier creates the consignments and
 * Opryx stores their tracking numbers. Sent in chunks of 20 so a large batch shows progress.
 */
export default function CourierBooking({ couriers, api }: { couriers: Record<string, string>; api: string[] }) {
    const [courier, setCourier] = useState(api[0] ?? '');
    const [rows, setRows] = useState<Bookable[] | null>(null);
    const [sel, setSel] = useState<Set<number>>(new Set());
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState('');
    const [results, setResults] = useState<Result[]>([]);
    const load = async () => {
        const r = await fetch('/courier/bookable', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (r.ok) { const d: Bookable[] = await r.json(); setRows(d); setSel(new Set(d.map((x) => x.id))); }
    };
    useEffect(() => { load(); }, []);
    if (!api.length) return <p className="p-5 text-sm text-slate-600">No courier API connected. Add your Steadfast, Pathao or RedX API keys in <a className="font-semibold text-brand-700" href="/settings">Settings → Courier API</a> to book parcels from Opryx.</p>;

    const book = async () => {
        const ids = [...sel]; if (!ids.length) return;
        setBusy(true); setResults([]);
        const all: Result[] = [];
        for (let i = 0; i < ids.length; i += 20) {
            setProgress(`Booking ${Math.min(i + 20, ids.length)} of ${ids.length}…`);
            try { const r = await jsonApi<{ results: Result[] }>('/courier/book', { courier, order_ids: ids.slice(i, i + 20) }); all.push(...r.results); }
            catch (e) { all.push(...ids.slice(i, i + 20).map((id) => ({ order_id: id, ok: false, error: e instanceof Error ? e.message : 'Failed' }))); }
            setResults([...all]);
        }
        setBusy(false); setProgress('');
        router.reload({ only: ['bookings', 'unbooked'] }); load();
    };
    const ok = results.filter((r) => r.ok).length, bad = results.filter((r) => !r.ok);
    return (
        <div className="space-y-3 p-5">
            <div className="flex flex-wrap items-end gap-3">
                <label className="text-sm"><span className="mb-1 block font-medium text-slate-700">Courier</span>
                    <Select value={courier} onChange={(e) => setCourier(e.target.value)}>{api.map((c) => <option key={c} value={c}>{couriers[c] ?? c}</option>)}</Select></label>
                <Button onClick={book} disabled={busy || !sel.size}>{busy ? progress : `Book ${num(sel.size)} parcels with ${couriers[courier] ?? courier}`}</Button>
            </div>
            {results.length > 0 && <div className={`rounded-lg px-3 py-2 text-sm ${bad.length ? 'bg-amber-50 text-amber-900' : 'bg-emerald-50 text-emerald-800'}`}>
                <b>{ok} booked</b>{bad.length > 0 && <>, {bad.length} failed:<ul className="mt-1 list-disc pl-5">{bad.slice(0, 30).map((b) => <li key={b.order_id}>#{b.order_id}: {b.error}</li>)}</ul></>}
                {ok > 0 && <div className="mt-1 text-xs">Booked parcels now show their tracking number — print the labels from the courier panel, then scan them below to hand over.</div>}</div>}
            {rows === null ? <p className="text-sm text-slate-500">Loading…</p> : rows.length === 0 ? <p className="text-sm text-slate-500">All confirmed / packed orders are already booked.</p> : (
                <div className="max-h-72 overflow-auto rounded-lg ring-1 ring-slate-200">
                    <table className="min-w-full text-sm">
                        <thead className="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500"><tr>
                            <th className="px-3 py-2 text-left"><input type="checkbox" checked={sel.size === rows.length} onChange={(e) => setSel(e.target.checked ? new Set(rows.map((r) => r.id)) : new Set())} /></th>
                            <th className="px-3 py-2 text-left">Order</th><th className="px-3 py-2 text-left">Customer</th><th className="px-3 py-2 text-left">Address</th><th className="px-3 py-2 text-right">Pcs</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((o) => (
                                <tr key={o.id}><td className="px-3 py-1.5"><input type="checkbox" checked={sel.has(o.id)} onChange={(e) => setSel((s) => { const n = new Set(s); e.target.checked ? n.add(o.id) : n.delete(o.id); return n; })} /></td>
                                    <td className="px-3 py-1.5 font-semibold">#{o.id} <span className="text-xs font-normal text-slate-400">{o.status}</span></td><td className="px-3 py-1.5 text-xs">{o.name}<div className="text-slate-500">{o.phone}</div></td>
                                    <td className="max-w-xs truncate px-3 py-1.5 text-xs text-slate-600">{o.address}</td><td className="px-3 py-1.5 text-right">{o.pieces}</td></tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
