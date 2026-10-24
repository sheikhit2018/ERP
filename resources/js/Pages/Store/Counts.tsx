import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Select, StatusBadge, td, th } from '@/Components/ui';
import { dateTime, num } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

type Props = { locations: { id: number; name: string }[]; counts: { id: number; location: string; status: string; note: string | null; created_at: string; approved_at: string | null; lines: number }[] };
export default function Counts({ locations, counts }: PageProps<Props>) {
    const f = useForm({ location_id: String(locations[0]?.id ?? ''), note: '' });
    return (
        <AuthenticatedLayout>
            <Head title="Stock count" />
            <PageHeader title="Stock count (audit)" subtitle="Count what is physically on the shelf, see shortage / excess, and let a manager approve the correction." />
            <Card className="mb-6" title="Start a count">
                <form className="grid gap-3 p-5 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); f.post('/stock-counts'); }}>
                    <Field label="Location"><Select value={f.data.location_id} onChange={(e) => f.setData('location_id', e.target.value)}>{locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select></Field>
                    <Field label="Note"><Input value={f.data.note} onChange={(e) => f.setData('note', e.target.value)} placeholder="Month-end count / rack A" /></Field>
                    <div className="flex items-end"><Button className="w-full" disabled={f.processing}>Start counting</Button></div>
                </form>
            </Card>
            <Card>{counts.length === 0 ? <Empty>No counts yet.</Empty> : (
                <table className="min-w-full divide-y divide-slate-100">
                    <thead><tr><th className={th}>Count</th><th className={th}>Location</th><th className={th}>Status</th><th className={`${th} text-right`}>Products counted</th><th className={th}>Started</th></tr></thead>
                    <tbody className="divide-y divide-slate-100">{counts.map((c) => (
                        <tr key={c.id}><td className={td}><Link href={`/stock-counts/${c.id}`} className="font-semibold text-brand-700">#{c.id}</Link>{c.note && <div className="text-xs text-slate-500">{c.note}</div>}</td>
                            <td className={td}>{c.location}</td><td className={td}><StatusBadge status={c.status} /></td><td className={`${td} text-right tabular-nums`}>{num(c.lines)}</td><td className={`${td} text-xs`}>{dateTime(c.created_at)}</td></tr>))}</tbody>
                </table>)}</Card>
        </AuthenticatedLayout>
    );
}
