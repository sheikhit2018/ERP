import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input, LinkButton, PageHeader, Select } from '@/Components/ui';
import { taka, toMinor } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Prod = { id: number; sku: string; name: string; color: string | null; size: string | null; barcode: string | null; cost_minor: number };
type Mat = { id: number; sku: string; name: string; base_unit: string };
type Line = { key: string; qty: string; cost: string };

export default function CreatePurchase({ suppliers, products, materials }: PageProps<{ suppliers: { id: number; name: string }[]; products: Prod[]; materials: Mat[] }>) {
    const [lines, setLines] = useState<Line[]>([{ key: '', qty: '', cost: '' }]);
    const f = useForm({ supplier_id: String(suppliers[0]?.id ?? ''), order_date: new Date().toLocaleDateString('en-CA'), expected_date: '', note: '' });
    const e = f.errors as Record<string, string>;
    const options = [...products.map((p) => [`p${p.id}`, `${p.name}${p.color || p.size ? ` — ${[p.color, p.size].filter(Boolean).join(' / ')}` : ''} (${p.sku})`] as const),
        ...materials.map((m) => [`m${m.id}`, `Material: ${m.name} (${m.sku}, per ${m.base_unit})`] as const)];
    const total = lines.reduce((a, l) => a + (parseInt(l.qty) || 0) * toMinor(l.cost), 0);
    const set = (i: number, k: keyof Line, v: string) => setLines((ls) => ls.map((l, j) => {
        if (j !== i) return l;
        const n = { ...l, [k]: v };
        if (k === 'key' && v.startsWith('p') && !l.cost) { const p = products.find((x) => `p${x.id}` === v); if (p?.cost_minor) n.cost = String(p.cost_minor / 100); }
        return n;
    }));
    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform((d) => ({ ...d, supplier_id: Number(d.supplier_id), expected_date: d.expected_date || null,
            lines: lines.filter((l) => l.key && parseInt(l.qty) > 0).map((l) => ({ [l.key.startsWith('p') ? 'product_id' : 'raw_material_id']: Number(l.key.slice(1)), quantity: parseInt(l.qty), unit_cost_minor: toMinor(l.cost) })) }));
        f.post('/purchases');
    };
    if (!suppliers.length) return <AuthenticatedLayout><Head title="New purchase" /><PageHeader title="New purchase" /><Card><p className="p-5 text-sm">Add a supplier first. <LinkButton href="/suppliers">Suppliers</LinkButton></p></Card></AuthenticatedLayout>;
    return (
        <AuthenticatedLayout>
            <Head title="New purchase" />
            <PageHeader title="New purchase order" subtitle="What you are buying, how many and at what cost. Receive the goods later from the purchase page." />
            <form onSubmit={submit} className="space-y-6">
                <Card><div className="grid gap-4 p-5 sm:grid-cols-4">
                    <Field label="Supplier" error={e.supplier_id}><Select value={f.data.supplier_id} onChange={(x) => f.setData('supplier_id', x.target.value)}>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select></Field>
                    <Field label="Order date"><Input type="date" value={f.data.order_date} onChange={(x) => f.setData('order_date', x.target.value)} /></Field>
                    <Field label="Expected"><Input type="date" value={f.data.expected_date} onChange={(x) => f.setData('expected_date', x.target.value)} /></Field>
                    <Field label="Note"><Input value={f.data.note} onChange={(x) => f.setData('note', x.target.value)} /></Field>
                </div></Card>
                <Card title={`Items · ${taka(total)}`}>
                    <div className="space-y-2 p-5">
                        {lines.map((l, i) => (
                            <div key={i} className="grid gap-2 sm:grid-cols-[3fr_1fr_1fr_auto]">
                                <Select value={l.key} onChange={(x) => set(i, 'key', x.target.value)}><option value="">Select product or raw material…</option>{options.map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select>
                                <Input type="number" min="1" placeholder="Qty" value={l.qty} onChange={(x) => set(i, 'qty', x.target.value)} />
                                <Input type="number" step="0.01" min="0" placeholder="Unit cost ৳" value={l.cost} onChange={(x) => set(i, 'cost', x.target.value)} />
                                <Button type="button" variant="ghost" onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}>✕</Button>
                            </div>
                        ))}
                        <Button type="button" variant="secondary" onClick={() => setLines((ls) => [...ls, { key: '', qty: '', cost: '' }])}>+ Add line</Button>
                        {(e.inventory || e.lines) && <p className="text-sm text-rose-600">{e.inventory || e.lines}</p>}
                    </div>
                </Card>
                <div className="flex justify-end"><Button disabled={f.processing}>Create purchase</Button></div>
            </form>
        </AuthenticatedLayout>
    );
}
