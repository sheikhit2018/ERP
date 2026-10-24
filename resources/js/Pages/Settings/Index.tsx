import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Field, Input, PageHeader, Select } from '@/Components/ui';
import { jsonApi } from '@/lib/jsonApi';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ReactNode, useState } from 'react';

type Props = { values: Record<string, string | null>; secrets: Record<string, boolean>; woo: { url: string; secret: string };
    urls: { shopify: string; steadfast: string }; steadfastToken: string };
const TABS: [string, string][] = [['couriers', 'Courier API'], ['sms', 'SMS'], ['store', 'Online store'], ['company', 'Company']];
const SMS_PROVIDERS: [string, string][] = [['bulksmsbd', 'BulkSMSBD'], ['smsnetbd', 'SMS.net.bd (Alpha SMS)'], ['greenweb', 'Greenweb'], ['sslwireless', 'SSL Wireless'], ['custom', 'Other (custom URL)']];
export const SMS_VARS = '{name} {order} {total} {cod} {courier} {tracking} {company}';

function Copy({ label, value }: { label: string; value: string }) {
    const [ok, setOk] = useState(false);
    return (
        <Field label={label}>
            <div className="flex gap-2"><Input readOnly value={value} className="font-mono text-xs" onFocus={(e) => e.target.select()} />
                <Button type="button" variant="secondary" onClick={() => { navigator.clipboard?.writeText(value); setOk(true); setTimeout(() => setOk(false), 1500); }}>{ok ? 'Copied' : 'Copy'}</Button></div>
        </Field>
    );
}
function Section({ title, desc, children }: { title: string; desc?: ReactNode; children: ReactNode }) {
    return <Card className="mb-6" title={title}><div className="space-y-4 p-5">{desc && <div className="text-sm text-slate-600">{desc}</div>}{children}</div></Card>;
}

