// Money is stored as integer paisa (1 Taka = 100 paisa).
export const taka = (minor: number | null | undefined) =>
    '৳' + ((Number(minor) || 0) / 100).toLocaleString('en-BD', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

export const toMinor = (value: string | number) => Math.round((parseFloat(String(value)) || 0) * 100);

export const num = (n: number | null | undefined) => (Number(n) || 0).toLocaleString('en-BD');

export const dateTime = (iso?: string | null) =>
    iso ? new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

export function uuid(): string {
    if (typeof crypto !== 'undefined' && typeof (crypto as Crypto).randomUUID === 'function' && window.isSecureContext) return crypto.randomUUID();
    const b = (globalThis.crypto as Crypto).getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

export const STATUS_LABEL: Record<string, string> = {
    pending: 'Pending', confirmed: 'Confirmed', shipped: 'Shipped', delivered: 'Delivered',
    return_pending: 'Return pending', returned: 'Returned', return_damaged: 'Returned (damaged)', cancelled: 'Cancelled', lost: 'Lost by courier',
    passed: 'QC passed', damaged: 'Damaged', completed: 'Completed',
};
const STATUS_TONE: Record<string, string> = {
    pending: 'bg-amber-50 text-amber-800 ring-amber-200', confirmed: 'bg-sky-50 text-sky-800 ring-sky-200',
    shipped: 'bg-violet-50 text-violet-800 ring-violet-200', delivered: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    return_pending: 'bg-orange-50 text-orange-800 ring-orange-200', returned: 'bg-slate-100 text-slate-700 ring-slate-200',
    return_damaged: 'bg-rose-50 text-rose-800 ring-rose-200', cancelled: 'bg-slate-100 text-slate-500 ring-slate-200', lost: 'bg-rose-100 text-rose-900 ring-rose-300',
    passed: 'bg-emerald-50 text-emerald-800 ring-emerald-200', damaged: 'bg-rose-50 text-rose-800 ring-rose-200',
    completed: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
};
export const statusTone = (s: string) => STATUS_TONE[s] ?? 'bg-slate-100 text-slate-700 ring-slate-200';
