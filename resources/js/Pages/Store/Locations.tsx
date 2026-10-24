import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, td, th } from '@/Components/ui';
import { label } from '@/Components/ProductFinder';
import { dateTime, num, taka } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Loc = { id: number; name: string; type: string; is_main: boolean; address: string | null; active: boolean; pieces: number; value: number };
type Props = { locations: Loc[]; current: number; stock: { id: number; sku: string; name: string; color: string | null; size: string | null; barcode: string | null; qty: number }[];
    transfers: { id: number; from: string; to: string; pieces: number; note: string | null; created_at: string }[] };

export default function Locations({ locations, current, stock, transfers }: PageProps<Props>) {
    const f = useForm({ id: 0, name: '', type: 'shop', address: '', active: true });
    const [q, setQ] = useState('');
    const cur = locations.find((l) => l.id === current);
    const shown = stock.filter((p) => !q || `${p.sku} ${label(p)} ${p.barcode}`.toLowerCase().includes(q.toLowerCase()));
    return (
        <AuthenticatedLayout>
            <Head title="Locations" />
            <PageHeader title="Warehouses & shops" subtitle="Online orders ship from the main warehouse. Shops sell through POS from their own stock."
                actions={<><LinkButton variant="secondary" href="/stock-counts">Stock count</LinkButton><LinkButton href={`/transfers/create?from=${current}`}>Transfer stock</LinkButton></>} />
            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{locations.map((l) => (
                <button key={l.id} onClick={() => router.get('/locations', { location: l.id }, { preserveScroll: true })}
                    className={`rounded-xl bg-white p-4 text-left shadow-sm ring-1 ${l.id === current ? 'ring-2 ring-brand-600' : 'ring-slate-200'} ${l.active ? '' : 'opacity-50'}`}>
                    <div className="flex items-center justify-between"><span className="font-semibold text-slate-900">{l.name}</span><span className="text-[11px] uppercase text-slate-500">{l.is_main ? 'Main' : l.type}</span></div>
                    <div className="mt-2 text-2xl font-bold tabular-nums">{num(l.pieces)} <span className="text-sm font-normal text-slate-500">pcs</span></div>
                    <div className="text-xs text-slate-500">at cost {taka(l.value)}</div>
                    <span className="mt-1 inline-block text-xs text-brand-700" onClick={(e) => { e.stopPropagation(); f.setData({ id: l.id, name: l.name, type: l.type, address: l.address ?? '', active: l.active }); }}>Edit</span>
                </button>))}</div>
            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2" title={`Stock at ${cur?.name ?? ''}`} actions={<Input className="!w-56" placeholder="Filter…" value={q} onChange={(e) => setQ(e.target.value)} />}>
                    {shown.length === 0 ? <Empty>No stock here.</Empty> : (
                        <div className="max-h-[32rem] overflow-auto"><table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Product</th><th className={th}>SKU</th><th className={`${th} text-right`}>Qty</th></tr></thead>
                            <tbody className="divide-y divide-slate-100">{shown.map((p) => <tr key={p.id}><td className={td}>{label(p)}</td><td className={`${td} font-mono text-xs`}>{p.sku}</td><td className={`${td} text-right tabular-nums`}>{num(p.qty)}</td></tr>)}</tbody>
                        </table></div>
                    )}
                </Card>
                <div className="space-y-6">
                    <Card title={f.data.id ? `Edit ${f.data.name}` : 'Add shop / warehouse'}>
                        <form className="space-y-3 p-4" onSubmit={(e) => { e.preventDefault(); f.transform((d) => ({ ...d, id: d.id || null, address: d.address || null })); f.post('/locations', { preserveScroll: true, onSuccess: () => f.reset() }); }}>
                            <Field label="Name" error={(f.errors as any).name}><Input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} required placeholder="Dhanmondi outlet" /></Field>
                            <Field label="Type"><Select value={f.data.type} onChange={(e) => f.setData('type', e.target.value)}><option value="shop">Shop / outlet</option><option value="warehouse">Warehouse</option></Select></Field>
                            <Field label="Address"><Input value={f.data.address} onChange={(e) => f.setData('address', e.target.value)} /></Field>
                            {!!f.data.id && <label className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={f.data.active} onChange={(e) => f.setData('active', e.target.checked)} /> Active</label>}
                            <div className="flex gap-2"><Button className="flex-1" disabled={f.processing}>Save</Button>{!!f.data.id && <Button type="button" variant="secondary" onClick={() => f.reset()}>Cancel</Button>}</div>
                        </form>
                    </Card>
                    <Card title="Recent transfers">
                        {transfers.length === 0 ? <Empty>No transfers yet.</Empty> : <ul className="divide-y divide-slate-100">{transfers.map((t) => (
                            <li key={t.id} className="px-4 py-2.5 text-sm"><div className="flex justify-between"><span>#{t.id} {t.from} → {t.to}</span><span className="font-semibold tabular-nums">{num(t.pieces)} pcs</span></div>
                                <div className="text-xs text-slate-500">{dateTime(t.created_at)}{t.note ? ` · ${t.note}` : ''}</div></li>))}</ul>}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
