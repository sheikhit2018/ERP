import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Dialog from '@/Components/Modal2';
import TagInput from '@/Components/TagInput';
import QtyGrid, { Qty, qtyLines, qtyTotal } from '@/Components/QtyGrid';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Pagination, Select, td, th } from '@/Components/ui';
import { num, taka, toMinor } from '@/lib/format';
import { lookupBarcode, uniq } from '@/lib/scan';
import { PageProps, Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

type Stock = { available_stock: number; reserved_stock: number; transit_stock: number; damaged_stock: number };
type Product = {
    id: number; sku: string; name: string; label: string; color: string | null; size: string | null; barcode: string | null; style_id: number | null;
    price_minor: number; cost_minor: number; low_stock_level: number; active: boolean; stock: Stock | null;
};
type Style = { id: number; code: string; name: string; barcode: string | null };

const SIZE_SUGGEST = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'];
const COLOR_SUGGEST = ['White', 'Black', 'Navy', 'Grey', 'Red', 'Maroon', 'Olive', 'Sky Blue'];

function CreateForm({ onDone }: { onDone: () => void }) {
    const [colors, setColors] = useState<string[]>([]);
    const [sizes, setSizes] = useState<string[]>(['S', 'M', 'L', 'XL']);
    const [qty, setQty] = useState<Qty>({});
    const f = useForm({ code: '', name: '', price: '', cost: '', low: '10', barcode: '' });
    const e = f.errors as Record<string, string>;
    const [exists, setExists] = useState<string | null>(null);
    // Scanning a barcode that is already in Opryx shows that product instead of creating a duplicate.
    const checkBarcode = async () => {
        const code = f.data.barcode.trim(); setExists(null); if (!code) return;
        try {
            const r = await lookupBarcode(code);
            const v = r.variants.find((x) => x.id === r.matched_id) ?? r.variants[0];
            setExists(`Already in Opryx: ${r.style?.name ?? v.label} (${r.style?.code ?? v.sku}) · ৳${(v.price_minor / 100).toLocaleString()}`);
            if (!f.data.name) f.setData((d) => ({ ...d, name: r.style?.name ?? v.name, code: r.style?.code ?? v.sku, price: String(v.price_minor / 100) }));
        } catch { /* new barcode: will be used as this product's style barcode */ }
    };
    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform((d) => ({ code: d.code.trim(), name: d.name.trim(), price_minor: toMinor(d.price), cost_minor: toMinor(d.cost),
            low_stock_level: parseInt(d.low) || 0, colors, sizes, barcode: d.barcode.trim() || null, quantities: qtyLines(qty, colors, sizes) }));
        f.post('/products', { preserveScroll: true, onSuccess: () => { f.reset(); setColors([]); setQty({}); onDone(); } });
    };
    return (
        <form onSubmit={submit} className="space-y-4">
            <Field label="Barcode (optional — scan if the product already has one)" error={e.barcode}>
                <Input value={f.data.barcode} onChange={(x) => { f.setData('barcode', x.target.value); setExists(null); }} onBlur={checkBarcode}
                    onKeyDown={(x) => { if (x.key === 'Enter') { x.preventDefault(); checkBarcode(); } }} placeholder="Leave empty to generate barcodes automatically" autoComplete="off" />
            </Field>
            {exists && <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{exists}. Use “+ Add colour / size” on that product instead of creating it again.</p>}
            <div className="grid grid-cols-2 gap-4">
                <Field label="Product name" error={e.name}><Input value={f.data.name} onChange={(x) => f.setData('name', x.target.value)} placeholder="Polo T-Shirt" required /></Field>
                <Field label="Style code" error={e.code} hint="Short, e.g. POLO01"><Input value={f.data.code} onChange={(x) => f.setData('code', x.target.value.toUpperCase())} required /></Field>
                <Field label="Sale price (৳)" error={e.price_minor}><Input type="number" step="0.01" min="0" value={f.data.price} onChange={(x) => f.setData('price', x.target.value)} required /></Field>
                <Field label="Cost price (৳)" error={e.cost_minor}><Input type="number" step="0.01" min="0" value={f.data.cost} onChange={(x) => f.setData('cost', x.target.value)} /></Field>
            </div>
            <Field label="Colours" error={e.colors}><TagInput value={colors} onChange={setColors} addLabel="Add colour" placeholder="Type a colour, press Enter" suggestions={COLOR_SUGGEST} /></Field>
            <Field label="Sizes" error={e.sizes}><TagInput value={sizes} onChange={setSizes} addLabel="Add size" placeholder="Type a size, press Enter" suggestions={SIZE_SUGGEST} /></Field>
            {colors.length > 0 && sizes.length > 0 && (
                <Field label="Opening quantity per colour × size (optional)" hint="Click a colour or size name to fill a whole row / column. Leave 0 to add stock later with Stock In." error={e.quantities}>
                    <QtyGrid colors={colors} sizes={sizes} value={qty} onChange={setQty} />
                </Field>
            )}
            <Field label="Low-stock alert at (per variant)"><Input type="number" min="0" value={f.data.low} onChange={(x) => f.setData('low', x.target.value)} className="max-w-[140px]" /></Field>
            <p className="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900">
                {colors.length && sizes.length ? <>Creates <b>{colors.length * sizes.length} variants</b> ({colors.length} colours × {sizes.length} sizes), each with its own barcode{qtyTotal(qty, colors, sizes) > 0 && <>, and books <b>{num(qtyTotal(qty, colors, sizes))} pcs</b> opening stock</>}.</> : 'Add at least one colour and one size.'}
            </p>
            {e.inventory && <p className="text-sm text-rose-600">{e.inventory}</p>}
            <div className="flex justify-end gap-2"><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
                <Button disabled={f.processing || !colors.length || !sizes.length || !!exists}>Create product</Button></div>
        </form>
    );
}

