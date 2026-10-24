import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ProductFinder, { CatalogItem, label } from '@/Components/ProductFinder';
import { Button, Card, Empty, Field, Input, PageHeader, Select, td, th } from '@/Components/ui';
import { num, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Loc = { id: number; name: string; is_main: boolean };
export default function Transfer({ locations, from, products }: PageProps<{ locations: Loc[]; from: number; products: CatalogItem[] }>) {
    const [to, setTo] = useState(String(locations.find((l) => l.id !== from)?.id ?? ''));
    const [lines, setLines] = useState<Record<number, number>>({});
    const [note, setNote] = useState('');
    const [key] = useState(uuid);
    const [busy, setBusy] = useState(false);
    const byId = Object.fromEntries(products.map((p) => [p.id, p]));
    const add = (p: CatalogItem) => setLines((l) => ({ ...l, [p.id]: (l[p.id] ?? 0) + 1 }));
    const rows = Object.entries(lines).filter(([, q]) => q > 0).map(([id, q]) => ({ p: byId[Number(id)], q }));
    const total = rows.reduce((s, r) => s + r.q, 0);
    const over = rows.some((r) => r.q > (r.p.qty ?? 0));
    return (
        <AuthenticatedLayout>
            <Head title="Transfer stock" />
            <PageHeader title="Transfer stock" subtitle="Scan every piece leaving this location, or type quantities. Total stock does not change." />
            <Card className="mb-6"><div className="grid gap-3 p-5 sm:grid-cols-3">
                <Field label="From"><Select value={from} onChange={(e) => router.get('/transfers/create', { from: e.target.value })}>{locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select></Field>
                <Field label="To"><Select value={to} onChange={(e) => setTo(e.target.value)}>{locations.filter((l) => l.id !== from).map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select></Field>
                <Field label="Note"><Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Challan no. / vehicle" /></Field>
            </div></Card>
            <Card>
                <div className="p-4"><ProductFinder items={products} onPick={add} /></div>
                {rows.length === 0 ? <Empty>Scan products to add them.</Empty> : (
                    <table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Product</th><th className={`${th} text-right`}>Available here</th><th className={`${th} text-right`}>Transfer</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">{rows.map(({ p, q }) => (
                            <tr key={p.id} className={q > (p.qty ?? 0) ? 'bg-rose-50' : ''}><td className={td}>{label(p)}<div className="font-mono text-xs text-slate-500">{p.sku}</div></td>
                                <td className={`${td} text-right tabular-nums`}>{num(p.qty)}</td>
                                <td className={`${td} text-right`}><Input type="number" min="0" className="!w-24 text-right" value={q} onChange={(e) => setLines((l) => ({ ...l, [p.id]: Math.max(0, parseInt(e.target.value) || 0) }))} /></td></tr>))}</tbody>
                    </table>
                )}
                <div className="flex items-center justify-between border-t border-slate-100 p-4"><span className="text-sm text-slate-600">{num(total)} pcs{over && <span className="ml-2 text-rose-600">More than available</span>}</span>
                    <Button disabled={busy || !total || !to || over} onClick={() => router.post('/transfers', { idempotency_key: key, from_location_id: from, to_location_id: Number(to), note: note || null,
                        items: rows.map((r) => ({ product_id: r.p.id, quantity: r.q })) }, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}>Transfer {num(total)} pcs</Button></div>
            </Card>
        </AuthenticatedLayout>
    );
}
