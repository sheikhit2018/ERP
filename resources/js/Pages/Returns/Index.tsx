import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import CourierReturnPanel from '@/Components/opryx/CourierReturnPanel';
import { Card, Empty, PageHeader, StatusBadge, td, th } from '@/Components/ui';
import { jsonApi } from '@/lib/jsonApi';
import { dateTime } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

type Ret = { id: number; status: string; created_at: string; shipment: { sales_order_id: number; courier: string; tracking_id: string } };

export default function Returns({ recent }: PageProps<{ recent: Ret[] }>) {
    return (
        <AuthenticatedLayout>
            <Head title="Returns & QC" />
            <PageHeader title="Returns & QC" subtitle="Returned parcels stay unsellable until they pass quality check." />
            <div className="grid gap-6 lg:grid-cols-5">
                <div className="lg:col-span-3"><CourierReturnPanel api={jsonApi} onDone={() => router.reload({ only: ['recent'] })} /></div>
                <Card className="lg:col-span-2" title="Recent returns">
                    {recent.length === 0 ? <Empty>No returns yet.</Empty> : (
                        <table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Order</th><th className={th}>Tracking</th><th className={th}>Status</th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {recent.map((r) => (
                                    <tr key={r.id}>
                                        <td className={td}><Link className="font-semibold text-brand-700" href={`/orders/${r.shipment.sales_order_id}`}>#{r.shipment.sales_order_id}</Link><div className="text-xs text-slate-400">{dateTime(r.created_at)}</div></td>
                                        <td className={`${td} text-xs`}>{r.shipment.courier}<div className="font-mono">{r.shipment.tracking_id}</div></td>
                                        <td className={td}><StatusBadge status={r.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