export default function Settings({ values, secrets, woo, urls, steadfastToken }: PageProps<Props>) {
    const [tab, setTab] = useState(() => new URLSearchParams(location.search).get('tab') || 'couriers');
    const [v, setV] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(values).map(([k, x]) => [k, x ?? ''])));
    const [sec, setSec] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [test, setTest] = useState({ phone: '', msg: '' });
    const set = (k: string) => (e: { target: { value: string } }) => setV((o) => ({ ...o, [k]: e.target.value }));
    const on = (k: string) => v[k] === '1';
    const toggle = (k: string, label: string) => (
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={on(k)} onChange={(e) => setV((o) => ({ ...o, [k]: e.target.checked ? '1' : '0' }))} className="rounded border-slate-300 text-brand-600" /> {label}</label>
    );
    const secret = (k: string, label: string, hint?: string) => (
        <Field label={label} hint={hint}>
            <div className="flex gap-2">
                <Input type="password" autoComplete="new-password" value={sec[k] ?? ''} onChange={(e) => setSec((o) => ({ ...o, [k]: e.target.value }))}
                    placeholder={secrets[k] ? '•••••••• saved — type to replace' : 'Not set'} />
                {secrets[k] && <Button type="button" variant="ghost" onClick={() => setSec((o) => ({ ...o, [k]: '__clear__' }))}>Remove</Button>}
            </div>
        </Field>
    );
    const save = (extra: Record<string, unknown> = {}) => {
        setBusy(true);
        router.post('/settings', { values: v, secrets: sec, ...extra }, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => setSec({}) });
    };
    const sendTest = async () => {
        setTest((t) => ({ ...t, msg: 'Sending…' }));
        try { const r = await jsonApi<{ message: string }>('/settings/sms-test', { phone: test.phone }); setTest((t) => ({ ...t, msg: r.message })); }
        catch (e) { setTest((t) => ({ ...t, msg: e instanceof Error ? e.message : 'Failed' })); }
    };
    const testCourier = async (c: string) => {
        try { const r = await jsonApi<{ message: string }>('/settings/courier-test', { courier: c }); alert(r.message); }
        catch (e) { alert(e instanceof Error ? e.message : 'Failed'); }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Settings" />
            <PageHeader title="Settings & integrations" subtitle="Connect your couriers, SMS gateway and online store. Keys are stored encrypted and are never shown again after saving."
                actions={<Button onClick={() => save()} disabled={busy}>{busy ? 'Saving…' : 'Save settings'}</Button>} />
            <div className="mb-5 inline-flex flex-wrap rounded-lg bg-slate-100 p-1 text-sm">
                {TABS.map(([k, l]) => <button key={k} onClick={() => setTab(k)} className={`rounded-md px-3 py-1.5 ${tab === k ? 'bg-white font-semibold shadow-sm' : 'text-slate-600'}`}>{l}</button>)}
            </div>

            {tab === 'couriers' && <>
                <Section title="Steadfast" desc={<>From the Steadfast merchant panel → <b>API</b>: copy the API key and secret key. Then add the webhook below under <b>Webhook</b> so delivery status updates come in automatically.</>}>
                    {toggle('courier.steadfast.enabled', 'Use Steadfast API (book parcels from Opryx)')}
                    <div className="grid gap-4 sm:grid-cols-2">{secret('courier.steadfast.api_key', 'API key')}{secret('courier.steadfast.secret_key', 'Secret key')}</div>
                    <div className="grid gap-4 sm:grid-cols-2"><Copy label="Webhook callback URL" value={urls.steadfast} /><Copy label="Webhook auth token (Bearer)" value={steadfastToken} /></div>
                    <div className="flex gap-3"><Button type="button" variant="secondary" onClick={() => testCourier('steadfast')}>Test connection</Button>
                        <button type="button" className="text-sm text-slate-500 hover:underline" onClick={() => save({ regenerate_steadfast: true })}>New webhook token</button></div>
                </Section>
                <Section title="Pathao" desc="From the Pathao merchant panel → Developers API: client ID and secret; plus your Pathao login email / password and the store ID to book from.">
                    {toggle('courier.pathao.enabled', 'Use Pathao API')}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Client ID"><Input value={v['courier.pathao.client_id']} onChange={set('courier.pathao.client_id')} /></Field>
                        {secret('courier.pathao.client_secret', 'Client secret')}
                        <Field label="Merchant login email"><Input value={v['courier.pathao.username']} onChange={set('courier.pathao.username')} /></Field>
                        {secret('courier.pathao.password', 'Merchant password')}
                        <Field label="Store ID" hint="Pathao merchant panel → Stores"><Input value={v['courier.pathao.store_id']} onChange={set('courier.pathao.store_id')} /></Field>
                    </div>
                    {toggle('courier.pathao.sandbox', 'Sandbox (test) mode')}
                    <Button type="button" variant="secondary" onClick={() => testCourier('pathao')}>Test connection</Button>
                </Section>
                <Section title="RedX" desc="From the RedX merchant panel → Developer API: create an API access token.">
                    {toggle('courier.redx.enabled', 'Use RedX API')}
                    <div className="grid gap-4 sm:grid-cols-2">{secret('courier.redx.token', 'API access token')}
                        <Field label="Pickup store ID (optional)"><Input value={v['courier.redx.pickup_store_id']} onChange={set('courier.redx.pickup_store_id')} /></Field></div>
                    {toggle('courier.redx.sandbox', 'Sandbox (test) mode')}
                    <Button type="button" variant="secondary" onClick={() => testCourier('redx')}>Test connection</Button>
                </Section>
                <Section title="Status updates">{toggle('courier.auto_sync', 'Check parcel status with the courier automatically (every hour, needs the cron job — see Help)')}</Section>
            </>}

            {tab === 'sms' && <>
                <Section title="SMS gateway" desc="Send customers an SMS when their order is confirmed, shipped and delivered. Use the API details from your SMS provider.">
                    {toggle('sms.enabled', 'Send SMS to customers')}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Provider"><Select value={v['sms.provider'] || 'bulksmsbd'} onChange={set('sms.provider')}>{SMS_PROVIDERS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Select></Field>
                        {secret('sms.api_key', 'API key / token')}
                        <Field label="Sender ID / masking" hint="Optional — leave empty for non-masking"><Input value={v['sms.sender_id']} onChange={set('sms.sender_id')} /></Field>
                        {v['sms.provider'] === 'custom' && <Field label="Custom GET URL" hint="Use {phone} and {message}, e.g. https://api.example.com/send?key=XXX&to={phone}&text={message}"><Input value={v['sms.url']} onChange={set('sms.url')} /></Field>}
                    </div>
                </Section>
                <Section title="Messages" desc={<>You can use: <code className="text-xs">{SMS_VARS}</code>. Keep each message short (one SMS = 160 English or 70 Bangla characters).</>}>
                    {([['confirmed', 'Order confirmed'], ['shipped', 'Handed to courier'], ['delivered', 'Delivered']] as const).map(([k, l]) => (
                        <div key={k} className="space-y-2">
                            {toggle(`sms.on_${k}`, `Send when: ${l}`)}
                            <textarea rows={2} value={v[`sms.tpl_${k}`]} onChange={set(`sms.tpl_${k}`)} className="block w-full rounded-lg border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500"
                                placeholder={k === 'confirmed' ? 'Dear {name}, your order #{order} ({total} Tk) is confirmed. - {company}' : k === 'shipped' ? 'Your order #{order} is with {courier}. Tracking: {tracking}. Please keep {cod} Tk ready. - {company}' : 'Order #{order} delivered. Thank you for shopping with {company}!'} />
                        </div>
                    ))}
                </Section>
                <Section title="Send a test SMS" desc="Save settings first.">
                    <div className="flex flex-wrap gap-2"><Input value={test.phone} onChange={(e) => setTest({ ...test, phone: e.target.value })} placeholder="01XXXXXXXXX" className="max-w-xs" />
                        <Button type="button" variant="secondary" onClick={sendTest} disabled={!test.phone}>Send test</Button></div>
                    {test.msg && <p className="text-sm text-slate-700">{test.msg}</p>}
                </Section>
            </>}

            {tab === 'store' && <>
                <Section title="Orders from your website">
                    <Field label="New web orders arrive as"><Select value={v['orders.web_status'] || 'pending'} onChange={set('orders.web_status')} className="max-w-sm">
                        <option value="pending">Pending — call the customer to confirm first</option><option value="confirmed">Confirmed — ready to pack</option></Select></Field>
                    <p className="text-xs text-slate-500">Products are matched by SKU or barcode. If a product is not found, the order waits in Orders → Import → Inbox; fix the SKU and press Retry.</p>
                </Section>
                <Section title="WooCommerce" desc={<>WordPress → WooCommerce → Settings → Advanced → <b>Webhooks</b> → Add webhook: Status <b>Active</b>, Topic <b>Order created</b>, API version <b>WP REST API v3</b>, and paste the URL and secret below.</>}>
                    <div className="grid gap-4 sm:grid-cols-2"><Copy label="Delivery URL" value={woo.url} /><Copy label="Secret" value={woo.secret} /></div>
                    <button type="button" className="text-sm text-slate-500 hover:underline" onClick={() => window.confirm('Make a new secret? The old one stops working.') && save({ regenerate_woo: true })}>Make a new secret</button>
                </Section>
                <Section title="Shopify" desc={<>Shopify admin → Settings → Notifications → <b>Webhooks</b> → Create webhook: Event <b>Order creation</b>, Format <b>JSON</b>, URL below. Copy the signing secret shown on that page into the field.</>}>
                    <Copy label="Webhook URL" value={urls.shopify} />{secret('store.shopify_secret', 'Webhook signing secret')}
                </Section>
            </>}

            {tab === 'company' && (
                <Section title="Invoice">
                    <Field label="Note printed on invoices" hint="e.g. return policy, hotline"><Input value={v['company.invoice_note']} onChange={set('company.invoice_note')} /></Field>
                </Section>
            )}
        </AuthenticatedLayout>
    );
}
