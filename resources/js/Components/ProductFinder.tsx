import { beep } from '@/lib/scan';
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';

export type CatalogItem = { id: number; sku: string; name: string; color: string | null; size: string | null; barcode: string | null; price_minor?: number; qty?: number };
export const label = (p: CatalogItem) => [p.name, p.color, p.size].filter(Boolean).join(' · ');

/** Scanner-first product input: a barcode / SKU scan adds instantly; typing shows matches to click. Works with a USB scanner (Enter after code). */
export default function ProductFinder({ items, onPick, placeholder = 'Scan barcode or type product / SKU…', autoFocus = true }:
    { items: CatalogItem[]; onPick: (p: CatalogItem) => void; placeholder?: string; autoFocus?: boolean }) {
    const [q, setQ] = useState('');
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const ref = useRef<HTMLInputElement>(null);
    useEffect(() => { if (autoFocus) ref.current?.focus(); }, [autoFocus]);
    const byCode = useMemo(() => { const m = new Map<string, CatalogItem>(); items.forEach((p) => { if (p.barcode) m.set(p.barcode.toLowerCase(), p); m.set(p.sku.toLowerCase(), p); }); return m; }, [items]);
    const matches = useMemo(() => { const t = q.trim().toLowerCase(); if (t.length < 2) return [];
        return items.filter((p) => `${p.sku} ${label(p)} ${p.barcode ?? ''}`.toLowerCase().includes(t)).slice(0, 8); }, [q, items]);
    const pick = (p: CatalogItem) => { onPick(p); setQ(''); setMsg({ ok: true, text: `+1 ${label(p)}` }); beep(true); ref.current?.focus(); };
    const submit = (e: FormEvent) => { e.preventDefault(); const t = q.trim().toLowerCase(); if (!t) return;
        const hit = byCode.get(t) ?? (matches.length === 1 ? matches[0] : undefined);
        if (hit) pick(hit); else { beep(false); setMsg({ ok: false, text: `No product with code “${q.trim()}”.` }); setQ(''); } };
    return (
        <div className="relative">
            <form onSubmit={submit}><input data-scan ref={ref} value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} autoComplete="off"
                className="w-full rounded-lg border-0 px-4 py-3 text-base shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600" /></form>
            {matches.length > 0 && !byCode.has(q.trim().toLowerCase()) && (
                <ul className="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-slate-200">{matches.map((p) => (
                    <li key={p.id}><button type="button" onClick={() => pick(p)} className="flex w-full justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-slate-50">
                        <span><span className="font-medium">{label(p)}</span> <span className="text-xs text-slate-500">{p.sku}</span></span>
                        {p.qty !== undefined && <span className={`text-xs tabular-nums ${p.qty > 0 ? 'text-slate-500' : 'text-rose-600'}`}>{p.qty} in stock</span>}</button></li>))}</ul>
            )}
            {msg && <div className={`mt-1.5 text-sm ${msg.ok ? 'text-emerald-700' : 'text-rose-600'}`}>{msg.text}</div>}
        </div>
    );
}
