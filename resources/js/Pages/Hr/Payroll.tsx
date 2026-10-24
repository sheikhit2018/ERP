import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select, td, th } from '@/Components/ui';
import { num, taka, toMinor } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type L = { employee_id: number; name: string; designation: string | null; pay_type: string; days_worked: number; days_absent: number; pieces: number;
    base_minor: number; piece_minor: number; bonus_minor: number; deduction_minor: number; advance_minor: number; net_minor: number };
type Props = { month: string; paid: boolean; paid_on: string | null; account: string | null; lines: L[]; accounts: { id: number; name: string }[]; runs: { month: string; paid_on: string }[] };

export default function Payroll({ month, paid, paid_on, account, lines, accounts, runs, tenant }: PageProps<Props>) {
    const [adj, setAdj] = useState<Record<number, { bonus: string; ded: string }>>({});
    const [acc, setAcc] = useState(String(accounts.find((a) => /cash/i.test(a.name))?.id ?? accounts[0]?.id ?? ''));
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [busy, setBusy] = useState(false);
    const [slip, setSlip] = useState<L | null>(null);
    const net = (l: L) => paid ? l.net_minor : l.base_minor + l.piece_minor + toMinor(adj[l.employee_id]?.bonus ?? '') - toMinor(adj[l.employee_id]?.ded ?? '') - l.advance_minor;
    const total = lines.reduce((s, l) => s + Math.max(0, net(l)), 0);
    const pay = () => { if (!window.confirm(`Pay ${taka(total)} salaries for ${month} from the selected account?`)) return;
        router.post('/hr/payroll', { month, money_account_id: Number(acc), paid_on: date,
            adjust: Object.fromEntries(Object.entries(adj).map(([id, v]) => [id, { bonus_minor: toMinor(v.bonus), deduction_minor: toMinor(v.ded) }])) },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) }); };
    const setA = (id: number, k: 'bonus' | 'ded', v: string) => setAdj((a) => ({ ...a, [id]: { bonus: a[id]?.bonus ?? '', ded: a[id]?.ded ?? '', [k]: v } }));

    return (
        <AuthenticatedLayout>
            <Head title={`Payroll ${month}`} />
            <div className="print:hidden">
                <PageHeader title={`Payroll · ${month}`} subtitle={paid ? `Paid on ${paid_on} from ${account}. Each salary is in the cash book.` : 'Calculated from attendance, production log and advances. Check, add bonus / deduction, then pay.'}
                    actions={<LinkButton variant="secondary" href="/hr/employees">Staff</LinkButton>} />
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <Input type="month" className="!w-44" value={month} onChange={(e) => e.target.value && router.get('/hr/payroll', { month: e.target.value })} />
                    {runs.length > 0 && <span className="text-xs text-slate-500">Paid: {runs.map((r) => <button key={r.month} className="mr-2 text-brand-700" onClick={() => router.get('/hr/payroll', { month: r.month })}>{r.month}</button>)}</span>}
                </div>
                <Card>
                    {lines.length === 0 ? <Empty>No staff to pay for this month.</Empty> : (
                        <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Employee</th><th className={`${th} text-right`}>Days</th><th className={`${th} text-right`}>Pieces</th><th className={`${th} text-right`}>Salary / wage</th>
                                <th className={`${th} text-right`}>Piece pay</th><th className={`${th} text-right`}>Bonus</th><th className={`${th} text-right`}>Deduction</th><th className={`${th} text-right`}>Advance</th><th className={`${th} text-right`}>Net pay</th><th className={th} /></tr></thead>
                            <tbody className="divide-y divide-slate-100">{lines.map((l) => (
                                <tr key={l.employee_id}>
                                    <td className={td}><div className="font-medium text-slate-900">{l.name}</div><div className="text-xs capitalize text-slate-500">{l.pay_type}{l.designation ? ` · ${l.designation}` : ''}</div></td>
                                    <td className={`${td} text-right tabular-nums text-xs`}>{Number(l.days_worked)}{Number(l.days_absent) > 0 && <div className="text-rose-600">−{Number(l.days_absent)} absent</div>}</td>
                                    <td className={`${td} text-right tabular-nums`}>{l.pieces ? num(l.pieces) : '—'}</td>
                                    <td className={`${td} text-right tabular-nums`}>{taka(l.base_minor)}</td>
                                    <td className={`${td} text-right tabular-nums`}>{taka(l.piece_minor)}</td>
                                    <td className={`${td} text-right`}>{paid ? taka(l.bonus_minor) : <Input type="number" min="0" step="0.01" className="!w-24 text-right" value={adj[l.employee_id]?.bonus ?? ''} onChange={(e) => setA(l.employee_id, 'bonus', e.target.value)} />}</td>
                                    <td className={`${td} text-right`}>{paid ? taka(l.deduction_minor) : <Input type="number" min="0" step="0.01" className="!w-24 text-right" value={adj[l.employee_id]?.ded ?? ''} onChange={(e) => setA(l.employee_id, 'ded', e.target.value)} />}</td>
                                    <td className={`${td} text-right tabular-nums text-amber-700`}>{l.advance_minor ? `−${taka(l.advance_minor)}` : '—'}</td>
                                    <td className={`${td} text-right font-semibold tabular-nums ${net(l) < 0 ? 'text-rose-600' : 'text-slate-900'}`}>{taka(net(l))}</td>
                                    <td className={td}>{paid && <Button variant="ghost" className="!px-2 !py-1 text-xs" onClick={() => { setSlip(l); setTimeout(() => window.print(), 50); }}>Payslip</Button>}</td>
                                </tr>))}</tbody>
                            <tfoot><tr><td className={`${td} font-semibold`} colSpan={8}>Total payout</td><td className={`${td} text-right text-base font-bold tabular-nums`}>{taka(total)}</td><td /></tr></tfoot>
                        </table></div>
                    )}
                </Card>
                {!paid && lines.length > 0 && (
                    <div className="mt-4 flex flex-wrap items-end justify-end gap-3">
                        <Field label="Pay from"><Select value={acc} onChange={(e) => setAcc(e.target.value)}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
                        <Field label="Payment date"><Input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
                        <Button disabled={busy || !acc} onClick={pay}>Pay {taka(total)}</Button>
                    </div>
                )}
                <p className="mt-3 text-xs text-slate-500">Monthly: salary × (days in month − absent) ÷ days in month (leave is paid, half day = ½; no attendance marked = full month). Daily: wage × days present. Piece: production log. Negative net (advance larger than pay) carries nothing forward — adjust with bonus/deduction.</p>
            </div>
            {slip && (
                <div className="hidden print:block text-sm">
                    <div className="flex justify-between border-b border-black pb-2"><div><div className="text-lg font-bold">{tenant?.name}</div><div>Payslip · {month}</div></div><div className="text-right">Paid {paid_on}<br />{account}</div></div>
                    <div className="my-3 font-semibold">{slip.name}{slip.designation ? ` — ${slip.designation}` : ''}</div>
                    <table className="w-full"><tbody>
                        {([['Days worked', String(Number(slip.days_worked))], ['Absent', String(Number(slip.days_absent))], ['Pieces', num(slip.pieces)], ['Salary / wage', taka(slip.base_minor)], ['Piece pay', taka(slip.piece_minor)],
                            ['Bonus', taka(slip.bonus_minor)], ['Deduction', `−${taka(slip.deduction_minor)}`], ['Advance', `−${taka(slip.advance_minor)}`]] as const).map(([k, v]) => <tr key={k}><td className="py-1">{k}</td><td className="py-1 text-right">{v}</td></tr>)}
                        <tr className="border-t border-black font-bold"><td className="py-2">Net paid</td><td className="py-2 text-right">{taka(slip.net_minor)}</td></tr>
                    </tbody></table>
                    <div className="mt-16 grid grid-cols-2 gap-16"><div className="border-t border-black pt-1">Employer</div><div className="border-t border-black pt-1">Received by (signature)</div></div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
