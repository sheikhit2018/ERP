import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Couriers } from '@/Components/CourierStats';
import { Button, Card, Field, LinkButton, PageHeader, Select } from '@/Components/ui';
import { num } from '@/lib/format';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

/** Paste (or scan) the tracking IDs the courier reports as delivered — e.g. from its payment / COD statement. */
export default function Delivered({ couriers, flash }: PageProps<{ couriers: Couriers }>) {
    const [text, setText] = useState('');
    const f = useForm({ courier: 'steadfast' });
    const ids = useMemo(() => [...new Set(text.split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean))], [text]);
    const e = f.errors as Record<string, string>;
    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        f.transform((d) => ({ ...d, tracking_ids: ids }));
        f.post('/courier/delivered', { preserveScroll: true, onSuccess: () => setText('') });
    };
    return (
        <AuthenticatedLayout>
            <Head title="Mark delivered" />
            <PageHeader title="Mark parcels delivered" subtitle="Copy the delivered tracking IDs from the courier's panel or payment statement and paste them here (one per line, or separated by spaces / commas)."
                actions={<LinkButton variant="secondary" href="/courier">Courier hub</LinkButton>} />
            {flash.report && flash.report.length > 0 && (
                <div className="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <b>{flash.report.length} tracking IDs were skipped:</b>
                    <ul className="mt-1 list-disc pl-5">{flash.report.slice(0, 50).map((r) => <li key={r}>{r}</li>)}</ul>
                </div>
            )}
            <Card>
                <form onSubmit={submit} className="space-y-4 p-5">
                    <Field label="Courier" error={e.courier}><Select value={f.data.courier} onChange={(x) => f.setData('courier', x.target.value)} className="max-w-xs">
                        {Object.entries(couriers).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</Select></Field>
                    <Field label={`Delivered tracking IDs (${num(ids.length)})`} error={e.tracking_ids || e.inventory}>
                        <textarea rows={12} value={text} onChange={(x) => setText(x.target.value)} autoFocus
                            className="block w-full rounded-lg border-slate-300 font-mono text-sm focus:border-brand-500 focus:ring-brand-500" />
                    </Field>
                    <div className="flex justify-end"><Button disabled={f.processing || !ids.length}>Mark {num(ids.length)} delivered</Button></div>
                </form>
            </Card>
        </AuthenticatedLayout>
    );
}
