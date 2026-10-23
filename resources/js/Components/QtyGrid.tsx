import { num } from '@/lib/format';

/** Quantities keyed by "colour|size" (lower-case) so renaming case or re-ordering tags never loses a number. */
export type Qty = Record<string, string>;
export const qtyKey = (c: string, s: string) => `${c.trim().toLowerCase()}|${s.trim().toLowerCase()}`;
export const qtyTotal = (q: Qty, colors: string[], sizes: string[]) =>
    colors.reduce((a, c) => a + sizes.reduce((b, s) => b + (parseInt(q[qtyKey(c, s)]) || 0), 0), 0);
export const qtyLines = (q: Qty, colors: string[], sizes: string[]) =>
    colors.flatMap((c) => sizes.map((s) => ({ color: c, size: s, quantity: parseInt(q[qtyKey(c, s)]) || 0 }))).filter((l) => l.quantity > 0);

/**
 * Colour x size quantity matrix. Every cell becomes one variant with its own barcode;
 * the number typed is booked as opening stock for that variant.
 */
export default function QtyGrid({ colors, sizes, value, onChange, disabled }: {
    colors: string[]; sizes: string[]; value: Qty; onChange: (q: Qty) => void; disabled?: (c: string, s: string) => boolean;
}) {
    if (!colors.length || !sizes.length) return null;
    const set = (c: string, s: string, v: string) => onChange({ ...value, [qtyKey(c, s)]: v.replace(/[^\d]/g, '').slice(0, 8) });
    const fillRow = (c: string, v: string) => onChange({ ...value, ...Object.fromEntries(sizes.filter((s) => !disabled?.(c, s)).map((s) => [qtyKey(c, s), v])) });
    const fillCol = (s: string, v: string) => onChange({ ...value, ...Object.fromEntries(colors.filter((c) => !disabled?.(c, s)).map((c) => [qtyKey(c, s), v])) });
    const rowSum = (c: string) => sizes.reduce((a, s) => a + (parseInt(value[qtyKey(c, s)]) || 0), 0);
    const colSum = (s: string) => colors.reduce((a, c) => a + (parseInt(value[qtyKey(c, s)]) || 0), 0);
    const ask = (what: string) => { const v = window.prompt(`Quantity for every ${what}:`); return v === null ? null : String(parseInt(v) || 0); };
    const cellCls = 'w-full min-w-[64px] rounded-md border-slate-300 px-2 py-1.5 text-center text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500';

    return (
        <div className="overflow-x-auto rounded-lg ring-1 ring-slate-200">
            <table className="min-w-full text-sm">
                <thead className="bg-slate-50">
                    <tr>
                        <th className="px-2 py-2 text-left text-xs font-semibold text-slate-500">Colour \ Size</th>
                        {sizes.map((s) => (
                            <th key={s} className="px-1 py-2 text-center text-xs font-semibold text-slate-600">
                                <button type="button" title={`Same quantity for every colour in size ${s}`} className="hover:text-brand-700"
                                    onClick={() => { const v = ask(`colour in size ${s}`); if (v !== null) fillCol(s, v); }}>{s} ↓</button>
                            </th>
                        ))}
                        <th className="px-2 py-2 text-right text-xs font-semibold text-slate-500">Total</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {colors.map((c) => (
                        <tr key={c}>
                            <td className="whitespace-nowrap px-2 py-1.5 font-medium text-slate-700">
                                <button type="button" title={`Same quantity for every size of ${c}`} className="hover:text-brand-700"
                                    onClick={() => { const v = ask(`size of ${c}`); if (v !== null) fillRow(c, v); }}>{c} →</button>
                            </td>
                            {sizes.map((s) => {
                                const off = disabled?.(c, s);
                                return (
                                    <td key={s} className="px-1 py-1">
                                        {off ? <div className="text-center text-xs text-slate-400" title="Already exists — use Stock In">exists</div> : (
                                            <input inputMode="numeric" placeholder="0" aria-label={`${c} ${s} quantity`} className={cellCls}
                                                value={value[qtyKey(c, s)] ?? ''} onChange={(e) => set(c, s, e.target.value)} />
                                        )}
                                    </td>
                                );
                            })}
                            <td className="px-2 py-1.5 text-right font-semibold tabular-nums">{num(rowSum(c))}</td>
                        </tr>
                    ))}
                </tbody>
                <tfoot className="bg-slate-50">
                    <tr>
                        <td className="px-2 py-1.5 text-xs font-semibold text-slate-500">Total</td>
                        {sizes.map((s) => <td key={s} className="px-1 py-1.5 text-center text-xs font-semibold tabular-nums text-slate-600">{num(colSum(s))}</td>)}
                        <td className="px-2 py-1.5 text-right font-bold tabular-nums">{num(qtyTotal(value, colors, sizes))}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
