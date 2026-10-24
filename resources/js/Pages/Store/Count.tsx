import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ProductFinder, { CatalogItem, label } from '@/Components/ProductFinder';
import { Button, Card, Input, PageHeader, StatusBadge, td, th } from '@/Components/ui';
import { num, taka } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type P = CatalogItem & { cost_minor: number; system: number; counted: number | null };
type Props = { count: { id: number; status: string; note: string | null; location: string; created_at: string; approved_at: string | null }; products: P[] };

/** Scan each piece (+1) or type a shelf total. Variance = counted − system. */
export default function Count({ count, products, role }: PageProps<Props>) {
    const open = count.status === 'open';
    const [show, setShow] = useState<'all' | 'counted' | 'diff'>('counted');
    const [zero, setZero] = useState(false);
    const [q, setQ] = useState('');
    const post = (counts: { product_id: number; counted: number }[], mode: 'add' | 'set') => router.post(`/stock-counts/${count.id}/lines`, { counts, mode }, { preserveScroll: true, preserveState: true });
    const rows = useMemo(() => products.filter((p) => (show === 'all' ? p.system > 0 || p.counted !== null : show === 'counted' ? p.counted !== null : (p.counted ?? (zero ? 0 : p.system)) !== p.system))
        .filter((p) => !q || `${p.sku} ${label(p)}`.toLowerCase().includes(q.toLowerCase())), [products, show, q, zero]);
    const counted = products.filter((p) => p.counted !== null);
    const effective = (p: P) => p.counted ?? (zero ? 0 : p.system);
    const short = products.reduce((s, p) => s + Math.max(0, p.system - effective(p)), 0);
    const excess = products.reduce((s, p) => s + Math.max(0, effective(p) - p.system), 0);
    const value = products.reduce((s, p) => s + (effective(p) - p.system) * p.cost_minor, 0);
    const canApprove = role === 'owner' || role === 'manager';
    return (
        <AuthenticatedLayout>
            <Head title={`Count #${count.id}`} />
            <PageHeader title={`Stock count #${count.id} · ${count.location}`} subtitle={`${count.note ?? ''}`} actions={<StatusBadge status={count.status} />} />
            <div className="mb-4 grid gap-3 sm:grid-cols-4">
                {([['Products counted', num(counted.length)], ['Pieces counted', num(counted.reduce((s, p) => s + (p.counted ?? 0), 0))], ['Short / excess', `−${num(short)} / +${num(excess)}`], ['Variance at cost', taka(value)]] as const).map(([l, v]) => (
                    <div key={l} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><div className="text-xs text-slate-500">{l}</div><div className={`mt-1 text-xl font-bold tabular-nums ${l === 'Variance at cost' && value < 0 ? 'text-rose-600' : ''}`}>{v}</div></div>))}
            </div>
            {open && <Card className="mb-4"><div className="p-4"><ProductFinder items={products} onPick={(p) => post([{ product_id: p.id, counted: 1 }], 'add')} placeholder="Scan each piece on the shelf (+1)…" /></div></Card>}
            <Card actions={<div className="flex items-center gap-2"><Input className="!w-48" placeholder="Filter…" value={q} onChange={(e) => setQ(e.target.value)} />
                <div className="inline-flex rounded-lg bg-slate-100 p-1 text-xs">{([['counted', 'Counted'], ['diff', 'Differences'], ['all', 'All in stock']] as const).map(([k, l]) =>
                    <button key={k} onClick={() => setShow(k)} className={`rounded-md px-2.5 py-1 ${show === k ? 'bg-white font-medium shadow-sm' : 'text-slate-600'}`}>{l}</button>)}</div></div>}>
                <div className="max-h-[36rem] overflow-auto"><table className="min-w-full divide-y divide-slate-100">
                    <thead><tr><th className={th}>Product</th><th className={`${th} text-right`}>System</th><th className={`${th} text-right`}>Counted</th><th className={`${th} text-right`}>Difference</th></tr></thead>
                    <tbody className="divide-y divide-slate-100">{rows.map((p) => { const d = effective(p) - p.system; return (
                        <tr key={p.id}><td className={td}>{label(p)}<div className="font-mono text-xs text-slate-500">{p.sku}</div></td>
                            <td className={`${td} text-right tabular-nums`}>{num(p.system)}</td>
                            <td className={`${td} text-right`}>{open ? <Input type="number" min="0" className="!w-24 text-right" defaultValue={p.counted ?? ''} placeholder={zero ? '0' : '—'} key={`${p.id}:${p.counted}`}
                                onBlur={(e) => { const v = e.target.value; if (v !== '' && parseInt(v) !== p.counted) post([{ product_id: p.id, counted: Math.max(0, parseInt(v) || 0) }], 'set'); }} /> : num(p.counted ?? 0)}</td>
                            <td className={`${td} text-right font-semibold tabular-nums ${d < 0 ? 'text-rose-600' : d > 0 ? 'text-amber-600' : 'text-slate-400'}`}>{d > 0 ? `+${d}` : d}</td></tr>); })}</tbody>
                </table></div>
            </Card>
            {open && <div className="mt-4 flex flex-wrap items-center justify-end gap-3">
                <label className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={zero} onChange={(e) => setZero(e.target.checked)} /> Products not counted = 0 (full count)</label>
                <Button variant="secondary" onClick={() => window.confirm('Cancel this count? Stock stays as it is.') && router.post(`/stock-counts/${count.id}/cancel`)}>Cancel count</Button>
                {canApprove ? <Button onClick={() => window.confirm(`Approve? Stock is corrected: −${short} short, +${excess} excess.`) && router.post(`/stock-counts/${count.id}/approve`, { zero_missing: zero }, { preserveScroll: true })}>Approve & adjust stock</Button>
                    : <span className="text-sm text-slate-500">A manager approves the count.</span>}
            </div>}
        </AuthenticatedLayout>
    );
}
