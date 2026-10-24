import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, td, th } from '@/Components/ui';
import { taka, toMinor } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

type S = { id: number; name: string; phone: string | null; address: string | null; note: string | null; opening_due_minor: number; active: boolean; received: number; paid: number; due: number };

export default function Suppliers({ suppliers }: PageProps<{ suppliers: S[] }>) {
    const f = useForm({ name: '', phone: '', address: '', opening: '' });
    const total = suppliers.reduce((a, s) => a + s.due, 0);
    return (
        <AuthenticatedLayout>
            <Head title="Suppliers" />
            <PageHeader title="Suppliers" subtitle={`You owe suppliers ${taka(total)} in total. Due = opening + goods received − payments.`} actions={<LinkButton href="/purchases/create">+ New purchase</LinkButton>} />
            <Card className="mb-6" title="Add supplier">
                <form className="grid gap-3 p-5 sm:grid-cols-5" onSubmit={(e) => { e.preventDefault(); f.transform((d) => ({ name: d.name, phone: d.phone || null, address: d.address || null, opening_due_minor: toMinor(d.opening) })); f.post('/suppliers', { onSuccess: () => f.reset() }); }}>
                    <Field label="Name" error={(f.errors as any).name}><Input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} required /></Field>
                    <Field label="Phone"><Input value={f.data.phone} onChange={(e) => f.setData('phone', e.target.value)} /></Field>
                    <Field label="Address"><Input value={f.data.address} onChange={(e) => f.setData('address', e.target.value)} /></Field>
                    <Field label="Opening due ৳" hint="What you already owe"><Input type="number" step="0.01" value={f.data.opening} onChange={(e) => f.setData('opening', e.target.value)} /></Field>
                    <div className="flex items-end"><Button className="w-full" disabled={f.processing}>Add</Button></div>
                </form>
            </Card>
            <Card>
                {suppliers.length === 0 ? <Empty>No suppliers yet.</Empty> : (
                    <table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Supplier</th><th className={`${th} text-right`}>Goods received</th><th className={`${th} text-right`}>Paid</th><th className={`${th} text-right`}>Due</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">{suppliers.map((s) => (
                            <tr key={s.id} className={s.active ? '' : 'opacity-50'}><td className={td}><Link href={`/suppliers/${s.id}`} className="font-semibold text-brand-700">{s.name}</Link><div className="text-xs text-slate-500">{s.phone} {s.address}</div></td>
                                <td className={`${td} text-right tabular-nums`}>{taka(s.received)}</td><td className={`${td} text-right tabular-nums`}>{taka(s.paid)}</td>
                                <td className={`${td} text-right font-semibold tabular-nums ${s.due > 0 ? 'text-rose-600' : 'text-emerald-700'}`}>{taka(s.due)}</td></tr>))}</tbody>
                    </table>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
