import { KeyboardEvent, useState } from 'react';

/** Chips for colours / sizes: type and press Enter or comma, or use the + button. */
export default function TagInput({ value, onChange, placeholder, addLabel, suggestions = [] }: {
    value: string[]; onChange: (v: string[]) => void; placeholder?: string; addLabel: string; suggestions?: string[];
}) {
    const [text, setText] = useState('');
    const add = (raw: string) => {
        const parts = raw.split(',').map((s) => s.trim()).filter(Boolean);
        const next = [...value];
        for (const p of parts) if (!next.some((v) => v.toLowerCase() === p.toLowerCase())) next.push(p);
        onChange(next); setText('');
    };
    const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); if (text.trim()) add(text); }
        else if (e.key === 'Backspace' && !text && value.length) onChange(value.slice(0, -1));
    };
    const unused = suggestions.filter((s) => !value.some((v) => v.toLowerCase() === s.toLowerCase()));
    return (
        <div>
            <div className="flex min-h-[42px] flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2 py-1.5 focus-within:border-brand-500 focus-within:ring-1 focus-within:ring-brand-500">
                {value.map((v) => (
                    <span key={v} className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-sm text-slate-800">
                        {v}
                        <button type="button" onClick={() => onChange(value.filter((x) => x !== v))} className="text-slate-400 hover:text-rose-600" aria-label={`Remove ${v}`}>×</button>
                    </span>
                ))}
                <input value={text} onChange={(e) => setText(e.target.value)} onKeyDown={onKey} onBlur={() => text.trim() && add(text)}
                    placeholder={value.length ? '' : placeholder} className="min-w-[90px] flex-1 border-0 p-0.5 text-sm focus:ring-0" />
                <button type="button" onClick={() => text.trim() && add(text)} className="rounded-md px-2 py-0.5 text-xs font-semibold text-brand-700 hover:bg-brand-50">+ {addLabel}</button>
            </div>
            {unused.length > 0 && (
                <div className="mt-1.5 flex flex-wrap gap-1">
                    {unused.map((s) => (
                        <button key={s} type="button" onClick={() => add(s)} className="rounded-md border border-dashed border-slate-300 px-2 py-0.5 text-xs text-slate-500 hover:border-brand-400 hover:text-brand-700">+ {s}</button>
                    ))}
                </div>
            )}
        </div>
    );
}
