import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, Empty, PageHeader, Pagination, td, th } from '@/Components/ui';
import { dateTime, num } from '@/lib/format';
import { PageProps, Paginated } from '@/types';
import { Head } from '@inertiajs/react';

type Mv = { id: number; product_id: number | null; raw_material_id: number | null; bucket: string; delta: number; reason: string; reference: string; actor_id: number; created_at: string };

export default function Ledger({ movements, products, materials, users }: PageProps<{ movements: Paginated<Mv>; products: Record<string, string>; materials: Record<string, string>; users: Record<string, string> }>) {
    return (
        <AuthenticatedLayout>
            <Head title="Stock ledger" />
            <PageHeader title="Stock ledger" subtitle="Every stock change, who made it and why. Entries are never edited — corrections are new entries." />
            <Card>
                {movements.data.length === 0 ? <Empty>No stock movements yet.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead className="bg-slate-50/60"><tr><th className={th}>When</th><th className={th}>Item</th><th className={th}>Bucket</th><th className={`${th} text-right`}>Change</th><th className={th}>Reason</th><th className={th}>Reference</th><th className={th}>By</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {movements.data.map((m) => (
                                <tr key={m.id}>
                                    <td className={`${td} whitespace-nowrap text-slate-500`}>{dateTime(m.created_at)}</td>
                                    <td className={td}>{m.product_id ? products[m.product_id] : materials[m.raw_material_id ?? 0]}{m.raw_material_id && <span className="ml-1 text-xs text-slate-400">(raw)</span>}</td>
                                    <td className={`${td} text-xs`}>{m.bucket.replace('_stock', '')}</td>
                                    <td className={`${td} text-right font-semibold tabular-nums ${m.delta > 0 ? 'text-emerald-700' : 'text-rose-700'}`}>{m.delta > 0 ? '+' : ''}{num(m.delta)}</td>
                                    <td className={`${td} text-xs`}>{m.reason.replace(/_/g, ' ')}</td>
                                    <td className={`${td} font-mono text-xs`}>{m.reference}</td>
                                    <td className={`${td} text-xs`}>{users[m.actor_id]}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                )}
                <Pagination links={movements.links} />
            </Card>
        </AuthenticatedLayout>
    );
}
