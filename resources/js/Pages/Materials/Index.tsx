import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Dialog from '@/Components/Modal2';
import { Button, Card, Empty, Field, Input, PageHeader, Select, td, th } from '@/Components/ui';
import { num } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Material = { id: number; sku: string; name: string; base_unit: string; low_stock_level: number; stock: { quantity: number } | null };
const UNITS = [['g', 'gram (g) — for kg use 1000'], ['ml', 'millilitre (ml) — for litre use 1000'], ['cm', 'centimetre (cm)'], ['mm', 'millimetre (mm)'], ['piece', 'piece']];

export default function Materials({ materials }: PageProps<{ materials: Material[] }>) {
    const [creating, setCreating] = useState(false);
    const [adj, setAdj] = useState<Material | null>(null);
    const c = useForm({ sku: '', name: '', base_unit: 'g', low_stock_level: '0', opening_stock: '0' });
    const a = useForm({ direction: 'in', qty: '', reason: 'purchase' });
    const ce = c.errors as Record<string, string>; const ae = a.errors as Record<string, string>;
    const create = (e: FormEvent) => { e.preventDefault(); c.post('/raw-materials', { preserveScroll: true, onSuccess: () => { c.reset(); setCreating(false); } }); };
    const adjust = (e: FormEvent) => {
        e.preventDefault(); if (!adj) return;
        a.transform((d) => ({ delta: (d.direction === 'in' ? 1 : -1) * (parseInt(d.qty) || 0), reason: d.reason }));
        a.post(`/raw-materials/${adj.id}/adjust`, { preserveScroll: true, onSuccess: () => { a.reset(); setAdj(null); } });
    };
    return (
        <AuthenticatedLayout>
            <Head title="Raw materials" />
            <PageHeader title="Raw materials" subtitle="Quantities are whole base units (e.g. 1.25 kg fabric = 1250 g) so totals never drift."
                actions={<Button onClick={() => setCreating(true)}>+ Add material</Button>} />
            <Card>
                {materials.length === 0 ? <Empty>No raw materials yet.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead className="bg-slate-50/60"><tr><th className={th}>Material</th><th className={th}>Unit</th><th className={`${th} text-right`}>In stock</th><th className={th}></th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {materials.map((m) => (
                                <tr key={m.id}>
                                    <td className={td}><div className="font-medium text-slate-900">{m.name}</div><div className="text-xs text-slate-500">{m.sku}</div></td>
                                    <td className={td}>{m.base_unit}</td>
                                    <td className={`${td} text-right font-semibold tabular-nums ${m.low_stock_level > 0 && (m.stock?.quantity ?? 0) <= m.low_stock_level ? 'text-rose-600' : ''}`}>{num(m.stock?.quantity)}</td>
                                    <td className={`${td} text-right`}><Button variant="secondary" onClick={() => setAdj(m)}>Adjust</Button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                )}
            </Card>
            <Dialog show={creating} onClose={() => setCreating(false)} title="Add raw material">
                <form onSubmit={create} className="grid grid-cols-2 gap-4">
                    <Field label="SKU / code" error={ce.sku}><Input value={c.data.sku} onChange={(x) => c.setData('sku', x.target.value)} required /></Field>
                    <Field label="Name" error={ce.name}><Input value={c.data.name} onChange={(x) => c.setData('name', x.target.value)} required /></Field>
                    <div className="col-span-2"><Field label="Base unit" error={ce.base_unit}><Select value={c.data.base_unit} onChange={(x) => c.setData('base_unit', x.target.value)}>{UNITS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></Field></div>
                    <Field label="Opening stock (base units)" error={ce.opening_stock}><Input type="number" min="0" value={c.data.opening_stock} onChange={(x) => c.setData('opening_stock', x.target.value)} /></Field>
                    <Field label="Low-stock alert at" error={ce.low_stock_level}><Input type="number" min="0" value={c.data.low_stock_level} onChange={(x) => c.setData('low_stock_level', x.target.value)} /></Field>
                    {ce.inventory && <p className="col-span-2 text-sm text-rose-600">{ce.inventory}</p>}
                    <div className="col-span-2 flex justify-end"><Button disabled={c.processing}>Save</Button></div>
                </form>
            </Dialog>
            <Dialog show={!!adj} onClose={() => setAdj(null)} title={`Adjust ${adj?.name ?? ''}`}>
                <form onSubmit={adjust} className="grid grid-cols-2 gap-4">
                    <Field label="Type"><Select value={a.data.direction} onChange={(x) => a.setData('direction', x.target.value)}><option value="in">Stock in (+)</option><option value="out">Stock out (−)</option></Select></Field>
                    <Field label={`Quantity (${adj?.base_unit})`} error={ae.delta}><Input type="number" min="1" value={a.data.qty} onChange={(x) => a.setData('qty', x.target.value)} required /></Field>
                    <div className="col-span-2"><Field label="Reason"><Select value={a.data.reason} onChange={(x) => a.setData('reason', x.target.value)}>
                        <option value="purchase">Purchase / supplier receipt</option><option value="recount">Physical recount</option><option value="damage_writeoff">Damage / wastage</option><option value="other">Other</option></Select></Field></div>
                    {ae.inventory && <p className="col-span-2 text-sm text-rose-600">{ae.inventory}</p>}
                    <div className="col-span-2 flex justify-end"><Button disabled={a.processing}>Update</Button></div>
                </form>
            </Dialog>
        </AuthenticatedLayout>
    );
}
