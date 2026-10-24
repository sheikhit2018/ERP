import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, td, th } from '@/Components/ui';
import { taka, toMinor } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

type E = { id: number; name: string; phone: string | null; designation: string | null; department: string | null; pay_type: 'monthly' | 'daily' | 'piece';
    monthly_salary_minor: number; daily_wage_minor: number; active: boolean; join_date: string | null; advance_due: number };
const PAY = { monthly: 'Monthly salary', daily: 'Daily wage', piece: 'Piece rate (per production)' };
const blank = { id: 0, name: '', phone: '', designation: '', department: '', pay_type: 'monthly' as E['pay_type'], salary: '', wage: '', join_date: '', active: true };

export default function Employees({ employees, accounts, role }: PageProps<{ employees: E[]; accounts: { id: number; name: string }[] }>) {
    const f = useForm(blank);
    const [adv, setAdv] = useState<E | null>(null);
    const a = useForm({ money_account_id: String(accounts[0]?.id ?? ''), amount: '', paid_on: new Date().toISOString().slice(0, 10), note: '' });
    const edit = (e: E) => { f.setData({ id: e.id, name: e.name, phone: e.phone ?? '', designation: e.designation ?? '', department: e.department ?? '', pay_type: e.pay_type,
        salary: e.monthly_salary_minor ? String(e.monthly_salary_minor / 100) : '', wage: e.daily_wage_minor ? String(e.daily_wage_minor / 100) : '', join_date: e.join_date ?? '', active: e.active }); window.scrollTo({ top: 0, behavior: 'smooth' }); };
    const save = (ev: React.FormEvent) => { ev.preventDefault();
        f.transform((d) => ({ id: d.id || null, name: d.name, phone: d.phone || null, designation: d.designation || null, department: d.department || null, pay_type: d.pay_type,
            monthly_salary_minor: toMinor(d.salary), daily_wage_minor: toMinor(d.wage), join_date: d.join_date || null, active: d.active }));
        f.post('/hr/employees', { preserveScroll: true, onSuccess: () => f.setData(blank) }); };
    const monthly = employees.filter((e) => e.active).reduce((s, e) => s + (e.pay_type === 'monthly' ? e.monthly_salary_minor : 0), 0);
    const err = f.errors as Record<string, string>;

    return (
        <AuthenticatedLayout>
            <Head title="Staff" />
            <PageHeader title="Staff & workers" subtitle={`${employees.filter((e) => e.active).length} active · fixed monthly salaries ${taka(monthly)}`}
                actions={<><LinkButton variant="secondary" href="/hr/attendance">Attendance</LinkButton><LinkButton variant="secondary" href="/hr/work">Production log</LinkButton>{role === 'owner' && <LinkButton href="/hr/payroll">Payroll</LinkButton>}</>} />
            <Card className="mb-6" title={f.data.id ? `Edit ${f.data.name}` : 'Add employee'}>
                <form className="grid gap-3 p-5 sm:grid-cols-4" onSubmit={save}>
                    <Field label="Name" error={err.name}><Input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} required /></Field>
                    <Field label="Phone"><Input value={f.data.phone} onChange={(e) => f.setData('phone', e.target.value)} /></Field>
                    <Field label="Designation" hint="e.g. Sewing operator"><Input value={f.data.designation} onChange={(e) => f.setData('designation', e.target.value)} /></Field>
                    <Field label="Department"><Input value={f.data.department} onChange={(e) => f.setData('department', e.target.value)} placeholder="Factory / Office / Warehouse" /></Field>
                    <Field label="Pay type"><Select value={f.data.pay_type} onChange={(e) => f.setData('pay_type', e.target.value as E['pay_type'])}>{Object.entries(PAY).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Select></Field>
                    {f.data.pay_type === 'monthly' && <Field label="Monthly salary ৳" error={err.monthly_salary_minor}><Input type="number" step="0.01" value={f.data.salary} onChange={(e) => f.setData('salary', e.target.value)} required /></Field>}
                    {f.data.pay_type === 'daily' && <Field label="Daily wage ৳"><Input type="number" step="0.01" value={f.data.wage} onChange={(e) => f.setData('wage', e.target.value)} required /></Field>}
                    {f.data.pay_type === 'piece' && <div className="self-end pb-2 text-xs text-slate-500">Paid from the production log × stage rate.</div>}
                    <Field label="Joined"><Input type="date" value={f.data.join_date} onChange={(e) => f.setData('join_date', e.target.value)} /></Field>
                    <div className="flex items-end gap-2">
                        <label className="mb-2 flex items-center gap-1.5 text-sm"><input type="checkbox" checked={f.data.active} onChange={(e) => f.setData('active', e.target.checked)} /> Active</label>
                        <Button className="flex-1" disabled={f.processing}>{f.data.id ? 'Save' : 'Add'}</Button>
                        {!!f.data.id && <Button type="button" variant="secondary" onClick={() => f.setData(blank)}>Cancel</Button>}
                    </div>
                </form>
            </Card>

            {adv && role === 'owner' && (
                <Card className="mb-6" title={`Salary advance — ${adv.name}`}>
                    <form className="grid gap-3 p-5 sm:grid-cols-5" onSubmit={(e) => { e.preventDefault(); a.transform((d) => ({ money_account_id: Number(d.money_account_id), amount_minor: toMinor(d.amount), paid_on: d.paid_on, note: d.note || null }));
                        a.post(`/hr/employees/${adv.id}/advance`, { preserveScroll: true, onSuccess: () => { a.reset('amount', 'note'); setAdv(null); } }); }}>
                        <Field label="Amount ৳" error={(a.errors as any).amount_minor}><Input type="number" step="0.01" min="0.01" value={a.data.amount} onChange={(e) => a.setData('amount', e.target.value)} required autoFocus /></Field>
                        <Field label="Paid from"><Select value={a.data.money_account_id} onChange={(e) => a.setData('money_account_id', e.target.value)}>{accounts.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select></Field>
                        <Field label="Date"><Input type="date" value={a.data.paid_on} onChange={(e) => a.setData('paid_on', e.target.value)} /></Field>
                        <Field label="Note"><Input value={a.data.note} onChange={(e) => a.setData('note', e.target.value)} /></Field>
                        <div className="flex items-end gap-2"><Button className="flex-1" disabled={a.processing}>Pay advance</Button><Button type="button" variant="secondary" onClick={() => setAdv(null)}>✕</Button></div>
                    </form>
                </Card>
            )}

            <Card>
                {employees.length === 0 ? <Empty>No staff yet. Add your workers above.</Empty> : (
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead><tr><th className={th}>Name</th><th className={th}>Pay</th><th className={`${th} text-right`}>Rate</th><th className={`${th} text-right`}>Advance due</th><th className={th} /></tr></thead>
                        <tbody className="divide-y divide-slate-100">{employees.map((e) => (
                            <tr key={e.id} className={e.active ? '' : 'opacity-50'}>
                                <td className={td}><div className="font-semibold text-slate-900">{e.name}</div><div className="text-xs text-slate-500">{[e.designation, e.department, e.phone].filter(Boolean).join(' · ')}</div></td>
                                <td className={`${td} text-xs`}>{PAY[e.pay_type]}</td>
                                <td className={`${td} text-right tabular-nums`}>{e.pay_type === 'monthly' ? `${taka(e.monthly_salary_minor)}/mo` : e.pay_type === 'daily' ? `${taka(e.daily_wage_minor)}/day` : 'per piece'}</td>
                                <td className={`${td} text-right tabular-nums ${e.advance_due ? 'font-semibold text-amber-700' : 'text-slate-400'}`}>{taka(e.advance_due)}</td>
                                <td className={`${td} whitespace-nowrap text-right`}>
                                    <Button variant="ghost" className="!px-2 !py-1 text-xs" onClick={() => edit(e)}>Edit</Button>
                                    {role === 'owner' && e.active && <Button variant="ghost" className="!px-2 !py-1 text-xs" onClick={() => setAdv(e)}>Advance</Button>}
                                </td>
                            </tr>))}</tbody>
                    </table></div>
                )}
            </Card>
        </AuthenticatedLayout>
    );
}
