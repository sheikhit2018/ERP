import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { taka } from '@/lib/format';
import { Head, useForm } from '@inertiajs/react';

type Props = { root: string; trialDays: number; plans: { id: number; name: string; price_minor: number; features: string | null }[] };
export default function Signup({ root, trialDays, plans }: Props) {
    const f = useForm({ company: '', subdomain: '', name: '', email: '', phone: '', password: '', password_confirmation: '', plan_id: plans[0]?.id ?? '' });
    const e = f.errors as Record<string, string>;
    const row = (k: keyof typeof f.data, label: string, type = 'text', extra: Record<string, unknown> = {}) => (
        <div><label className="text-sm font-medium text-slate-700">{label}</label><TextInput type={type} className="mt-1 block w-full" value={String(f.data[k])} onChange={(ev) => f.setData(k, ev.target.value as never)} required {...extra} /><InputError message={e[k]} className="mt-1" /></div>);
    return (
        <GuestLayout>
            <Head title="Start free trial" />
            <h1 className="text-lg font-semibold text-slate-900">Start your {trialDays}-day free trial</h1>
            <p className="mb-4 text-sm text-slate-600">Orders, courier, stock, factory, accounts — one system. No card needed.</p>
            <form className="space-y-3" onSubmit={(ev) => { ev.preventDefault(); f.post('/signup'); }}>
                {row('company', 'Company / brand name')}
                <div><label className="text-sm font-medium text-slate-700">Your Opryx address</label>
                    <div className="mt-1 flex items-center rounded-md shadow-sm ring-1 ring-slate-300"><input className="min-w-0 flex-1 rounded-l-md border-0 px-3 py-2 text-sm focus:ring-0" value={f.data.subdomain} required
                        onChange={(ev) => f.setData('subdomain', ev.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} placeholder="yourbrand" /><span className="px-3 text-sm text-slate-500">.{root}</span></div>
                    <InputError message={e.subdomain} className="mt-1" /></div>
                {row('name', 'Your name')}{row('email', 'Email (login)', 'email')}{row('phone', 'Mobile', 'tel')}
                {row('password', 'Password', 'password', { minLength: 8 })}{row('password_confirmation', 'Confirm password', 'password')}
                {plans.length > 0 && <div><label className="text-sm font-medium text-slate-700">Plan after trial</label>
                    <select className="mt-1 block w-full rounded-md border-slate-300 text-sm" value={f.data.plan_id} onChange={(ev) => f.setData('plan_id', Number(ev.target.value))}>{plans.map((p) => <option key={p.id} value={p.id}>{p.name} — {taka(p.price_minor)}/month</option>)}</select></div>}
                <PrimaryButton className="w-full justify-center" disabled={f.processing}>Create my company</PrimaryButton>
            </form>
        </GuestLayout>
    );
}