function AddVariantsForm({ style, mode, colors, sizes, onDone }: { style: Style; mode: 'colour' | 'size'; colors: string[]; sizes: string[]; onDone: () => void }) {
    const [items, setItems] = useState<string[]>([]);
    const [qty, setQty] = useState<Qty>({});
    const f = useForm({});
    const e = f.errors as Record<string, string>;
    const gColors = mode === 'colour' ? items : colors, gSizes = mode === 'colour' ? sizes : items;
    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform(() => ({ colors: gColors, sizes: gSizes, quantities: qtyLines(qty, gColors, gSizes) }));
        f.post(`/styles/${style.id}/variants`, { preserveScroll: true, onSuccess: onDone });
    };
    const other = mode === 'colour' ? sizes : colors;
    return (
        <form onSubmit={submit} className="space-y-4">
            <TagInput value={items} onChange={setItems} addLabel={mode === 'colour' ? 'Add colour' : 'Add size'}
                placeholder={mode === 'colour' ? 'New colour, press Enter' : 'New size, press Enter'}
                suggestions={(mode === 'colour' ? COLOR_SUGGEST : SIZE_SUGGEST).filter((s) => !(mode === 'colour' ? colors : sizes).includes(s))} />
            {items.length > 0 && <Field label="Opening quantity (optional)"><QtyGrid colors={gColors} sizes={gSizes} value={qty} onChange={setQty} /></Field>}
            <p className="text-sm text-slate-600">Each new {mode} is created in all {other.length} existing {mode === 'colour' ? 'sizes' : 'colours'} ({other.join(', ')}) = <b>{items.length * other.length}</b> new variants with barcodes.</p>
            {(e.inventory || e.colors || e.sizes) && <p className="text-sm text-rose-600">{e.inventory || e.colors || e.sizes}</p>}
            <div className="flex justify-end gap-2"><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button><Button disabled={f.processing || !items.length}>Add</Button></div>
        </form>
    );
}

