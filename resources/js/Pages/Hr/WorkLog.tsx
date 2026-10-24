import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, td, th } from '@/Components/ui';
import { num, taka, toMinor } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Stage = { id: number; name: string; rate_minor: number; active: boolean };
type Props = { date: string; stages: Stage[]; employees: { id: number; name: string; designation: string | null; pay_type: string }[]; styles: { id: number; name: string }[];
    logs: { id: number; employee: string; stage: string; quantity: number; rate_minor: number; note: string | null; style: string | null }[];
    month: { stage: string; pieces: number; amount: number }[] };

/** Supervisor enters how many pieces each worker finished at a stage today. Piece-rate workers are paid from this. */
export default function WorkLog({ date, stages, employees, styles, logs, month, role }: PageProps<Props>) {
    const active = stages.filter((s) => s.active);
    const [stage, setStage] = useState(String(active[0]?.id ?? ''));
    const [style, setStyle] = useState('');
    const [qty, setQty] = useState<Record<number, string>>({});
    const [onlyPiece, setOnlyPiece] = useState(employees.some((e) => e.pay_type === 'piece'));
    const [busy, setBusy] = useState(false);
    const st = stages.find((s) => String(s.id) === stage);
    const shown = employees.filter((e) => !onlyPiece || e.pay_type === 'piece');
    const total = Object.values(qty).reduce((a, v) => a + (parseInt(v) || 0), 0);
    const sf = useForm({ id: 0, name: '', rate: '' });
    const canStages = role === 'owner' || role === 'manager';
    const submit = () => router.post('/hr/work', { work_date: date, work_stage_id: Number(stage), product_style_id: style ? Number(style) : null,
        entries: Object.entries(qty).filter(([, v]) => parseInt(v) > 0).map(([id, v]) => ({ employee_id: Number(id), quantity: parseInt(v) })) },
        { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setQty({}) });

    return (
        <AuthenticatedLayout>
            <Head title="Production log" />
            <PageHeader title="Production work log" subtitle="Pieces finished per worker per stage. Piece-rate pay = pieces × stage rate." actions={<LinkButton variant="secondary" href="/hr/attendance">Attendance</LinkButton>} />
            <div className="grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2 space-y-6">
                    <Card title="Add today's output">
                        <div className="grid gap-3 p-5 sm:grid-cols-3">
                            <Field label="Date"><Input type="date" value={date} max={new Date().toLocaleDateString('en-CA')} onChange={(e) => router.get('/hr/work', { date: e.target.value })} /></Field>
                            <Field label="Stage" hint={st ? `${taka(st.rate_minor)} per piece` : undefined}><Select value={stage} onChange={(e) => setStage(e.target.value)}>{active.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select></Field>
                            <Field label="Style (optional)"><Select value={style} onChange={(e) => setStyle(e.target.value)}><option value="">—</option>{styles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select></Field>
                        </div>
                        <div className="flex items-center justify-between border-t border-slate-100 px-5 py-2 text-sm">
                            <label className="flex items-center gap-1.5"><input type="checkbox" checked={onlyPiece} onChange={(e) => setOnlyPiece(e.target.checked)} /> Only piece-rate workers</label>
                            <span className="text-slate-500">{num(total)} pcs · {taka(total * (st?.rate_minor ?? 0))}</span>
                        </div>
                        {shown.length === 0 ? <Empty>No workers. Add staff with pay type “Piece rate”.</Empty> : (
                            <ul className="divide-y divide-slate-100 border-t border-slate-100">{shown.map((e) => (
                                <li key={e.id} className="flex items-center justify-between gap-3 px-5 py-2">
                                    <div className="text-sm"><span className="font-medium text-slate-900">{e.name}</span> <span className="text-xs text-slate-500">{e.designation}</span></div>
                                    <Input type="number" min="0" inputMode="numeric" className="!w-28 text-right" placeholder="0" value={qty[e.id] ?? ''} onChange={(ev) => setQty((q) => ({ ...q, [e.id]: ev.target.value }))} />
                                </li>))}</ul>
                        )}
                        <div className="flex justify-end border-t border-slate-100 p-4"><Button disabled={busy || !total || !stage} onClick={submit}>Save {num(total)} pcs</Button></div>
                    </Card>
                    <Card title={`Entries on ${date}`}>
                        {logs.length === 0 ? <Empty>Nothing logged for this day.</Empty> : (
                            <table className="min-w-full divide-y divide-slate-100">
                                <thead><tr><th className={th}>Worker</th><th className={th}>Stage</th><th className={`${th} text-right`}>Pcs</th><th className={`${th} text-right`}>Earned</th><th className={th} /></tr></thead>
                                <tbody className="divide-y divide-slate-100">{logs.map((l) => (
                                    <tr key={l.id}><td className={td}>{l.employee}</td><td className={`${td} text-xs`}>{l.stage}{l.style && <div className="text-slate-500">{l.style}</div>}</td>
                                        <td className={`${td} text-right tabular-nums`}>{num(l.quantity)}</td><td className={`${td} text-right tabular-nums`}>{taka(l.quantity * l.rate_minor)}</td>
                                        <td className={`${td} text-right`}><Button variant="ghost" className="!px-2 !py-1 text-xs text-rose-700" onClick={() => window.confirm('Remove this entry?') && router.delete(`/hr/work/${l.id}`, { preserveScroll: true })}>Remove</Button></td></tr>))}</tbody>
                            </table>
                        )}
                    </Card>
                </div>
                <div className="space-y-6">
                    <Card title="This month by stage">
                        {month.length === 0 ? <Empty>No output yet.</Empty> : <ul className="divide-y divide-slate-100">{month.map((m) => (
                            <li key={m.stage} className="flex justify-between px-4 py-2.5 text-sm"><span>{m.stage}</span><span className="tabular-nums">{num(m.pieces)} pcs · {taka(m.amount)}</span></li>))}</ul>}
                    </Card>
                    {canStages && <Card title="Stages & piece rates">
                        <ul className="divide-y divide-slate-100">{stages.map((s) => (
                            <li key={s.id} className={`flex items-center justify-between px-4 py-2 text-sm ${s.active ? '' : 'opacity-50'}`}><span>{s.name}</span>
                                <span className="flex items-center gap-2 tabular-nums">{taka(s.rate_minor)}<button className="text-xs text-brand-700" onClick={() => sf.setData({ id: s.id, name: s.name, rate: String(s.rate_minor / 100) })}>Edit</button></span></li>))}</ul>
                        <form className="flex gap-2 border-t border-slate-100 p-3" onSubmit={(e) => { e.preventDefault(); sf.transform((d) => ({ id: d.id || null, name: d.name, rate_minor: toMinor(d.rate) }));
                            sf.post('/hr/stages', { preserveScroll: true, onSuccess: () => sf.setData({ id: 0, name: '', rate: '' }) }); }}>
                            <Input placeholder="Stage name" value={sf.data.name} onChange={(e) => sf.setData('name', e.target.value)} required />
                            <Input type="number" step="0.01" min="0" className="!w-24" placeholder="৳/pc" value={sf.data.rate} onChange={(e) => sf.setData('rate', e.target.value)} required />
                            <Button disabled={sf.processing}>{sf.data.id ? 'Save' : 'Add'}</Button>
                        </form>
                        <p className="px-3 pb-3 text-[11px] text-slate-500">Changing a rate affects new entries only.</p>
                    </Card>}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
