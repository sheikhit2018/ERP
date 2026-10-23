import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Select, StatusBadge, td, th } from '@/Components/ui';
import { dateTime, num, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Bom = { id: number; version: number; product: { name: string; sku: string }; items: { id: number; quantity_per_unit: number; material: { name: string; base_unit: string; stock: { quantity: number } | null } }[] };
type MO = { id: number; quantity: number; status: string; completed_at: string; bom: { version: number; product: { name: string } } };

export default function Manufacturing({ boms, orders }: PageProps<{ boms: Bom[]; orders: MO[] }>) {
    const [key, setKey] = useState(uuid);
    const f = useForm({ bom_id: '', quantity: '' });
    const e = f.errors as Record<string, string>;
    const bom = boms.find((b) => String(b.id) === f.data.bom_id);
    const qty = parseInt(f.data.quantity) || 0;
    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform((d) => ({ bom_id: Number(d.bom_id), quantity: parseInt(d.quantity) || 0, idempotency_key: key }));
        f.post('/manufacturing', { preserveScroll: true, onSuccess: () => { f.reset(); setKey(uuid()); } });
    };
    return (
        <AuthenticatedLayout>
            <Head title="Production" />
            <PageHeader title="Production" subtitle="Logging a run deducts raw materials and adds finished goods in one step — all or nothing." />
            <div className="grid gap-6 lg:grid-cols-5">
                <Card className="lg:col-span-2" title="Log production run">
                    <form onSubmit={submit} className="space-y-4 p-5">
                        <Field label="Product recipe" error={e.bom_id}><Select value={f.data.bom_id} onChange={(x) => f.setData('bom_id', x.target.value)} required>
                            <option value="">Select…</option>{boms.map((b) => <option key={b.id} value={b.id}>{(b.product as any).label ?? b.product.name} (v{b.version})</option>)}</Select></Field>
                        <Field label="Units produced" error={e.quantity}><Input type="number" min="1" value={f.data.quantity} onChange={(x) => f.setData('quantity', x.target.value)} required /></Field>
                        {bom && (
                            <div className="rounded-lg bg-slate-50 p-3 text-sm">
                                <div className="mb-2 font-medium">Materials needed</div>
                                {bom.items.map((i) => {
                                    const need = i.quantity_per_unit * qty, have = i.material.stock?.quantity ?? 0;
                                    return <div key={i.id} className="flex justify-between"><span>{i.material.name}</span><span className={`tabular-nums ${need > have ? 'font-semibold text-rose-600' : ''}`}>{num(need)} / {num(have)} {i.material.base_unit}</span></div>;
                                })}
                            </div>
                        )}
                        {e.inventory && <p className="text-sm text-rose-600">{e.inventory}</p>}
                        <Button className="w-full" disabled={f.processing}>Complete production</Button>
                    </form>
                </Card>
                <Card className="lg:col-span-3" title="Production history">
                    {orders.length === 0 ? <Empty>No production runs yet.</Empty> : (
                        <table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Run</th><th className={th}>Product</th><th className={`${th} text-right`}>Units</th><th className={th}>Status</th><th className={th}>When</th></tr></thead>
                            <tbody className="divide-y divide-slate-100">{orders.map((o) => (
                                <tr key={o.id}><td className={td}>#{o.id}</td><td className={td}>{(o.bom.product as any).label ?? o.bom.product.name} <span className="text-xs text-slate-400">v{o.bom.version}</span></td>
                                    <td className={`${td} text-right tabular-nums`}>{num(o.quantity)}</td><td className={td}><StatusBadge status={o.status} /></td><td className={`${td} text-slate-500`}>{dateTime(o.completed_at)}</td></tr>
                            ))}</tbody>
                        </table>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
