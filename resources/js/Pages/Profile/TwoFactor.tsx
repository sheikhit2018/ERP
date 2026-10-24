import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input, PageHeader } from '@/Components/ui';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import QRCode from 'qrcode';
import { useEffect, useState } from 'react';

type Props = { enabled: boolean; setup: { secret: string; uri: string } | null; recovery: string[] | null };

export default function TwoFactor({ enabled, setup, recovery }: PageProps<Props>) {
    const pw = useForm({ password: '' });
    const code = useForm({ code: '' });
    const [qr, setQr] = useState('');
    useEffect(() => { if (setup) QRCode.toDataURL(setup.uri, { margin: 1, width: 220 }).then(setQr); }, [setup?.uri]);
    return (
        <AuthenticatedLayout>
            <Head title="Two-factor login" />
            <PageHeader title="Two-factor login (2FA)" subtitle="After your password, Opryx also asks for a 6-digit code from your phone. Stops anyone who learns your password." />
            {recovery && (
                <Card className="mb-6" title="Your recovery codes — save them now">
                    <div className="p-5"><p className="mb-3 text-sm text-slate-600">Each code works once if you lose your phone. They will not be shown again.</p>
                        <div className="grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">{recovery.map((c) => <div key={c} className="rounded bg-slate-100 px-2 py-1">{c}</div>)}</div>
                        <Button className="mt-3" variant="secondary" onClick={() => navigator.clipboard?.writeText(recovery.join('\n'))}>Copy all</Button></div>
                </Card>
            )}
            <Card>
                <div className="max-w-xl space-y-4 p-5">
                    {enabled ? <>
                        <p className="text-sm font-medium text-emerald-700">✓ Two-factor login is ON for your account.</p>
                        <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); pw.delete('/profile/two-factor', { preserveScroll: true, onFinish: () => pw.reset() }); }}>
                            <Field label="Password to turn it off" error={pw.errors.password}><Input type="password" value={pw.data.password} onChange={(e) => pw.setData('password', e.target.value)} required /></Field>
                            <Button variant="danger" disabled={pw.processing}>Turn off</Button>
                        </form>
                    </> : setup ? <>
                        <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-700"><li>Install Google Authenticator / Microsoft Authenticator on your phone.</li><li>Scan this QR code (or type the key).</li><li>Type the 6-digit code it shows.</li></ol>
                        <div className="flex flex-wrap items-center gap-5">{qr && <img src={qr} alt="QR code" className="h-[220px] w-[220px] rounded ring-1 ring-slate-200" />}
                            <div className="text-xs text-slate-500">Key:<div className="mt-1 break-all font-mono text-sm text-slate-900">{setup.secret.match(/.{1,4}/g)?.join(' ')}</div></div></div>
                        <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); code.post('/profile/two-factor/confirm', { preserveScroll: true }); }}>
                            <Field label="6-digit code" error={code.errors.code}><Input inputMode="numeric" maxLength={6} value={code.data.code} onChange={(e) => code.setData('code', e.target.value.replace(/\D/g, ''))} autoFocus /></Field>
                            <Button disabled={code.processing || code.data.code.length !== 6}>Turn on</Button>
                        </form>
                    </> : <>
                        <p className="text-sm text-slate-600">Two-factor login is OFF. Recommended for owners and anyone who handles money.</p>
                        <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); pw.post('/profile/two-factor', { preserveScroll: true, onFinish: () => pw.reset() }); }}>
                            <Field label="Confirm your password" error={pw.errors.password}><Input type="password" value={pw.data.password} onChange={(e) => pw.setData('password', e.target.value)} required /></Field>
                            <Button disabled={pw.processing}>Set up</Button>
                        </form>
                    </>}
                </div>
            </Card>
        </AuthenticatedLayout>
    );
}
