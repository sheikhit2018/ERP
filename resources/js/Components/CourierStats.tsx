import { num } from '@/lib/format';

export type Stats = {
    sent_parcels: number; sent_pieces: number; delivered_parcels: number; delivered_pieces: number;
    returned_parcels: number; returned_pieces: number; lost_parcels: number; lost_pieces: number;
    open_parcels: number; open_pieces: number; overdue_parcels: number; overdue_pieces: number;
};
export type Couriers = Record<string, string>;

/** Parcels / pieces tiles: Sent = Delivered + Returned + Lost + With courier. */
export function StatTiles({ s, days }: { s: Stats; days: number }) {
    const tiles: [string, number, number, string, string?][] = [
        ['Sent to courier', s.sent_parcels, s.sent_pieces, 'text-slate-900'],
        ['Delivered', s.delivered_parcels, s.delivered_pieces, 'text-emerald-700'],
        ['Returned', s.returned_parcels, s.returned_pieces, 'text-sky-700'],
        ['With courier', s.open_parcels, s.open_pieces, 'text-violet-700'],
        [`Overdue (> ${days} days)`, s.overdue_parcels, s.overdue_pieces, s.overdue_parcels ? 'text-rose-600' : 'text-slate-400', 'Not delivered, not returned — ask the courier'],
        ['Lost (claimed)', s.lost_parcels, s.lost_pieces, s.lost_parcels ? 'text-rose-800' : 'text-slate-400'],
    ];
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            {tiles.map(([label, p, pcs, tone, hint]) => (
                <div key={label} title={hint} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="text-xs font-medium text-slate-500">{label}</div>
                    <div className={`mt-1 text-2xl font-semibold tabular-nums ${tone}`}>{num(p)}</div>
                    <div className="text-xs text-slate-500">parcels · <b className="text-slate-700">{num(pcs)}</b> pcs</div>
                </div>
            ))}
        </div>
    );
}

export const ageDays = (iso: string) => Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
