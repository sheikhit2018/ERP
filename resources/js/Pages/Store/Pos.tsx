import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ProductFinder, { CatalogItem, label } from '@/Components/ProductFinder';
import { Button, Card, Empty, Field, Input, Select } from '@/Components/ui';
import { dateTime, num, taka, toMinor, uuid } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Props = { locations: { id: number; name: string }[]; location: { id: number; name: string; is_main: boolean }; products: CatalogItem[];
    today: { count: number; total: number; by_method: Record<string, number> }; recent: { id: number; total_minor: number; method: string; status: string; customer_name: string | null; created_at: string }[] };
type Line = { p: CatalogItem; qty: number; price: number };
const METHODS: [string, string][] = [['cash', 'Cash'], ['bkash', 'bKash'], ['nagad', 'Nagad'], ['card', 'Card'], ['bank', 'Bank']];

/** Counter sale screen: scan → cart → take payment → print receipt. */
export default function Pos({ locations, location, products, today, recent, role }: PageProps<Props>) {
    const { flash } = usePage().props;
    const [cart, setCart] = useState<Line[]>([]);
    const [disc, setDisc] = useState('');
    const [method, setMethod] = useState('cash');
    const [tendered, setTendered] = useState('');
    const [cust, setCust] = useState({ name: '', phone: '' });
    const [key, setKey] = useState(uuid);
    const [busy, setBusy] = useState(false);
    useEffect(() => { if (flash?.pos_sale) window.open(`/pos/${flash.pos_sale}/receipt`, '_blank', 'width=420,height=640'); }, [flash?.pos_sale]);
    const add = (p: CatalogItem) => setCart((c) => { const i = c.findIndex((l) => l.p.id === p.id); if (i < 0) return [{ p, qty: 1, price: p.price_minor ?? 0 }, ...c];
        const n = [...c]; n[i] = { ...n[i], qty: n[i].qty + 1 }; return n; });
    const set = (id: number, k: 'qty' | 'price', v: number) => setCart((c) => c.map((l) => (l.p.id === id ? { ...l, [k]: v } : l)).filter((l) => l.qty > 0));
    const sub = cart.reduce((s, l) => s + l.qty * l.price, 0);
    const discount = Math.min(toMinor(disc), sub); const total = sub - discount;
    const change = method === 'cash' && tendered ? toMinor(tendered) - total : 0;
    const short = cart.some((l) => l.qty > (l.p.qty ?? 0));
    const pay = () => router.post('/pos', { idempotency_key: key, location_id: location.id, method, discount_minor: discount, tendered_minor: method === 'cash' ? toMinor(tendered) : 0,
        customer_name: cust.name || null, customer_phone: cust.phone || null, items: cart.map((l) => ({ product_id: l.p.id, quantity: l.qty, unit_price_minor: l.price })) },
        { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => { setCart([]); setDisc(''); setTendered(''); setCust({ name: '', phone: '' }); setKey(uuid()); } });
    return (
        <AuthenticatedLayout>
            <Head title="POS" />
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><h1 className="text-xl font-bold text-slate-900">Point of sale</h1><p className="text-sm text-slate-500">Today: {num(today.count)} sales · {taka(today.total)} {Object.entries(today.by_method).map(([m, t]) => `· ${m} ${taka(t)}`).join(' ')}</p></div>
                <Select className="!w-56" value={location.id} onChange={(e) => router.get('/pos', { location: e.target.value })}>{locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select>
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <div className="p-4"><ProductFinder items={products} onPick={add} /></div>
                    {cart.length === 0 ? <Empty>Scan a product to start a sale.</Empty> : (
                        <ul className="divide-y divide-slate-100 border-t border-slate-100">{cart.map((l) => (
                            <li key={l.p.id} className={`flex flex-wrap items-center gap-3 px-4 py-2.5 ${l.qty > (l.p.qty ?? 0) ? 'bg-rose-50' : ''}`}>
                                <div className="min-w-0 flex-1"><div className="truncate font-medium text-slate-900">{label(l.p)}</div><div className="text-xs text-slate-500">{l.p.sku} · {l.p.qty} in stock</div></div>
                                <div className="flex items-center gap-1"><button className="h-8 w-8 rounded bg-slate-100" onClick={() => set(l.p.id, 'qty', l.qty - 1)}>−</button>
                                    <Input type="number" min="0" className="!w-16 text-center" value={l.qty} onChange={(e) => set(l.p.id, 'qty', parseInt(e.target.value) || 0)} />
                                    <button className="h-8 w-8 rounded bg-slate-100" onClick={() => set(l.p.id, 'qty', l.qty + 1)}>+</button></div>
                                <Input type="number" min="0" step="0.01" className="!w-24 text-right" value={l.price / 100} onChange={(e) => set(l.p.id, 'price', toMinor(e.target.value))} />
                                <div className="w-24 text-right font-semibold tabular-nums">{taka(l.qty * l.price)}</div>
                            </li>))}</ul>
                    )}
                </Card>
                <div className="space-y-4">
                    <Card><div className="space-y-3 p-4">
                        <div className="flex justify-between text-sm"><span>Subtotal</span><span className="tabular-nums">{taka(sub)}</span></div>
                        <Field label="Discount ৳"><Input type="number" min="0" step="0.01" value={disc} onChange={(e) => setDisc(e.target.value)} /></Field>
                        <div className="flex justify-between border-t border-slate-100 pt-3 text-lg font-bold"><span>Total</span><span className="tabular-nums">{taka(total)}</span></div>
                        <div className="grid grid-cols-5 gap-1">{METHODS.map(([k, l]) => <button key={k} onClick={() => setMethod(k)} className={`rounded-md py-2 text-xs font-medium ring-1 ring-inset ${method === k ? 'bg-brand-600 text-white ring-brand-600' : 'ring-slate-200'}`}>{l}</button>)}</div>
                        {method === 'cash' && <Field label="Cash received ৳" hint={tendered ? (change >= 0 ? `Change ${taka(change)}` : `Short ${taka(-change)}`) : undefined}><Input type="number" min="0" step="0.01" value={tendered} onChange={(e) => setTendered(e.target.value)} /></Field>}
                        <div className="grid grid-cols-2 gap-2"><Input placeholder="Customer name" value={cust.name} onChange={(e) => setCust({ ...cust, name: e.target.value })} /><Input placeholder="Phone" value={cust.phone} onChange={(e) => setCust({ ...cust, phone: e.target.value })} /></div>
                        {short && <p className="text-xs text-rose-600">Some items are more than the stock at {location.name}.</p>}
                        <Button className="w-full !py-3 text-base" disabled={busy || !cart.length || short || (method === 'cash' && !!tendered && change < 0)} onClick={pay}>Take payment {taka(total)}</Button>
                    </div></Card>
                    <Card title="Today's sales">
                        {recent.length === 0 ? <Empty>No sales yet today.</Empty> : <ul className="divide-y divide-slate-100">{recent.map((s) => (
                            <li key={s.id} className={`flex items-center justify-between px-4 py-2 text-sm ${s.status === 'void' ? 'opacity-50 line-through' : ''}`}>
                                <span><Link href={`/pos/${s.id}/receipt`} className="font-medium text-brand-700">#{s.id}</Link> <span className="text-xs text-slate-500">{s.method} · {dateTime(s.created_at).split(',').pop()}</span></span>
                                <span className="flex items-center gap-2 tabular-nums">{taka(s.total_minor)}{s.status === 'paid' && (role === 'owner' || role === 'manager') &&
                                    <button className="text-xs text-rose-700" onClick={() => window.confirm(`Void sale #${s.id}? Stock goes back and the money entry is reversed.`) && router.post(`/pos/${s.id}/void`, {}, { preserveScroll: true })}>Void</button>}</span>
                            </li>))}</ul>}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
