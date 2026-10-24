import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Input, LinkButton, PageHeader } from '@/Components/ui';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type St = 'present' | 'absent' | 'leave' | 'half';
const OPTS: [St, string, string][] = [['present', 'Present', 'bg-emerald-600 text-white'], ['half', 'Half day', 'bg-amber-500 text-white'], ['leave', 'Leave (paid)', 'bg-sky-600 text-white'], ['absent', 'Absent', 'bg-rose-600 text-white']];
type Props = { date: string; employees: { id: number; name: string; designation: string | null; pay_type: string }[]; marks: Record<number, St>; summary: Record<string, number> };

export default function Attendance({ date, employees, marks, summary }: PageProps<Props>) {
    const [m, setM] = useState<Record<number, St>>(marks);
    const [busy, setBusy] = useState(false);
    useEffect(() => setM(marks), [marks]);
    const set = (id: number, s: St) => setM((x) => ({ ...x, [id]: s }));
    const all = (s: St) => setM(Object.fromEntries(employees.map((e) => [e.id, s])));
    const count = (s: St) => Object.values(m).filter((v) => v === s).length;
    const unmarked = employees.filter((e) => !m[e.id]).length;
    return (
        <AuthenticatedLayout>
            <Head title="Attendance" />
            <PageHeader title="Daily attendance" subtitle={`Month so far: ${summary.present ?? 0} present · ${summary.absent ?? 0} absent · ${summary.half ?? 0} half · ${summary.leave ?? 0} leave (staff-days)`}
                actions={<LinkButton variant="secondary" href="/hr/employees">Staff</LinkButton>} />
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Input type="date" className="!w-44" value={date} max={new Date().toLocaleDateString('en-CA')} onChange={(e) => router.get('/hr/attendance', { date: e.target.value }, { preserveState: false })} />
                <Button variant="secondary" onClick={() => all('present')}>Mark everyone present</Button>
                <span className="text-sm text-slate-500">{count('present')} present · {count('half')} half · {count('leave')} leave · {count('absent')} absent{unmarked ? ` · ${unmarked} not marked` : ''}</span>
            </div>
            <Card>
                {employees.length === 0 ? <Empty>Add staff first.</Empty> : (
                    <ul className="divide-y divide-slate-100">{employees.map((e) => (
                        <li key={e.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div><div className="font-medium text-slate-900">{e.name}</div><div className="text-xs text-slate-500">{e.designation}</div></div>
                            <div className="inline-flex flex-wrap gap-1">{OPTS.map(([k, l, on]) => (
                                <button key={k} type="button" onClick={() => set(e.id, k)} className={`rounded-md px-3 py-1.5 text-xs font-medium ring-1 ring-inset ring-slate-200 ${m[e.id] === k ? on : 'bg-white text-slate-600 hover:bg-slate-50'}`}>{l}</button>))}</div>
                        </li>))}</ul>
                )}
            </Card>
            {employees.length > 0 && <div className="mt-4 flex justify-end"><Button disabled={busy || !Object.keys(m).length}
                onClick={() => router.post('/hr/attendance', { date, marks: m }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}>Save attendance for {date}</Button></div>}
        </AuthenticatedLayout>
    );
}