function VariantDialog({ p, canEdit, onDone }: { p: Product; canEdit: boolean; onDone: () => void }) {
    const adj = useForm({ direction: 'out', qty: '', reason: 'damage_writeoff', note: '' });
    const bc = useForm({ barcode: p.barcode ?? '' });
    const ae = adj.errors as Record<string, string>, be = bc.errors as Record<string, string>;
    const s = p.stock;
    return (
        <div className="space-y-5">
            <div className="grid grid-cols-4 gap-2 text-center">
                {[['Available', s?.available_stock, 'text-brand-700'], ['Reserved', s?.reserved_stock, 'text-sky-700'], ['Transit', s?.transit_stock, 'text-violet-700'], ['Damaged', s?.damaged_stock, 'text-rose-600']].map(([k, v, c]) => (
                    <div key={k as string} className="rounded-lg bg-slate-50 py-2"><div className={`text-lg font-semibold tabular-nums ${c}`}>{num(v as number)}</div><div className="text-xs text-slate-500">{k}</div></div>
                ))}
            </div>
            <div className="text-sm text-slate-600">SKU <span className="font-mono">{p.sku}</span> · Barcode <span className="font-mono">{p.barcode}</span> ·{' '}
                <Link className="text-brand-700" href={`/labels?ids=${p.id}`}>Print label</Link></div>
            {canEdit && (
                <form className="flex items-end gap-2" onSubmit={(ev) => { ev.preventDefault(); bc.post(`/products/${p.id}/barcode`, { preserveScroll: true, onSuccess: onDone }); }}>
                    <div className="flex-1"><Field label="Use your own barcode (optional)" error={be.barcode || be.inventory}><Input value={bc.data.barcode} onChange={(x) => bc.setData('barcode', x.target.value)} /></Field></div>
                    <Button variant="secondary" disabled={bc.processing}>Save</Button>
                </form>
            )}
            {canEdit && (
                <form className="space-y-3 border-t border-slate-100 pt-4" onSubmit={(ev) => {
                    ev.preventDefault();
                    adj.transform((d) => ({ delta: (d.direction === 'in' ? 1 : -1) * (parseInt(d.qty) || 0), reason: d.reason, note: d.note }));
                    adj.post(`/products/${p.id}/adjust`, { preserveScroll: true, onSuccess: onDone });
                }}>
                    <div className="text-sm font-medium text-slate-700">Manual correction <span className="font-normal text-slate-400">(normal receiving → Stock In scan)</span></div>
                    <div className="grid grid-cols-3 gap-2">
                        <Select value={adj.data.direction} onChange={(x) => adj.setData('direction', x.target.value)}><option value="out">Out (−)</option><option value="in">In (+)</option></Select>
                        <Input type="number" min="1" placeholder="Qty" value={adj.data.qty} onChange={(x) => adj.setData('qty', x.target.value)} required />
                        <Select value={adj.data.reason} onChange={(x) => adj.setData('reason', x.target.value)}>
                            <option value="damage_writeoff">Damage / loss</option><option value="recount">Recount</option><option value="other">Other</option></Select>
                    </div>
                    {(ae.delta || ae.inventory) && <p className="text-sm text-rose-600">{ae.delta || ae.inventory}</p>}
                    <div className="flex justify-end"><Button variant="secondary" disabled={adj.processing}>Apply correction</Button></div>
                </form>
            )}
        </div>
    );
}

