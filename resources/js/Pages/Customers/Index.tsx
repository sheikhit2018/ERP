import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { RiskBadge, Risk } from '@/Components/CustomerRisk';
import { Button, Card, Empty, Input, PageHeader, Pagination, td, th } from '@/Components/ui';
import { dateTime, num, taka } from '@/lib/format';
import { PageProps, Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Row = Risk & { phone: string; name: string; address: string; last_order_id: number; spent_minor: number };

export default function Customers({ customers, q }: PageProps<{ customers: Paginated<Row>; q: string }>) {
    const [s, setS] = useState(q);
    return (
        <AuthenticatedLayout>
            <Head title="Customers" />
            <PageHeader title="Customers" subtitle="Everyone who ordered, by phone number. Success rate = delivered ÷ (delivered + returned). Red = high return risk." />
            <form className="mb-5 flex gap-2" onSubmit={(e) => { e.preventDefault(); router.get('/customers', { q: s }, { preserveState: true }); }}>
                <Input placeholder="Search phone or name" value={s} onChange={(e) => setS(e.target.value)} className="max-w-md bg-white" />
                <Button variant="secondary">Search</Button>
            </form>
            <Card>
                {customers.data.length === 0 ? <Empty>No customers yet.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Customer</th><th className={th}>Phone</th><th className={`${th} text-right`}>Orders</th><th className={`${th} text-right`}>Delivered</th>
                            <th className={`${th} text-right`}>Returned</th><th className={th}>Success</th><th className={`${th} text-right`}>Bought</th><th className={th}>Last order</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {customers.data.map((c) => (
                                <tr key={c.phone} className={c.level === 'risky' ? 'bg-rose-50/40' : ''}>
                                    <td className={td}><div className="font-medium">{c.name}</div><div className="max-w-xs truncate text-xs text-slate-500">{c.address}</div></td>
                                    <td className={`${td} font-mono text-xs`}><a href={`tel:${c.phone}`}>{c.phone}</a></td>
                                    <td className={`${td} text-right tabular-nums`}>{num(c.orders)}</td>
                                    <td className={`${td} text-right tabular-nums text-emerald-700`}>{num(c.delivered)}</td>
                                    <td className={`${td} text-right tabular-nums text-rose-600`}>{num(c.returned)}</td>
                                    <td className={td}><RiskBadge r={c} /></td>
                                    <td className={`${td} text-right tabular-nums`}>{taka(c.spent_minor)}</td>
                                    <td className={td}><Link href={`/orders/${c.last_order_id}`} className="text-brand-700">#{c.last_order_id}</Link>
                                        {c.last_order_at && <div className="text-xs text-slate-400">{dateTime(c.last_order_at)}</div>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                )}
            </Card>
            <Pagination links={customers.links} />
        </AuthenticatedLayout>
    );
}
