import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input, PageHeader, Select, td, th } from '@/Components/ui';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Member = { id: number; name: string; email: string; role: string; active: boolean };
const HELP: Record<string, string> = {
    owner: 'Everything, incl. team', manager: 'Everything except team', sales: 'Create/confirm/cancel orders',
    warehouse: 'Stock, courier handover, returns', manufacturer: 'Materials, BOM, production', qc: 'Return scanning & QC',
};

export default function Team({ members, roles, auth }: PageProps<{ members: Member[]; roles: string[] }>) {
    const f = useForm({ name: '', email: '', password: '', role: 'sales' });
    const e = f.errors as Record<string, string>;
    const add = (ev: FormEvent) => { ev.preventDefault(); f.post('/team', { preserveScroll: true, onSuccess: () => f.reset() }); };
    const update = (m: Member, patch: Partial<Member>) => router.put(`/team/${m.id}`, { role: patch.role ?? m.role, active: patch.active ?? m.active }, { preserveScroll: true });
    return (
        <AuthenticatedLayout>
            <Head title="Team & roles" />
            <PageHeader title="Team & roles" subtitle="Give each staff member their own login. Roles decide which screens they see." />
            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-100">
                        <thead className="bg-slate-50/60"><tr><th className={th}>Person</th><th className={th}>Role</th><th className={th}>Access</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {members.map((m) => (
                                <tr key={m.id} className={m.active ? '' : 'opacity-50'}>
                                    <td className={td}><div className="font-medium">{m.name}</div><div className="text-xs text-slate-500">{m.email}</div></td>
                                    <td className={td}><Select value={m.role} onChange={(x) => update(m, { role: x.target.value })} disabled={m.email === auth.user.email} className="w-40">{roles.map((r) => <option key={r} value={r}>{r}</option>)}</Select>
                                        <div className="mt-1 text-xs text-slate-400">{HELP[m.role]}</div></td>
                                    <td className={td}>{m.email !== auth.user.email && <Button variant="secondary" onClick={() => update(m, { active: !m.active })}>{m.active ? 'Disable' : 'Enable'}</Button>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table></div>
                </Card>
                <Card title="Add team member">
                    <form onSubmit={add} className="space-y-4 p-5">
                        <Field label="Name" error={e.name}><Input value={f.data.name} onChange={(x) => f.setData('name', x.target.value)} required /></Field>
                        <Field label="Email (login)" error={e.email}><Input type="email" value={f.data.email} onChange={(x) => f.setData('email', x.target.value)} required /></Field>
                        <Field label="Password" error={e.password} hint="Min 8 characters. Leave empty if this email already has an Opryx login."><Input type="text" value={f.data.password} onChange={(x) => f.setData('password', x.target.value)} /></Field>
                        <Field label="Role" error={e.role}><Select value={f.data.role} onChange={(x) => f.setData('role', x.target.value)}>{roles.map((r) => <option key={r} value={r}>{r} — {HELP[r]}</option>)}</Select></Field>
                        <Button className="w-full" disabled={f.processing}>Add member</Button>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
