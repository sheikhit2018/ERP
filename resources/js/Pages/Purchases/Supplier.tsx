import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Select, StatusBadge, td, th } from '@/Components/ui';
import { taka, toMinor } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

type P = { id: number; status: string; order_date: string; note: string | null; total: number; received: number };
type Pay = { id: number; entry_date: string; direction: string; amount_minor: number; reference: string | null; note: string | null; account: { name: string } };
type Props = { supplier: { id: number; name: string; phone: string | null; address: string | null; opening_due_minor: number; active: boolean; note: string | null };
    due: { received: number; paid: number; due: number }; purchases: P[]; payments: Pay[]; accounts: { id: number; name: string }[] };

export default function Supplier({ supplier: s, due, purchases, payments, accounts, role }: PageProps<Props>) {
    const pay = useForm({ money_account_id: String(accounts[0]?.id ?? ''), amount: '', entry_date: new Date().toLocaleDateString('en-CA'), reference: '' });
    const edit = useForm({ id: s.id, name: s.name, phone: s.phone ?? '', address: s.address ?? '', opening: String(s.opening_due_minor / 100), active: s.active });
    return (
        <AuthenticatedLayout>
            <Head title={s.name} />
            <div className="mb-2"><Link href="/suppliers" className="text-sm text-slate-500">← Suppliers</Link></div>
            <PageHeader title={s.name} subtitle={`${s.phone ?? ''} ${s.address ?? ''}`} />
            <div className="mb-6 grid grid-cols-3 gap-3 sm:max-w-2xl">
                {([['Goods received', due.received, ''], ['Paid', due.paid, 'text-emerald-700'], ['Due', due.due, due.due > 0 ? 'text-rose-600' : 'text-emerald-700']] as const).map(([l, v, c]) => (
                    <div key={l} className="rounded-xl border border-slate-200 bg-white p-4"><div className="text-xs text-slate-500">{l}</div><div className={`mt-1 text-xl font-semibold tabular-nums ${c}`}>{taka(v)}</div></div>))}
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
                {role === 'owner' && <Card title="Pay supplier">
                    <form className="grid gap-3 p-5 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); pay.transform((d) => ({ ...d, money_account_id: Number(d.money_account_id), amount_minor: toMinor(d.amount) })); pay.post(`/suppliers/${s.id}/pay`, { preserveScroll: true, onSuccess: () => pay.reset('amount', 'reference') }); }}>
                        <Field label="From account"><Select value={pay.data.money_account_id} onChange={(e) => pay.setData('money_account_id', e.target.value)}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
                        <Field label="Amount ৳" error={(pay.errors as any).amount_minor}><Input type="number" step="0.01" min="0" value={pay.data.amount} onChange={(e) => pay.setData('amount', e.target.value)} required /></Field>
                        <Field label="Date"><Input type="date" value={pay.data.entry_date} onChange={(e) => pay.setData('entry_date', e.target.value)} /></Field>
                        <Field label="Reference"><Input value={pay.data.reference} onChange={(e) => pay.setData('reference', e.target.value)} /></Field>
                        <Button className="sm:col-span-2" disabled={pay.processing}>Save payment</Button>
                    </form>
                </Card>}
                <Card title="Supplier details">
                    <form className="grid gap-3 p-5 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); edit.transform((d) => ({ id: d.id, name: d.name, phone: d.phone || null, address: d.address || null, opening_due_minor: toMinor(d.opening), active: d.active })); edit.post('/suppliers', { preserveScroll: true }); }}>
                        <Field label="Name"><Input value={edit.data.name} onChange={(e) => edit.setData('name', e.target.value)} /></Field>
                        <Field label="Phone"><Input value={edit.data.phone} onChange={(e) => edit.setData('phone', e.target.value)} /></Field>
                        <Field label="Address"><Input value={edit.data.address} onChange={(e) => edit.setData('address', e.target.value)} /></Field>
                        <Field label="Opening due ৳"><Input type="number" step="0.01" value={edit.data.opening} onChange={(e) => edit.setData('opening', e.target.value)} /></Field>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={edit.data.active} onChange={(e) => edit.setData('active', e.target.checked)} /> Active</label>
                        <Button variant="secondary" disabled={edit.processing}>Save</Button>
                    </form>
                </Card>
            </div>
            <div className="mt-6 grid gap-6 lg:grid-cols-2">
                <Card title="Purchases">{purchases.length === 0 ? <Empty>No purchases.</Empty> : (
                    <table className="min-w-full divide-y divide-slate-100"><tbody className="divide-y divide-slate-100">{purchases.map((p) => (
                        <tr key={p.id}><td className={td}><Link href={`/purchases/${p.id}`} className="font-semibold text-brand-700">#{p.id}</Link><div className="text-xs text-slate-500">{String(p.order_date).slice(0, 10)}</div></td>
                            <td className={td}><StatusBadge status={p.status} /></td><td className={`${td} text-right tabular-nums`}>{taka(p.received)}<div className="text-xs text-slate-400">of {taka(p.total)}</div></td></tr>))}</tbody></table>)}</Card>
                <Card title="Payments">{payments.length === 0 ? <Empty>No payments.</Empty> : (
                    <table className="min-w-full divide-y divide-slate-100"><tbody className="divide-y divide-slate-100">{payments.map((p) => (
                        <tr key={p.id}><td className={`${td} text-xs`}>{String(p.entry_date).slice(0, 10)}</td><td className={td}>{p.account?.name}<div className="text-xs text-slate-500">{p.reference}</div></td>
                            <td className={`${td} text-right tabular-nums`}>{p.direction === 'out' ? taka(p.amount_minor) : `−${taka(p.amount_minor)}`}</td></tr>))}</tbody></table>)}</Card>
            </div>
        </AuthenticatedLayout>
    );
}
