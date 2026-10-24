import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Input, PageHeader, Pagination, Select, td, th } from '@/Components/ui';
import { dateTime } from '@/lib/format';
import { Paginated, PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Log = { id: number; action: string; method: string | null; path: string | null; status: number | null; ip: string | null; agent: string | null; meta: string | null; created_at: string; user: string | null };
type Props = { logs: Paginated<Log>; filters: { user?: string; q?: string }; users: { id: number; name: string }[] };

const nice = (a: string) => a.replace(/[._-]/g, ' ');
export default function Audit({ logs, filters, users }: PageProps<Props>) {
    const [q, setQ] = useState(filters.q ?? '');
    const go = (p: Record<string, string | undefined>) => router.get('/audit', { ...filters, ...p }, { preserveState: true });
    return (
        <AuthenticatedLayout>
            <Head title="Activity log" />
            <PageHeader title="Activity log" subtitle="Every change made by anyone in your company: who, what, when and from which IP." actions={<Button variant="secondary" onClick={() => (window.location.href = '/export')}>Download all data (backup)</Button>} />
            <div className="mb-4 flex flex-wrap gap-2">
                <Select className="!w-48" value={filters.user ?? ''} onChange={(e) => go({ user: e.target.value || undefined })}><option value="">Everyone</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</Select>
                <form onSubmit={(e) => { e.preventDefault(); go({ q: q || undefined }); }}><Input className="!w-64" placeholder="Action contains… (e.g. orders, pos, login)" value={q} onChange={(e) => setQ(e.target.value)} /></form>
            </div>
            <Card>
                {logs.data.length === 0 ? <Empty>No activity recorded yet.</Empty> : <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                    <thead><tr><th className={th}>When</th><th className={th}>Who</th><th className={th}>Action</th><th className={th}>Result</th><th className={th}>IP / device</th></tr></thead>
                    <tbody className="divide-y divide-slate-100">{logs.data.map((l) => (
                        <tr key={l.id}><td className={`${td} whitespace-nowrap text-xs`}>{dateTime(l.created_at)}</td><td className={td}>{l.user ?? '—'}</td>
                            <td className={td}><div className="capitalize">{nice(l.action)}</div><div className="font-mono text-[11px] text-slate-400">{l.method} {l.path}{l.meta && l.meta !== '[]' ? ` ${l.meta}` : ''}</div></td>
                            <td className={`${td} text-xs`}>{l.status ? (l.status < 400 ? <span className="text-emerald-700">OK</span> : <span className="text-rose-600">Refused ({l.status})</span>) : ''}</td>
                            <td className={`${td} text-xs text-slate-500`}>{l.ip}<div className="max-w-[16rem] truncate">{l.agent}</div></td></tr>))}</tbody>
                </table></div>}
            </Card>
            <Pagination links={logs.links} />
        </AuthenticatedLayout>
    );
}
