import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const f = useForm({ code: '', recovery_code: '' });
    return (
        <GuestLayout>
            <Head title="Two-factor login" />
            <h1 className="mb-1 text-lg font-semibold text-slate-900">Enter your login code</h1>
            <p className="mb-4 text-sm text-slate-600">{recovery ? 'Type one of your saved recovery codes.' : 'Open your authenticator app and type the 6-digit code for Opryx.'}</p>
            <form onSubmit={(e) => { e.preventDefault(); f.post('/two-factor-challenge', { onFinish: () => f.reset() }); }}>
                {recovery
                    ? <TextInput className="block w-full" value={f.data.recovery_code} onChange={(e) => f.setData('recovery_code', e.target.value)} autoFocus autoComplete="one-time-code" />
                    : <TextInput className="block w-full text-center text-2xl tracking-[0.4em]" inputMode="numeric" maxLength={6} value={f.data.code} onChange={(e) => f.setData('code', e.target.value.replace(/\D/g, ''))} autoFocus autoComplete="one-time-code" />}
                <InputError message={f.errors.code} className="mt-2" />
                <div className="mt-4 flex items-center justify-between">
                    <button type="button" className="text-sm text-slate-600 underline" onClick={() => setRecovery(!recovery)}>{recovery ? 'Use authenticator code' : 'Lost phone? Use a recovery code'}</button>
                    <PrimaryButton disabled={f.processing}>Verify</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
