import { StatusBadge } from '@/Components/ui';
import { num } from '@/lib/format';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export type Risk = { orders: number; delivered: number; returned: number; cancelled: number; open: number; success_rate: number | null;
    level: 'new' | 'good' | 'watch' | 'risky'; last_order_at?: string | null; recent?: { id: number; status: string; name: string }[] };

const TONE = { new: 'bg-slate-100 text-slate-700 ring-slate-200', good: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    watch: 'bg-amber-50 text-amber-900 ring-amber-200', risky: 'bg-rose-50 text-rose-800 ring-rose-300' } as const;
const LABEL = { new: 'New customer', good: 'Good customer', watch: 'Check before shipping', risky: 'High return risk' } as const;

/** Compact badge: "82% · 9/11" */
export function RiskBadge({ r }: { r: Risk | null | undefined }) {
    if (!r) return null;
    const t = r.success_rate === null ? 'New' : `${r.success_rate}% · ${r.delivered}/${r.delivered + r.returned}`;
    return <span title={`${LABEL[r.level]} — delivered ${r.delivered}, returned ${r.returned}, cancelled ${r.cancelled}`}
        className={`inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${TONE[r.level]}`}>{t}</span>;
}

/** Full card: history of this phone number with this company. */
export function RiskCard({ r, compact = false }: { r: Risk; compact?: boolean }) {
    return (
        <div className={`rounded-lg px-3 py-2 text-sm ring-1 ring-inset ${TONE[r.level]}`}>
            <div className="flex items-center justify-between gap-2">
                <b>{LABEL[r.level]}</b>
                {r.success_rate !== null && <span className="text-lg font-bold tabular-nums">{r.success_rate}%</span>}
            </div>
            {r.orders === 0 ? <div className="text-xs">No earlier orders with this phone number.</div> : (
                <div className="text-xs">{num(r.orders)} earlier orders · <b>{num(r.delivered)}</b> delivered · <b>{num(r.returned)}</b> returned{r.cancelled ? ` · ${r.cancelled} cancelled` : ''}{r.open ? ` · ${r.open} open` : ''}</div>
            )}
            {r.level === 'risky' && <div className="mt-1 text-xs font-semibold">Ask for advance payment / delivery charge before shipping.</div>}
            {!compact && r.recent && r.recent.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">{r.recent.slice(0, 6).map((o) => <Link key={o.id} href={`/orders/${o.id}`} className="inline-flex items-center gap-1 text-xs underline">#{o.id} <StatusBadge status={o.status} /></Link>)}</div>
            )}
        </div>
    );
}

/** Looks the phone up after typing stops (needs at least 11 digits for BD mobiles). */
export function useCustomerRisk(phone: string, except?: number) {
    const [risk, setRisk] = useState<Risk | null>(null);
    useEffect(() => {
        const digits = phone.replace(/\D/g, '');
        if (digits.length < 11) { setRisk(null); return; }
        const t = setTimeout(async () => {
            try {
                const r = await fetch(`/customers/risk?phone=${encodeURIComponent(phone)}${except ? `&except=${except}` : ''}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (r.ok) setRisk(await r.json());
            } catch { /* offline: no badge */ }
        }, 350);
        return () => clearTimeout(t);
    }, [phone, except]);
    return risk;
}