function StyleCard({ style, items, canEdit, onOpen, onAdd }: { style: Style; items: Product[]; canEdit: boolean; onOpen: (p: Product) => void; onAdd: (m: 'colour' | 'size') => void }) {
    const colors = uniq(items.map((i) => i.color)), sizes = uniq(items.map((i) => i.size));
    const cell = (c: string, s: string) => items.find((i) => i.color === c && i.size === s);
    const total = items.reduce((a, i) => a + (i.stock?.available_stock ?? 0), 0);
    return (
        <Card className="mb-5">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
                <div>
                    <div className="font-semibold text-slate-900">{style.name} <span className="ml-1 text-xs font-normal text-slate-400">{style.code}</span></div>
                    <div className="text-xs text-slate-500">{taka(items[0]?.price_minor)} · {colors.length} colours × {sizes.length} sizes · <b className="text-slate-700">{num(total)}</b> available · style barcode <span className="font-mono">{style.barcode}</span></div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {canEdit && <Button variant="secondary" onClick={() => onAdd('colour')}>+ Add colour</Button>}
                    {canEdit && <Button variant="secondary" onClick={() => onAdd('size')}>+ Add size</Button>}
                    <LinkButton variant="secondary" href={`/labels?style=${style.id}&copies=stock`}>Print labels</LinkButton>
                </div>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full">
                    <thead><tr><th className={th}>Colour</th>{sizes.map((s) => <th key={s} className={`${th} text-center`}>{s}</th>)}<th className={`${th} text-right`}>Total</th></tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {colors.map((c) => (
                            <tr key={c}>
                                <td className={`${td} font-medium`}>{c}</td>
                                {sizes.map((s) => {
                                    const v = cell(c, s);
                                    if (!v) return <td key={s} className={`${td} text-center text-slate-300`}>—</td>;
                                    const a = v.stock?.available_stock ?? 0, low = a <= v.low_stock_level;
                                    return (
                                        <td key={s} className="px-1.5 py-1.5 text-center">
                                            <button onClick={() => onOpen(v)} title={`${v.barcode}`}
                                                className={`w-full min-w-[64px] rounded-lg px-2 py-1.5 tabular-nums ring-1 ring-inset transition hover:ring-brand-400 ${low ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-white text-slate-900 ring-slate-200'}`}>
                                                <div className="text-sm font-semibold">{num(a)}</div>
                                                {(v.stock?.reserved_stock ?? 0) > 0 && <div className="text-[10px] text-sky-700">{num(v.stock?.reserved_stock)} reserved</div>}
                                            </button>
                                        </td>
                                    );
                                })}
                                <td className={`${td} text-right font-semibold tabular-nums`}>{num(items.filter((i) => i.color === c).reduce((a, i) => a + (i.stock?.available_stock ?? 0), 0))}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

export default function Products({ products, styles, q, role }: PageProps<{ products: Paginated<Product>; styles: Record<string, Style>; q: string }>) {
    const [creating, setCreating] = useState(false);
    const [open, setOpen] = useState<Product | null>(null);
    const [adding, setAdding] = useState<{ style: Style; mode: 'colour' | 'size' } | null>(null);
    const [search, setSearch] = useState(q);
    const canEdit = role === 'owner' || role === 'manager' || role === 'warehouse';
    const groups = useMemo(() => {
        const m = new Map<number, Product[]>();
        for (const p of products.data) if (p.style_id) m.set(p.style_id, [...(m.get(p.style_id) ?? []), p]);
        return m;
    }, [products.data]);
    const plain = products.data.filter((p) => !p.style_id);
    const addingItems = adding ? groups.get(adding.style.id) ?? [] : [];

    return (
        <AuthenticatedLayout>
            <Head title="Products & stock" />
            <PageHeader title="Products & stock" subtitle="Numbers show available (sellable) stock per colour and size. Click a cell for barcode, reserved and transit."
                actions={<>{canEdit && <LinkButton variant="secondary" href="/stock-in">Stock In (scan)</LinkButton>}{canEdit && <Button onClick={() => setCreating(true)}>+ Add product</Button>}</>} />
            <form className="mb-5 flex gap-2" onSubmit={(e) => { e.preventDefault(); router.get('/products', { q: search }, { preserveState: true }); }}>
                <Input placeholder="Search name, SKU, colour or scan a barcode" value={search} onChange={(e) => setSearch(e.target.value)} className="max-w-md bg-white" />
                <Button variant="secondary">Search</Button>
            </form>

            {products.data.length === 0 && <Card><Empty>No products yet. Add your first product with its colours and sizes.</Empty></Card>}
            {[...groups.entries()].map(([sid, items]) => styles[sid] && (
                <StyleCard key={sid} style={styles[sid]} items={items} canEdit={canEdit} onOpen={setOpen} onAdd={(mode) => setAdding({ style: styles[sid], mode })} />
            ))}

            {plain.length > 0 && (
                <Card title="Products without colour / size">
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Product</th><th className={th}>Barcode</th><th className={th}>Price</th><th className={`${th} text-right`}>Available</th><th className={`${th} text-right`}>Reserved</th><th className={`${th} text-right`}>Transit</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {plain.map((p) => (
                                <tr key={p.id} className="cursor-pointer hover:bg-slate-50" onClick={() => setOpen(p)}>
                                    <td className={td}><div className="font-medium">{p.name}</div><div className="text-xs text-slate-500">{p.sku}</div></td>
                                    <td className={`${td} font-mono text-xs`}>{p.barcode}</td><td className={td}>{taka(p.price_minor)}</td>
                                    <td className={`${td} text-right font-semibold tabular-nums`}>{num(p.stock?.available_stock)}</td>
                                    <td className={`${td} text-right tabular-nums`}>{num(p.stock?.reserved_stock)}</td>
                                    <td className={`${td} text-right tabular-nums`}>{num(p.stock?.transit_stock)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                </Card>
            )}
            <Pagination links={products.links} />

            <Dialog show={creating} onClose={() => setCreating(false)} title="Add product" wide><CreateForm onDone={() => setCreating(false)} /></Dialog>
            <Dialog show={!!open} onClose={() => setOpen(null)} title={open?.label ?? ''}>{open && <VariantDialog key={open.id} p={open} canEdit={canEdit} onDone={() => setOpen(null)} />}</Dialog>
            <Dialog wide show={!!adding} onClose={() => setAdding(null)} title={adding ? `${adding.mode === 'colour' ? 'Add colour' : 'Add size'} — ${adding.style.name}` : ''}>
                {adding && <AddVariantsForm style={adding.style} mode={adding.mode} colors={uniq(addingItems.map((i) => i.color))} sizes={uniq(addingItems.map((i) => i.size))} onDone={() => setAdding(null)} />}
            </Dialog>
        </AuthenticatedLayout>
    );
}
