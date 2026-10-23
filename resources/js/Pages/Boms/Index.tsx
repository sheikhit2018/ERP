import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Select } from '@/Components/ui';
import { num } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Opt = { id: number; sku: string; name: string; base_unit?: string };
type Bom = { id: number; version: number; active: boolean; product: Opt; items: { id: number; quantity_per_unit: number; material: Opt }[] };

export default function Boms({ boms, products, materials }: PageProps<{ boms: Bom[]; products: Opt[]; materials: Opt[] }>) {
    const [rows, setRows] = useState([{ raw_material_id: '', quantity_per_unit: '' }]);
    const f = useForm({ product_id: '' });
    const e = f.errors as Record<string, string>;
    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform((d) => ({ product_id: Number(d.product_id), items: rows.filter((r) => r.raw_material_id).map((r) => ({ raw_material_id: Number(r.raw_material_id), quantity_per_unit: parseInt(r.quantity_per_unit) || 0 })) }));
        f.post('/boms', { preserveScroll: true, onSuccess: () => { f.reset(); setRows([{ raw_material_id: '', quantity_per_unit: '' }]); } });
    };
    const unit = (id: string) => materials.find((m) => String(m.id) === id)?.base_unit ?? '';
    return (
        <AuthenticatedLayout>
            <Head title="BOM / recipes" />
            <PageHeader title="Bill of materials" subtitle="How much raw material makes ONE finished unit. Saving a new recipe for a product creates a new version." />
            <div className="grid gap-6 lg:grid-cols-5">
                <Card className="lg:col-span-2" title="New recipe">
                    <form onSubmit={submit} className="space-y-4 p-5">
                        <Field label="Finished product" error={e.product_id}><Select value={f.data.product_id} onChange={(x) => f.setData('product_id', x.target.value)} required>
                            <option value="">Select…</option>{products.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>)}</Select></Field>
                        {rows.map((r, i) => (
                            <div key={i} className="grid grid-cols-12 items-end gap-2">
                                <div className="col-span-7"><Field label={i === 0 ? 'Raw material' : ''}><Select value={r.raw_material_id} onChange={(x) => setRows((rs) => rs.map((o, j) => j === i ? { ...o, raw_material_id: x.target.value } : o))} required>
                                    <option value="">Select…</option>{materials.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</Select></Field></div>
                                <div className="col-span-4"><Field label={i === 0 ? 'Per unit' : ''}><Input type="number" min="1" placeholder={unit(r.raw_material_id)} value={r.quantity_per_unit} onChange={(x) => setRows((rs) => rs.map((o, j) => j === i ? { ...o, quantity_per_unit: x.target.value } : o))} required /></Field></div>
                                <div className="col-span-1"><Button type="button" variant="ghost" disabled={rows.length === 1} onClick={() => setRows((rs) => rs.filter((_, j) => j !== i))}>✕</Button></div>
                            </div>
                        ))}
                        <Button type="button" variant="secondary" onClick={() => setRows((rs) => [...rs, { raw_material_id: '', quantity_per_unit: '' }])}>+ Material</Button>
                        {(e.items || e.inventory) && <p className="text-sm text-rose-600">{e.items || e.inventory}</p>}
                        <Button className="w-full" disabled={f.processing}>Save recipe</Button>
                    </form>
                </Card>
                <div className="space-y-4 lg:col-span-3">
                    {boms.length === 0 && <Card><Empty>No recipes yet.</Empty></Card>}
                    {boms.map((b) => (
                        <Card key={b.id} title={`${b.product.name} — v${b.version}`} actions={<span className={`text-xs font-medium ${b.active ? 'text-emerald-700' : 'text-slate-400'}`}>{b.active ? 'Active' : 'Archived'}</span>}>
                            <ul className="divide-y divide-slate-100 text-sm">
                                {b.items.map((i) => <li key={i.id} className="flex justify-between px-5 py-2"><span>{i.material.name}</span><span className="tabular-nums">{num(i.quantity_per_unit)} {i.material.base_unit}</span></li>)}
                            </ul>
                        </Card>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
