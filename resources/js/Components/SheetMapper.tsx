import { Button, Field, Select } from '@/Components/ui';
import { readSheetFile } from '@/lib/sheet';
import { ChangeEvent, ReactNode, useMemo, useRef, useState } from 'react';

export type Column = { key: string; label: string; guess: RegExp; required?: boolean };
export type Mapped = { line: number; values: Record<string, string> };

/**
 * Upload an Excel (.xlsx) / CSV file and map its columns to the fields we need.
 * Columns are guessed from the header row; the user can change them. Parsing happens in the browser.
 */
export default function SheetMapper({ columns, onRows, hint, template }: {
    columns: Column[]; onRows: (rows: Mapped[]) => ReactNode; hint?: ReactNode; template?: { name: string; csv: string };
}) {
    const [rows, setRows] = useState<string[][]>([]);
    const [name, setName] = useState('');
    const [err, setErr] = useState('');
    const [header, setHeader] = useState(true);
    const [map, setMap] = useState<Record<string, number>>({});
    const input = useRef<HTMLInputElement>(null);

    const load = async (e: ChangeEvent<HTMLInputElement>) => {
        const f = e.target.files?.[0]; e.target.value = '';
        if (!f) return;
        setErr(''); setRows([]); setName(f.name);
        try {
            const data = await readSheetFile(f);
            if (!data.length) throw new Error('The file is empty.');
            const h = data[0]; const m: Record<string, number> = {}; const used = new Set<number>();
            for (const c of columns) { const i = h.findIndex((x, j) => !used.has(j) && c.guess.test(x)); if (i >= 0) { m[c.key] = i; used.add(i); } }
            setHeader(Object.keys(m).length > 0); setMap(m); setRows(data);
        } catch (x) { setErr(x instanceof Error ? x.message : 'Could not read the file.'); }
    };
    const width = Math.max(0, ...rows.slice(0, 50).map((r) => r.length));
    const colName = (i: number) => `${String.fromCharCode(65 + (i % 26))}${header && rows[0]?.[i] ? ` — ${rows[0][i]}` : ''}`;
    const mapped: Mapped[] = useMemo(() => rows.slice(header ? 1 : 0).map((r, i) => ({ line: i + (header ? 2 : 1),
        values: Object.fromEntries(columns.map((c) => [c.key, map[c.key] === undefined ? '' : (r[map[c.key]] ?? '').trim()])) }))
        .filter((m) => Object.values(m.values).some((v) => v !== '')), [rows, header, map, columns]);
    const missing = columns.filter((c) => c.required && map[c.key] === undefined);

    const downloadTemplate = () => { if (!template) return; const a = document.createElement('a'); a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(template.csv); a.download = template.name; a.click(); };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <input ref={input} type="file" accept=".xlsx,.xls,.csv,.tsv,.txt" className="hidden" onChange={load} />
                <Button type="button" variant="secondary" onClick={() => input.current?.click()}>Choose Excel / CSV file</Button>
                {template && <button type="button" onClick={downloadTemplate} className="text-sm text-brand-700 hover:underline">Download template</button>}
                {name && <span className="text-sm text-slate-500">{name}</span>}
            </div>
            {hint && <div className="text-xs text-slate-500">{hint}</div>}
            {err && <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{err}</p>}
            {rows.length > 0 && <>
                <div className="grid gap-3 sm:grid-cols-3">
                    {columns.map((c) => (
                        <Field key={c.key} label={`${c.label}${c.required ? '' : ' (optional)'}`}>
                            <Select value={map[c.key] ?? ''} onChange={(e) => setMap((m) => { const n = { ...m }; if (e.target.value === '') delete n[c.key]; else n[c.key] = +e.target.value; return n; })}>
                                <option value="">— not in file —</option>
                                {Array.from({ length: width }, (_, i) => <option key={i} value={i}>{colName(i)}</option>)}
                            </Select>
                        </Field>
                    ))}
                    <label className="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" checked={header} onChange={(e) => setHeader(e.target.checked)} className="rounded border-slate-300 text-brand-600" /> First row is a header</label>
                </div>
                {missing.length > 0 ? <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Choose the column for: {missing.map((m) => m.label).join(', ')}</p> : onRows(mapped)}
            </>}
        </div>
    );
}

/** "৳1,250.50" / "1250" / "-" → paisa */
export const moneyMinor = (v: string) => { const n = parseFloat(String(v).replace(/[^\d.-]/g, '')); return Number.isFinite(n) ? Math.round(n * 100) : 0; };
