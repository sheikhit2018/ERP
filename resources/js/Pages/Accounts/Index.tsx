import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Pagination, Select, td, th } from '@/Components/ui';
import { num, taka, toMinor } from '@/lib/format';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Acc = { id: number; name: string; type: string; active: boolean; opening_minor: number; balance_minor: number };
type Entry = { id: number; entry_date: string; direction: 'in' | 'out'; amount_minor: number; category: string; reference: string | null; note: string | null;
    source_type: string | null; created_by: number; account: { id: number; name: string } };
type PL = { profit: Record<string, number>; expenses: { category: string; label: string; total: number }[]; income: { category: string; label: string; total: number }[];
    expense_total: number; income_total: number; net: number };
type Props = { tab: string; filters: { from: string; to: string; account: number | null; category: string }; accounts: Acc[]; entries: Paginated<Entry>;
    totals: { in: number; out: number }; expenseCats: Record<string, string>; incomeCats: Record<string, string>; pl: PL | null; users: Record<string, string> };

const today = () => new Date().toISOString().slice(0, 10);

function EntryForm({ accounts, expenseCats, incomeCats }: Pick<Props, 'accounts' | 'expenseCats' | 'incomeCats'>) {
    const f = useForm({ direction: 'out', money_account_id: String(accounts[0]?.id ?? ''), category: 'rent', amount: '', entry_date: today(), reference: '', note: '' });
    const e = f.errors as Record<string, string>;
    const cats = f.data.direction === 'out' ? expenseCats : incomeCats;
    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform((d) => ({ ...d, money_account_id: Number(d.money_account_id), amount_minor: toMinor(d.amount) }));
        f.post('/accounts/entries', { preserveScroll: true, onSuccess: () => f.reset('amount', 'reference', 'note') });
    };
    return (
        <form onSubmit={submit} className="grid gap-3 p-5 sm:grid-cols-4">
            <Field label="Type"><Select value={f.data.direction} onChange={(x) => f.setData((d) => ({ ...d, direction: x.target.value, category: x.target.value === 'out' ? 'rent' : 'other_income' }))}>
                <option value="out">Expense (money out)</option><option value="in">Income (money in)</option></Select></Field>
            <Field label="Category" error={e.category}><Select value={f.data.category} onChange={(x) => f.setData('category', x.target.value)}>
                {Object.entries(cats).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
            <Field label="Account" error={e.money_account_id}><Select value={f.data.money_account_id} onChange={(x) => f.setData('money_account_id', x.target.value)}>
                {accounts.filter((a) => a.active).map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
            <Field label="Amount (৳)" error={e.amount_minor}><Input type="number" step="0.01" min="0" value={f.data.amount} onChange={(x) => f.setData('amount', x.target.value)} required /></Field>
            <Field label="Date"><Input type="date" value={f.data.entry_date} onChange={(x) => f.setData('entry_date', x.target.value)} /></Field>
            <Field label="Reference"><Input value={f.data.reference} onChange={(x) => f.setData('reference', x.target.value)} placeholder="Voucher / trx id" /></Field>
            <Field label="Note"><Input value={f.data.note} onChange={(x) => f.setData('note', x.target.value)} /></Field>
            <div className="flex items-end"><Button className="w-full" disabled={f.processing}>Save</Button></div>
        </form>
    );
}

function TransferForm({ accounts }: { accounts: Acc[] }) {
    const act = accounts.filter((a) => a.active);
    const f = useForm({ from: String(act[0]?.id ?? ''), to: String(act[1]?.id ?? ''), amount: '', entry_date: today(), note: '' });
    return (
        <form className="grid gap-3 p-5 sm:grid-cols-5" onSubmit={(ev) => { ev.preventDefault(); f.transform((d) => ({ ...d, from: Number(d.from), to: Number(d.to), amount_minor: toMinor(d.amount) })); f.post('/accounts/transfer', { preserveScroll: true, onSuccess: () => f.reset('amount', 'note') }); }}>
            <Field label="From"><Select value={f.data.from} onChange={(x) => f.setData('from', x.target.value)}>{act.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
            <Field label="To"><Select value={f.data.to} onChange={(x) => f.setData('to', x.target.value)}>{act.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
            <Field label="Amount (৳)" error={(f.errors as any).amount_minor}><Input type="number" step="0.01" min="0" value={f.data.amount} onChange={(x) => f.setData('amount', x.target.value)} required /></Field>
            <Field label="Date"><Input type="date" value={f.data.entry_date} onChange={(x) => f.setData('entry_date', x.target.value)} /></Field>
            <div className="flex items-end"><Button variant="secondary" className="w-full" disabled={f.processing}>Transfer</Button></div>
        </form>
    );
}

function AccountRow({ a }: { a?: Acc }) {
    const f = useForm({ id: a?.id ?? null, name: a?.name ?? '', type: a?.type ?? 'bank', opening: a ? String(a.opening_minor / 100) : '0', active: a?.active ?? true });
    return (
        <form className="grid items-end gap-2 border-b border-slate-100 px-5 py-3 sm:grid-cols-[2fr_1fr_1fr_auto_auto]" onSubmit={(ev) => { ev.preventDefault(); f.transform((d) => ({ id: d.id, name: d.name, type: d.type, opening_minor: toMinor(d.opening), active: d.active })); f.post('/accounts/accounts', { preserveScroll: true, onSuccess: () => { if (!a) f.reset(); } }); }}>
            <Field label={a ? 'Account' : 'New account'}><Input value={f.data.name} onChange={(x) => f.setData('name', x.target.value)} required placeholder="e.g. DBBL Bank" /></Field>
            <Field label="Type"><Select value={f.data.type} onChange={(x) => f.setData('type', x.target.value)}><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile banking</option></Select></Field>
            <Field label="Opening balance ৳"><Input type="number" step="0.01" value={f.data.opening} onChange={(x) => f.setData('opening', x.target.value)} /></Field>
            <label className="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" checked={f.data.active} onChange={(x) => f.setData('active', x.target.checked)} /> Active</label>
            <Button variant="secondary" disabled={f.processing}>{a ? 'Save' : 'Add'}</Button>
        </form>
    );
}

export default function AccountsPage({ tab, filters, accounts, entries, totals, expenseCats, incomeCats, pl, users }: PageProps<Props>) {
    const [f, setF] = useState({ from: filters.from, to: filters.to, account: filters.account ? String(filters.account) : '', category: filters.category });
    const go = (t: string) => router.get('/accounts', { tab: t, ...f }, { preserveState: true });
    const label = (c: string) => expenseCats[c] ?? incomeCats[c] ?? (c === 'transfer' ? 'Transfer' : c);
    const total = accounts.filter((a) => a.active).reduce((s, a) => s + a.balance_minor, 0);
    return (
        <AuthenticatedLayout>
            <Head title="Accounts" />
            <PageHeader title="Accounts" subtitle="Cash book for cash, bank and mobile banking. COD payments, advances, supplier payments and salaries are posted automatically." />
            <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                {accounts.filter((a) => a.active).map((a) => (
                    <button key={a.id} onClick={() => { setF({ ...f, account: String(a.id) }); router.get('/accounts', { tab: 'book', ...f, account: a.id }, { preserveState: true }); }}
                        className={`rounded-xl border bg-white p-4 text-left shadow-sm ${String(a.id) === f.account ? 'border-brand-400' : 'border-slate-200'}`}>
                        <div className="text-xs text-slate-500">{a.name}</div><div className={`mt-1 text-xl font-semibold tabular-nums ${a.balance_minor < 0 ? 'text-rose-600' : ''}`}>{taka(a.balance_minor)}</div>
                    </button>
                ))}
                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4"><div className="text-xs text-slate-500">All accounts</div><div className="mt-1 text-xl font-bold tabular-nums">{taka(total)}</div></div>
            </div>
            <div className="mb-4 inline-flex rounded-lg bg-slate-100 p-1 text-sm">
                {([['book', 'Cash book'], ['pl', 'Profit & loss'], ['accounts', 'Accounts']] as const).map(([k, l]) => <button key={k} onClick={() => go(k)} className={`rounded-md px-3 py-1.5 ${tab === k ? 'bg-white font-semibold shadow-sm' : 'text-slate-600'}`}>{l}</button>)}
            </div>
            {tab !== 'accounts' && (
                <form onSubmit={(e) => { e.preventDefault(); go(tab); }} className="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <Field label="From"><Input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></Field>
                    <Field label="To"><Input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></Field>
                    {tab === 'book' && <>
                        <Field label="Account"><Select value={f.account} onChange={(e) => setF({ ...f, account: e.target.value })}><option value="">All</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
                        <Field label="Category"><Select value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })}><option value="">All</option>
                            {Object.entries({ ...incomeCats, ...expenseCats, transfer: 'Transfer' }).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                    </>}
                    <Button variant="secondary">Apply</Button>
                </form>
            )}

            {tab === 'book' && <>
                <Card className="mb-6" title="Add income or expense"><EntryForm accounts={accounts} expenseCats={expenseCats} incomeCats={incomeCats} /></Card>
                <Card className="mb-6" title="Move money between accounts"><TransferForm accounts={accounts} /></Card>
                <Card title={`Entries · in ${taka(totals.in)} · out ${taka(totals.out)}`}>
                    {entries.data.length === 0 ? <Empty>No entries in this period.</Empty> : (
                        <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                            <thead><tr><th className={th}>Date</th><th className={th}>Account</th><th className={th}>Category</th><th className={th}>Details</th><th className={`${th} text-right`}>In</th><th className={`${th} text-right`}>Out</th><th className={th} /></tr></thead>
                            <tbody className="divide-y divide-slate-100">{entries.data.map((x) => (
                                <tr key={x.id}><td className={`${td} text-xs`}>{String(x.entry_date).slice(0, 10)}</td><td className={td}>{x.account?.name}</td>
                                    <td className={td}>{label(x.category)}{x.source_type && <span className="ml-1 rounded bg-slate-100 px-1 text-[10px] text-slate-500">auto</span>}</td>
                                    <td className={`${td} text-xs`}>{x.note}{x.reference && <div className="font-mono text-slate-500">{x.reference}</div>}<div className="text-slate-400">{users[x.created_by]}</div></td>
                                    <td className={`${td} text-right tabular-nums text-emerald-700`}>{x.direction === 'in' ? taka(x.amount_minor) : ''}</td>
                                    <td className={`${td} text-right tabular-nums text-rose-600`}>{x.direction === 'out' ? taka(x.amount_minor) : ''}</td>
                                    <td className={`${td} text-right`}>{!x.source_type && <button className="text-xs text-slate-400 hover:text-rose-600" onClick={() => window.confirm('Delete this entry?') && router.delete(`/accounts/entries/${x.id}`, { preserveScroll: true })}>Delete</button>}</td></tr>))}</tbody>
                        </table></div>
                    )}
                </Card>
                <Pagination links={entries.links} />
            </>}

            {tab === 'pl' && pl && (
                <Card className="max-w-2xl" title={`Profit & loss · ${filters.from} to ${filters.to}`}>
                    <dl className="divide-y divide-slate-100 text-sm">
                        <div className="flex justify-between px-5 py-2"><dt>Profit from delivered orders <span className="text-xs text-slate-400">(after courier charges & return losses)</span></dt><dd className="tabular-nums">{taka(pl.profit.net_after_losses)}</dd></div>
                        {pl.income.map((i) => <div key={i.category} className="flex justify-between px-5 py-2"><dt>+ {i.label}</dt><dd className="tabular-nums">{taka(i.total)}</dd></div>)}
                        {pl.expenses.map((x) => <div key={x.category} className="flex justify-between px-5 py-2"><dt>− {x.label}</dt><dd className="tabular-nums text-rose-600">{taka(-x.total)}</dd></div>)}
                        <div className="flex justify-between bg-slate-50 px-5 py-3 text-base font-bold"><dt>Net profit</dt><dd className={`tabular-nums ${pl.net < 0 ? 'text-rose-600' : 'text-emerald-700'}`}>{taka(pl.net)}</dd></div>
                    </dl>
                    <p className="px-5 pb-4 text-xs text-slate-500">Supplier payments, owner drawings, transfers and COD receipts move money but are not expenses / income here — product cost is counted when the order is delivered.</p>
                </Card>
            )}

            {tab === 'accounts' && <Card title="Money accounts">{accounts.map((a) => <AccountRow key={a.id} a={a} />)}<AccountRow /></Card>}
        </AuthenticatedLayout>
    );
}
