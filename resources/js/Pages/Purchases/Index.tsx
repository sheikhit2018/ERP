import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, Empty, LinkButton, PageHeader, Pagination, StatusBadge, td, th } from '@/Components/ui';
import { taka } from '@/lib/format';
import { PageProps, Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

type P = { id: number; status: string; order_date: string; expected_date: string | null; note: string | null; supplier: string; total: number; received: number };

export default function Purchases({ purchases, status }: PageProps<{ purchases: Paginated<P>; status: string | null }>) {
    return (
        <AuthenticatedLayout>
            <Head title="Purchases" />
            <PageHeader title="Purchases" subtitle="Order from suppliers, then receive the goods — stock goes in and the supplier's due goes up."
                actions={<><LinkButton variant="secondary" href="/suppliers">Suppliers</LinkButton><LinkButton href="/purchases/create">+ New purchase</LinkButton></>} />
            <div className="mb-4 inline-flex rounded-lg bg-slate-100 p-1 text-sm">
                {[['', 'All'], ['ordered', 'Waiting'], ['partial', 'Partly received'], ['received', 'Received'], ['cancelled', 'Cancelled']].map(([k, l]) => (
                    <button key={k} onClick={() => router.get('/purchases', k ? { status: k } : {})} className={`rounded-md px-3 py-1.5 ${(status ?? '') === k ? 'bg-white font-semibold shadow-sm' : 'text-slate-600'}`}>{l}</button>))}
            </div>
            <Card>{purchases.data.length === 0 ? <Empty>No purchases yet.</Empty> : (
                <table className="min-w-full divide-y divide-slate-100">
                    <thead><tr><th className={th}>Purchase</th><th className={th}>Supplier</th><th className={th}>Status</th><th className={`${th} text-right`}>Received</th><th className={`${th} text-right`}>Total</th></tr></thead>
                    <tbody className="divide-y divide-slate-100">{purchases.data.map((p) => (
                        <tr key={p.id} className="cursor-pointer hover:bg-slate-50" onClick={() => router.get(`/purchases/${p.id}`)}>
                            <td className={td}><Link href={`/purchases/${p.id}`} className="font-semibold text-brand-700">#{p.id}</Link><div className="text-xs text-slate-500">{String(p.order_date).slice(0, 10)}</div></td>
                            <td className={td}>{p.supplier}</td><td className={td}><StatusBadge status={p.status} /></td>
                            <td className={`${td} text-right tabular-nums`}>{taka(p.received)}</td><td className={`${td} text-right tabular-nums`}>{taka(p.total)}</td></tr>))}</tbody>
                </table>)}</Card>
            <Pagination links={purchases.links} />
        </AuthenticatedLayout>
    );
}
