import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Dialog from '@/Components/Modal2';
import { Button, Card, Empty, Field, Input, PageHeader, Pagination, Select, td, th } from '@/Components/ui';
import { num, taka, toMinor } from '@/lib/format';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Product = {
    id: number; sku: string; name: string; base_unit: string; price_minor: number; cost_minor: number; low_stock_level: number; active: boolean;
    stock: { available_stock: number; reserved_stock: number; transit_stock: number; damaged_stock: number; on_hand_stock: number } | null;
};

function CreateForm({ onDone }: { onDone: () => void }) {
    const f = useForm({ sku: '', name: '', price: '', cost: '', low_stock_level: '5', opening_stock: '0' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        f.transform((d) => ({ sku: d.sku.trim(), name: d.name.trim(), price_minor: toMinor(d.price), cost_minor: toMinor(d.cost),
            low_stock_level: parseInt(d.low_stock_level) || 0, opening_stock: parseInt(d.opening_stock) || 0 }));
        f.post('/products', { preserveScroll: true, onSuccess: () => { f.reset(); onDone(); } });
    };
    const e = f.errors as Record<string, string>;
    return (
        <form onSubmit={submit} className="grid grid-cols-2 gap-4">
            <Field label="SKU / code" error={e.sku} hint="Letters, numbers, - . _"><Input value={f.data.sku} onChange={(x) => f.setData('sku', x.target.value)} required autoFocus /></Field>
            <Field label="Product name" error={e.name}><Input value={f.data.name} onChange={(x) => f.setData('name', x.target.value)} required /></Field>
            <Field label="Sale price (৳)" error={e.price_minor}><Input type="number" step="0.01" min="0" value={f.data.price} onChange={(x) => f.setData('price', x.target.value)} required /></Field>
            <Field label="Cost price (৳)" error={e.cost_minor}><Input type="number" step="0.01" min="0" value={f.data.cost} onChange={(x) => f.setData('cost', x.target.value)} /></Field>
            <Field label="Low-stock alert at" error={e.low_stock_level}><Input type="number" min="0" value={f.data.low_stock_level} onChange={(x) => f.setData('low_stock_level', x.target.value)} /></Field>
            <Field label="Opening stock" error={e.opening_stock}><Input type="number" min="0" value={f.data.opening_stock} onChange={(x) => f.setData('opening_stock', x.target.value)} /></Field>
            {e.inventory && <p className="col-span-2 text-sm text-rose-600">{e.inventory}</p>}
            <div className="col-span-2 flex justify-end gap-2"><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button><Button disabled={f.processing}>Save product</Button></div>
        </form>
    );
}

function AdjustForm({ product, onDone }: { product: Product; onDone: () => void }) {
    const f = useForm({ direction: 'in', qty: '', reason: 'purchase', note: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        f.transform((d) => ({ delta: (d.direction === 'in' ? 1 : -1) * (parseInt(d.qty) || 0), reason: d.reason, note: d.note }));
        f.post(`/products/${product.id}/adjust`, { preserveScroll: true, onSuccess: onDone });
    };
    const e = f.errors as Record<string, string>;
    return (
        <form onSubmit={submit} className="space-y-4">
            <p className="text-sm text-slate-500">{product.name} — available now: <b>{num(product.stock?.available_stock)}</b></p>
            <div className="grid grid-cols-2 gap-4">
                <Field label="Type"><Select value={f.data.direction} onChange={(x) => f.setData('direction', x.target.value)}><option value="in">Stock in (+)</option><option value="out">Stock out (−)</option></Select></Field>
                <Field label="Quantity" error={e.delta}><Input type="number" min="1" value={f.data.qty} onChange={(x) => f.setData('qty', x.target.value)} required autoFocus /></Field>
                <Field label="Reason" error={e.reason}><Select value={f.data.reason} onChange={(x) => f.setData('reason', x.target.value)}>
                    <option value="purchase">Purchase / supplier receipt</option><option value="recount">Physical recount</option>
                    <option value="damage_writeoff">Damage / write-off</option><option value="opening">Opening stock</option><option value="other">Other</option></Select></Field>
                <Field label="Note (optional)"><Input maxLength={60} value={f.data.note} onChange={(x) => f.setData('note', x.target.value)} /></Field>
            </div>
            {e.inventory && <p className="text-sm text-rose-600">{e.inventory}</p>}
            <div className="flex justify-end gap-2"><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button><Button disabled={f.processing}>Update stock</Button></div>
        </form>
    );
}

export default function Products({ products, q, role }: PageProps<{ products: Paginated<Product>; q: string }>) {
    const [creating, setCreating] = useState(false);
    const [adjusting, setAdjusting] = useState<Product | null>(null);
    const [search, setSearch] = useState(q);
    const canEdit = role === 'owner' || role === 'manager' || role === 'warehouse';
    return (
        <AuthenticatedLayout>
            <Head title="Products & stock" />
            <PageHeader title="Products & stock" subtitle="Available = sellable now. Reserved = held for confirmed/pending orders. Transit = with courier."
                actions={canEdit ? <Button onClick={() => setCreating(true)}>+ Add product</Button> : undefined} />
            <Card>
                <form className="flex gap-2 border-b border-slate-100 p-4" onSubmit={(e) => { e.preventDefault(); router.get('/products', { q: search }, { preserveState: true }); }}>
                    <Input placeholder="Search by name or SKU" value={search} onChange={(e) => setSearch(e.target.value)} className="max-w-sm" />
                    <Button variant="secondary">Search</Button>
                </form>
                {products.data.length === 0 ? <Empty>No products yet. Add your first product to start taking orders.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead className="bg-slate-50/60"><tr>
                            <th className={th}>Product</th><th className={th}>Price</th>
                            <th className={`${th} text-right`}>Available</th><th className={`${th} text-right`}>Reserved</th>
                            <th className={`${th} text-right`}>Transit</th><th className={`${th} text-right`}>Damaged</th><th className={th}></th>
                        </tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {products.data.map((p) => {
                                const low = (p.stock?.available_stock ?? 0) <= p.low_stock_level;
                                return (
                                    <tr key={p.id} className={p.active ? '' : 'opacity-50'}>
                                        <td className={td}><div className="font-medium text-slate-900">{p.name}</div><div className="text-xs text-slate-500">{p.sku}</div></td>
                                        <td className={td}>{taka(p.price_minor)}</td>
                                        <td className={`${td} text-right font-semibold tabular-nums ${low ? 'text-rose-600' : 'text-slate-900'}`}>{num(p.stock?.available_stock)}</td>
                                        <td className={`${td} text-right tabular-nums`}>{num(p.stock?.reserved_stock)}</td>
                                        <td className={`${td} text-right tabular-nums`}>{num(p.stock?.transit_stock)}</td>
                                        <td className={`${td} text-right tabular-nums text-slate-400`}>{num(p.stock?.damaged_stock)}</td>
                                        <td className={`${td} text-right`}>{canEdit && <Button variant="secondary" onClick={() => setAdjusting(p)}>Adjust</Button>}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table></div>
                )}
                <Pagination links={products.links} />
            </Card>
            <Dialog show={creating} onClose={() => setCreating(false)} title="Add product"><CreateForm onDone={() => setCreating(false)} /></Dialog>
            <Dialog show={!!adjusting} onClose={() => setAdjusting(null)} title="Adjust stock">{adjusting && <AdjustForm product={adjusting} onDone={() => setAdjusting(null)} />}</Dialog>
        </AuthenticatedLayout>
    );
}
