import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ScanChecklist, { CheckItem, useScanChecklist } from '@/Components/ScanChecklist';
import { Button, Card, Empty, Input, LinkButton, PageHeader, StatusBadge, td, th } from '@/Components/ui';
import { jsonApi } from '@/lib/jsonApi';
import { beep } from '@/lib/scan';
import { dateTime, num } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';

type Order = { id: number; status: string; customer: string; phone: string; items: CheckItem[] };
type Recent = { id: number; customer: { name: string }; packed_at: string };

async function loadOrder(no: string): Promise<Order> {
    const r = await fetch(`/courier/pack/order?order=${encodeURIComponent(no)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const j = await r.json().catch(() => null);
    if (!r.ok) throw new Error(j?.message || `Order not found (${r.status})`);
    return j;
}

/** One order's checklist. Remounted per order (key) so scan counters start fresh. */
function PackOrder({ order, onPacked, onCancel }: { order: Order; onPacked: (packedToday: number) => void; onCancel: () => void }) {
    const check = useScanChecklist(order.items);
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState('');
    const lock = useRef(false);
    const pack = async () => {
        if (!check.done || lock.current) return;
        lock.current = true; setBusy(true); setErr('');
        try { const r = await jsonApi<{ packed_today: number }>('/courier/pack', { order_id: order.id, scans: check.scans }); beep(true); onPacked(r.packed_today); }
        catch (e) { beep(false); setErr(e instanceof Error ? e.message : 'Could not save.'); }
        finally { lock.current = false; setBusy(false); }
    };
    // Auto-pack the moment the last piece is scanned: no extra click at a busy packing table.
    useEffect(() => { if (check.done) pack(); }, [check.done]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <Card title={`Order #${order.id} · ${order.customer}`} actions={<button onClick={onCancel} className="text-xs text-slate-500 hover:underline">Cancel</button>}>
            <div className="space-y-3 p-5">
                <ScanChecklist items={order.items} state={check} />
                {err && <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{err}</p>}
                <Button className="w-full" onClick={pack} disabled={!check.done || busy}>{busy ? 'Saving…' : check.done ? 'Mark packed' : `Scan every piece (${check.count}/${check.total})`}</Button>
            </div>
        </Card>
    );
}

export default function Pack({ waiting, packedToday, recent }: PageProps<{ waiting: number; packedToday: number; recent: Recent[] }>) {
    const [no, setNo] = useState('');
    const [order, setOrder] = useState<Order | null>(null);
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [done, setDone] = useState(packedToday);
    const [left, setLeft] = useState(waiting);
    const [log, setLog] = useState<{ id: number; name: string }[]>([]);
    const input = useRef<HTMLInputElement>(null);

    const open = async (e: FormEvent) => {
        e.preventDefault();
        const v = no.trim(); setNo(''); if (!v) return;
        try {
            const o = await loadOrder(v);
            if (o.status === 'packed') throw new Error(`Order #${o.id} is already packed.`);
            if (o.status !== 'confirmed') throw new Error(`Order #${o.id} is ${o.status.replace(/_/g, ' ')} — only confirmed orders can be packed.`);
            setOrder(o); setMsg(null); beep(true);
        } catch (x) { setMsg({ ok: false, text: x instanceof Error ? x.message : 'Not found' }); beep(false); input.current?.focus(); }
    };
    const packed = (n: number) => {
        if (!order) return;
        setMsg({ ok: true, text: `✓ Order #${order.id} packed. Scan the next order.` });
        setLog((l) => [{ id: order.id, name: order.customer }, ...l].slice(0, 15));
        setDone(n); setLeft((x) => Math.max(0, x - 1)); setOrder(null);
        requestAnimationFrame(() => input.current?.focus());
    };

    return (
        <AuthenticatedLayout>
            <Head title="Packing station" />
            <PageHeader title="Packing station" subtitle="Scan the order number, then scan every piece you put in the parcel. Wrong size or colour is blocked. Only packed orders can be handed to the courier."
                actions={<LinkButton variant="secondary" href="/courier/dispatch">Hand over to courier →</LinkButton>} />
            <div className="mb-6 grid grid-cols-2 gap-3 sm:max-w-md">
                <div className="rounded-xl border border-slate-200 bg-white p-4"><div className="text-xs text-slate-500">Waiting to pack</div><div className="text-2xl font-semibold tabular-nums">{num(left)}</div></div>
                <div className="rounded-xl border border-slate-200 bg-white p-4"><div className="text-xs text-slate-500">Packed today</div><div className="text-2xl font-semibold tabular-nums text-emerald-700">{num(done)}</div></div>
            </div>
            <div className="grid gap-6 lg:grid-cols-5">
                <div className="space-y-4 lg:col-span-3">
                    {!order && (
                        <Card>
                            <form onSubmit={open} className="p-5">
                                <label className="mb-1 block text-sm font-medium text-slate-700">Order number</label>
                                <Input data-scan ref={input} autoFocus value={no} onChange={(e) => setNo(e.target.value)} placeholder="Scan the order slip or type the order no." className="text-lg" />
                            </form>
                            {msg && <p className={`mx-5 mb-5 rounded-lg px-3 py-2 text-sm ${msg.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'}`}>{msg.text}</p>}
                        </Card>
                    )}
                    {order && <PackOrder key={order.id} order={order} onPacked={packed} onCancel={() => { setOrder(null); requestAnimationFrame(() => input.current?.focus()); }} />}
                </div>
                <Card className="lg:col-span-2" title="Packed — waiting for courier">
                    {log.length === 0 && recent.length === 0 ? <Empty>Nothing packed yet.</Empty> : (
                        <table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Order</th><th className={th}>Customer</th><th className={th} /></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {log.map((l) => <tr key={`n${l.id}`} className="bg-emerald-50/50"><td className={`${td} font-semibold`}>#{l.id}</td><td className={td}>{l.name}</td><td className={td}><StatusBadge status="packed" /></td></tr>)}
                                {recent.filter((r) => !log.some((l) => l.id === r.id)).map((r) => (
                                    <tr key={r.id}><td className={td}><Link href={`/orders/${r.id}`} className="font-semibold text-brand-700">#{r.id}</Link></td>
                                        <td className={`${td} text-xs`}>{r.customer.name}</td><td className={`${td} text-xs text-slate-500`}>{dateTime(r.packed_at)}</td></tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
