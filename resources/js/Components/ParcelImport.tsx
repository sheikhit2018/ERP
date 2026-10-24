import { Button, Field, Select } from '@/Components/ui';
import { num } from '@/lib/format';
import { readSheetFile } from '@/lib/sheet';
import { ChangeEvent, useMemo, useRef, useState } from 'react';

type Parcel = { order_id: number; tracking_id: string };
type Ready = { id: number; name: string; pieces: number };
type Props = { ready: Map<number, Ready>; inList: Set<number>; listTrackings: Set<string>; onAdd: (parcels: Parcel[]) => void };

const ORDER_RX = /order|invoice|inv\b|merchant|অর্ডার|ইনভয়েস/i;
const TRACK_RX = /track|consign|awb|cn\b|parcel|ট্র্যাক|কনসাইন/i;
const orderNo = (v: string) => { const d = (v.match(/\d+/g) ?? []).join(''); return d ? parseInt(d) : NaN; };

/**
 * Add many parcels from an Excel / CSV file (e.g. the courier's booking export).
 * Needs two columns: order number and courier tracking ID. Columns are guessed from the header and can be changed.
 */
export default function ParcelImport({ ready, inList, listTrackings, onAdd }: Props) {
    const [rows, setRows] = useState<string[][]>([]);
    const [fileName, setFileName] = useState('');
    const [err, setErr] = useState('');
    const [header, setHeader] = useState(true);
    const [cols, setCols] = useState({ order: 0, track: 1 });
    const input = useRef<HTMLInputElement>(null);

    const load = async (e: ChangeEvent<HTMLInputElement>) => {
        const f = e.target.files?.[0]; e.target.value = '';
        if (!f) return;
        setErr(''); setRows([]); setFileName(f.name);
        try {
            const data = await readSheetFile(f);
            if (!data.length) throw new Error('The file is empty.');
            const h = data[0];
            const o = h.findIndex((x) => ORDER_RX.test(x)), t = h.findIndex((x) => TRACK_RX.test(x));
            setHeader(o >= 0 || t >= 0);
            setCols({ order: o >= 0 ? o : 0, track: t >= 0 ? t : (o === 1 ? 0 : 1) });
            setRows(data);
        } catch (x) { setErr(x instanceof Error ? x.message : 'Could not read the file.'); }
    };

    const width = Math.max(0, ...rows.slice(0, 50).map((r) => r.length));
    const colName = (i: number) => (header && rows[0]?.[i]) ? `${String.fromCharCode(65 + (i % 26))} — ${rows[0][i]}` : `Column ${String.fromCharCode(65 + (i % 26))}`;
    const checked = useMemo(() => {
        const seenO = new Set<number>(), seenT = new Set<string>();
        return rows.slice(header ? 1 : 0).map((r, i) => {
            const rawO = r[cols.order] ?? '', t = (r[cols.track] ?? '').trim(), id = orderNo(rawO);
            let problem = '';
            if (!rawO && !t) problem = 'empty';
            else if (Number.isNaN(id)) problem = 'no order number';
            else if (!t) problem = 'no tracking ID';
            else if (inList.has(id) || seenO.has(id)) problem = 'order already added';
            else if (listTrackings.has(t) || seenT.has(t)) problem = 'tracking used twice';
            else if (!ready.has(id)) problem = 'order not packed yet, or already shipped';
            if (!problem) { seenO.add(id); seenT.add(t); }
            return { line: i + (header ? 2 : 1), id, rawO, t, problem };
        }).filter((x) => x.problem !== 'empty');
    }, [rows, header, cols, ready, inList, listTrackings]);
    const ok = checked.filter((c) => !c.problem), bad = checked.filter((c) => c.problem);
    const pieces = ok.reduce((a, c) => a + (ready.get(c.id)?.pieces ?? 0), 0);

    const template = () => {
        const a = document.createElement('a');
        a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent('order_no,tracking_id\n1001,SF123456789\n1002,SF123456790\n');
        a.download = 'opryx-handover-template.csv'; a.click();
    };

    return (
        <div className="space-y-4 p-5">
            <div className="flex flex-wrap items-center gap-3">
                <input ref={input} type="file" accept=".xlsx,.xls,.csv,.tsv,.txt" className="hidden" onChange={load} />
                <Button type="button" variant="secondary" onClick={() => input.current?.click()}>Choose Excel / CSV file</Button>
                <button type="button" onClick={template} className="text-sm text-brand-700 hover:underline">Download template</button>
                {fileName && <span className="text-sm text-slate-500">{fileName}</span>}
            </div>
            <p className="text-xs text-slate-500">The file needs an <b>order number</b> column and a <b>courier tracking ID</b> column (e.g. the courier's booking export with Invoice + Consignment ID). Other columns are ignored.</p>
            {err && <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{err}</p>}

            {rows.length > 0 && <>
                <div className="grid gap-3 sm:grid-cols-3 sm:items-end">
                    <Field label="Order number column"><Select value={cols.order} onChange={(e) => setCols({ ...cols, order: +e.target.value })}>
                        {Array.from({ length: width }, (_, i) => <option key={i} value={i}>{colName(i)}</option>)}</Select></Field>
                    <Field label="Tracking ID column"><Select value={cols.track} onChange={(e) => setCols({ ...cols, track: +e.target.value })}>
                        {Array.from({ length: width }, (_, i) => <option key={i} value={i}>{colName(i)}</option>)}</Select></Field>
                    <label className="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" checked={header} onChange={(e) => setHeader(e.target.checked)} className="rounded border-slate-300 text-brand-600" /> First row is a header</label>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                    <span><b className="text-emerald-700">{num(ok.length)} parcels OK</b> · {num(pieces)} pcs{bad.length > 0 && <> · <b className="text-rose-600">{num(bad.length)} rows skipped</b></>}</span>
                    <div className="flex gap-2">
                        <Button type="button" variant="ghost" onClick={() => { setRows([]); setFileName(''); }}>Clear</Button>
                        <Button type="button" disabled={!ok.length} onClick={() => { onAdd(ok.map((c) => ({ order_id: c.id, tracking_id: c.t }))); setRows([]); setFileName(''); }}>
                            Add {num(ok.length)} parcels to handover</Button>
                    </div>
                </div>

                <div className="max-h-72 overflow-auto rounded-lg ring-1 ring-slate-200">
                    <table className="min-w-full text-sm">
                        <thead className="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500"><tr><th className="px-3 py-2 text-left">Row</th><th className="px-3 py-2 text-left">Order</th><th className="px-3 py-2 text-left">Tracking</th><th className="px-3 py-2 text-left">Check</th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {[...bad, ...ok].slice(0, 500).map((c) => (
                                <tr key={c.line} className={c.problem ? 'bg-rose-50/60' : ''}>
                                    <td className="px-3 py-1.5 text-xs text-slate-400">{c.line}</td>
                                    <td className="px-3 py-1.5">{Number.isNaN(c.id) ? <span className="text-slate-400">{c.rawO || '—'}</span> : <>#{c.id} <span className="text-xs text-slate-500">{ready.get(c.id)?.name}</span></>}</td>
                                    <td className="px-3 py-1.5 font-mono text-xs">{c.t || '—'}</td>
                                    <td className={`px-3 py-1.5 text-xs ${c.problem ? 'text-rose-700' : 'text-emerald-700'}`}>{c.problem || `OK · ${ready.get(c.id)?.pieces} pcs`}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </>}
        </div>
    );
}
