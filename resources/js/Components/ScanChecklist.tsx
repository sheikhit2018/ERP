import { beep } from '@/lib/scan';
import { useRef, useState } from 'react';

export type CheckItem = { product_id: number; label: string; sku: string; barcode: string | null; quantity: number };

/** Scan each unit; shows progress per line and blocks wrong or extra items. */
export function useScanChecklist(items: CheckItem[]) {
    const [scanned, setScanned] = useState<Record<number, number>>({});
    const done = items.every((i) => (scanned[i.product_id] ?? 0) === i.quantity);
    const total = items.reduce((a, i) => a + i.quantity, 0);
    const count = Object.values(scanned).reduce((a, b) => a + b, 0);
    const scans = Object.entries(scanned).filter(([, q]) => q > 0).map(([id, q]) => ({ product_id: Number(id), quantity: q }));
    return { scanned, setScanned, done, total, count, scans };
}

export default function ScanChecklist({ items, state, autoFocus = true }: { items: CheckItem[]; state: ReturnType<typeof useScanChecklist>; autoFocus?: boolean }) {
    const [code, setCode] = useState('');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const ref = useRef<HTMLInputElement>(null);
    const scan = () => {
        const c = code.trim(); setCode(''); if (!c) return;
        const item = items.find((i) => i.barcode === c || i.sku.toLowerCase() === c.toLowerCase());
        if (!item) { beep(false); setMsg({ ok: false, text: `${c} is not in this order.` }); return; }
        const have = state.scanned[item.product_id] ?? 0;
        if (have >= item.quantity) { beep(false); setMsg({ ok: false, text: `Extra item: ${item.label} already ${have}/${item.quantity}.` }); return; }
        state.setScanned((s) => ({ ...s, [item.product_id]: have + 1 }));
        beep(true); setMsg({ ok: true, text: `✓ ${item.label} (${have + 1}/${item.quantity})` });
    };
    return (
        <div className="space-y-2">
            <div className="flex gap-2">
                <input data-scan ref={ref} autoFocus={autoFocus} value={code} onChange={(e) => setCode(e.target.value)} autoComplete="off"
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); scan(); } }}
                    placeholder="Scan each item's barcode" className="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500" />
                <button type="button" onClick={() => { state.setScanned({}); setMsg(null); ref.current?.focus(); }} className="whitespace-nowrap rounded-lg px-3 text-xs text-slate-500 hover:bg-slate-100">Reset</button>
            </div>
            {msg && <p className={`text-xs ${msg.ok ? 'text-emerald-700' : 'font-medium text-rose-600'}`}>{msg.text}</p>}
            <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                {items.map((i) => {
                    const n = state.scanned[i.product_id] ?? 0, ok = n === i.quantity;
                    return (
                        <li key={i.product_id} className={`flex items-center justify-between gap-2 px-3 py-2 ${ok ? 'bg-emerald-50' : ''}`}>
                            <span className="min-w-0"><span className="block truncate font-medium">{i.label}</span><span className="font-mono text-[11px] text-slate-400">{i.barcode}</span></span>
                            <span className={`whitespace-nowrap font-semibold tabular-nums ${ok ? 'text-emerald-700' : 'text-slate-500'}`}>{ok ? '✓ ' : ''}{n}/{i.quantity}</span>
                        </li>
                    );
                })}
            </ul>
            <div className="text-xs text-slate-500">Scanned {state.count} of {state.total} pieces</div>
        </div>
    );
}
