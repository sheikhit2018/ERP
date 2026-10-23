import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input, PageHeader, Select } from '@/Components/ui';
import { num, taka, toMinor, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useRef, useState } from 'react';
import { beep, lookupBarcode } from '@/lib/scan';

type Product = { id: number; sku: string; name: string; label: string; color: string | null; size: string | null; barcode: string | null; price_minor: number; stock: { available_stock: number } | null };
type Line = { product_id: string; quantity: string; price: string };

export default function CreateOrder({ products }: PageProps<{ products: Product[] }>) {
    // One idempotency key per form: a double-click or retry can never create two orders.
    const [key] = useState(uuid);
    const [lines, setLines] = useState<Line[]>([{ product_id: '', quantity: '1', price: '' }]);
    const f = useForm({ name: '', phone: '', address: '', status: 'confirmed', source: 'manual', delivery: '0', discount: '0', note: '' });
    const byId = useMemo(() => new Map(products.map((p) => [String(p.id), p])), [products]);
    const setLine = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const subtotal = lines.reduce((a, l) => a + toMinor(l.price) * (parseInt(l.quantity) || 0), 0);
    const total = subtotal + toMinor(f.data.delivery) - toMinor(f.data.discount);
    const e = f.errors as Record<string, string>;
    const [scanCode, setScanCode] = useState('');
    const [scanMsg, setScanMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const scanRef = useRef<HTMLInputElement>(null);
    // Scan a product label: adds the line with name, SKU and price filled in; scanning again adds +1.
    const scanAdd = async (ev: { preventDefault: () => void }) => {
        ev.preventDefault();
        const code = scanCode.trim(); if (!code) return;
        setScanCode('');
        let p = products.find((x) => x.barcode === code || x.sku.toLowerCase() === code.toLowerCase());
        if (!p) {
            try { const r = await lookupBarcode(code); const id = r.matched_id; p = id ? byId.get(String(id)) : undefined;
                if (!p) { beep(false); setScanMsg({ ok: false, text: r.style ? `${r.style.name}: scan the colour/size label, not the style code.` : 'Product is inactive.' }); return; }
            } catch (err) { beep(false); setScanMsg({ ok: false, text: err instanceof Error ? err.message : 'Not found' }); return; }
        }
        const pid = String(p.id), found = p;
        setLines((ls) => {
            const i = ls.findIndex((l) => l.product_id === pid);
            if (i >= 0) return ls.map((l, j) => (j === i ? { ...l, quantity: String((parseInt(l.quantity) || 0) + 1) } : l));
            const blank = ls.findIndex((l) => !l.product_id);
            const line = { product_id: pid, quantity: '1', price: String(found.price_minor / 100) };
            return blank >= 0 ? ls.map((l, j) => (j === blank ? line : l)) : [...ls, line];
        });
        beep(true); setScanMsg({ ok: true, text: `Added ${found.label} · ${found.sku} · ৳${found.price_minor / 100}` });
        scanRef.current?.focus();
    };

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform((d) => ({
            idempotency_key: key, status: d.status, source: d.source, note: d.note || null,
            customer: { name: d.name.trim(), phone: d.phone.trim(), address: d.address.trim() },
            items: lines.filter((l) => l.product_id).map((l) => ({ product_id: Number(l.product_id), quantity: parseInt(l.quantity) || 0, unit_price_minor: toMinor(l.price) })),
            delivery_charge_minor: toMinor(d.delivery), discount_minor: toMinor(d.discount),
        }));
        f.post('/orders');
    };

    return (
        <AuthenticatedLayout>
            <Head title="New order" />
            <PageHeader title="New order" subtitle="Stock is reserved as soon as you save." />
            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card title="Customer">
                        <div className="grid gap-4 p-5 sm:grid-cols-2">
                            <Field label="Name" error={e['customer.name']}><Input value={f.data.name} onChange={(x) => f.setData('name', x.target.value)} required autoFocus /></Field>
                            <Field label="Phone" error={e['customer.phone']}><Input inputMode="tel" value={f.data.phone} onChange={(x) => f.setData('phone', x.target.value)} placeholder="01XXXXXXXXX" required /></Field>
                            <div className="sm:col-span-2"><Field label="Full address" error={e['customer.address']}><Input value={f.data.address} onChange={(x) => f.setData('address', x.target.value)} placeholder="House, road, area, thana, district" required /></Field></div>
                        </div>
                    </Card>
                    <Card title="Products">
                        <div className="space-y-3 p-5">
                            <div className="flex gap-2">
                                <Input ref={scanRef} value={scanCode} onChange={(x) => setScanCode(x.target.value)} onKeyDown={(ev) => { if (ev.key === 'Enter') scanAdd(ev); }}
                                    placeholder="Scan product barcode (name, SKU, price fill in automatically)" autoComplete="off" />
                                <Button type="button" variant="secondary" onClick={scanAdd} disabled={!scanCode.trim()}>Add</Button>
                            </div>
                            {scanMsg && <p className={`text-sm ${scanMsg.ok ? 'text-emerald-700' : 'text-rose-600'}`}>{scanMsg.text}</p>}
                            {lines.map((l, i) => {
                                const p = byId.get(l.product_id);
                                return (
                                    <div key={i} className="grid grid-cols-12 items-end gap-3">
                                        <div className="col-span-12 sm:col-span-6">
                                            <Field label={i === 0 ? 'Product' : ''} error={e[`items.${i}.product_id`]}>
                                                <Select value={l.product_id} onChange={(x) => { const np = byId.get(x.target.value); setLine(i, { product_id: x.target.value, price: np ? String(np.price_minor / 100) : '' }); }} required>
                                                    <option value="">Select product…</option>
                                                    {products.map((pp) => <option key={pp.id} value={pp.id} disabled={lines.some((o, j) => j !== i && o.product_id === String(pp.id))}>
                                                        {pp.label} ({pp.sku}) — {num(pp.stock?.available_stock)} available</option>)}
                                                </Select>
                                            </Field>
                                        </div>
                                        <div className="col-span-4 sm:col-span-2"><Field label={i === 0 ? 'Qty' : ''}><Input type="number" min="1" max={p?.stock?.available_stock || undefined} value={l.quantity} onChange={(x) => setLine(i, { quantity: x.target.value })} required /></Field></div>
                                        <div className="col-span-5 sm:col-span-3"><Field label={i === 0 ? 'Unit price ৳' : ''}><Input type="number" step="0.01" min="0" value={l.price} onChange={(x) => setLine(i, { price: x.target.value })} required /></Field></div>
                                        <div className="col-span-3 sm:col-span-1">
                                            <Button type="button" variant="ghost" disabled={lines.length === 1} onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))} aria-label="Remove line">✕</Button>
                                        </div>
                                    </div>
                                );
                            })}
                            <Button type="button" variant="secondary" onClick={() => setLines((ls) => [...ls, { product_id: '', quantity: '1', price: '' }])}>+ Add another product</Button>
                            {(e.items || e.inventory) && <p className="text-sm text-rose-600">{e.items || e.inventory}</p>}
                        </div>
                    </Card>
                </div>
                <div className="space-y-6">
                    <Card title="Summary">
                        <div className="space-y-4 p-5">
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Delivery charge ৳"><Input type="number" min="0" step="0.01" value={f.data.delivery} onChange={(x) => f.setData('delivery', x.target.value)} /></Field>
                                <Field label="Discount ৳"><Input type="number" min="0" step="0.01" value={f.data.discount} onChange={(x) => f.setData('discount', x.target.value)} /></Field>
                            </div>
                            <Field label="Order source"><Select value={f.data.source} onChange={(x) => f.setData('source', x.target.value)}>
                                <option value="manual">Manual</option><option value="facebook">Facebook / Messenger</option><option value="website">Website</option>
                                <option value="phone">Phone call</option><option value="whatsapp">WhatsApp</option><option value="pos">Shop / POS</option></Select></Field>
                            <Field label="Status"><Select value={f.data.status} onChange={(x) => f.setData('status', x.target.value)}>
                                <option value="confirmed">Confirmed (customer verified)</option><option value="pending">Pending (call to confirm)</option></Select></Field>
                            <Field label="Note"><Input value={f.data.note} onChange={(x) => f.setData('note', x.target.value)} /></Field>
                            <dl className="space-y-1 border-t border-slate-100 pt-4 text-sm">
                                <div className="flex justify-between"><dt className="text-slate-500">Subtotal</dt><dd className="tabular-nums">{taka(subtotal)}</dd></div>
                                <div className="flex justify-between"><dt className="text-slate-500">Delivery</dt><dd className="tabular-nums">{taka(toMinor(f.data.delivery))}</dd></div>
                                <div className="flex justify-between"><dt className="text-slate-500">Discount</dt><dd className="tabular-nums">−{taka(toMinor(f.data.discount))}</dd></div>
                                <div className="flex justify-between pt-2 text-base font-semibold"><dt>Total (COD)</dt><dd className="tabular-nums">{taka(total)}</dd></div>
                            </dl>
                            <Button className="w-full" disabled={f.processing}>{f.processing ? 'Saving…' : 'Save order & reserve stock'}</Button>
                        </div>
                    </Card>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
