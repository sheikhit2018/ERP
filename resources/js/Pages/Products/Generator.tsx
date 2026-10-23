import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BarcodeSvg from '@/Components/BarcodeSvg';
import { Button, Card, Field, Input, LinkButton, PageHeader, Select } from '@/Components/ui';
import { barcodeDataUrl, downloadPng, ean13Check, Fmt, pickFormat } from '@/lib/barcode';
import { num } from '@/lib/format';
import { Head } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';

type Row = { code: string; title: string; line2: string; copies: number };
type Mode = 'list' | 'sequence';
const SIZES = { '50x30': 'w-[50mm] h-[30mm]', '38x25': 'w-[38mm] h-[25mm]', '40x30': 'w-[40mm] h-[30mm]', '60x40': 'w-[60mm] h-[40mm]', '100x50': 'w-[100mm] h-[50mm]' } as const;
type Size = keyof typeof SIZES;
const MAX = 20000;

/** "2000001" + count 5 + width -> 2000001…2000005, keeping leading zeros; optional prefix and EAN-13 check digit. */
function sequence(prefix: string, start: string, count: number, ean: boolean): string[] {
    const digits = start.replace(/\D/g, '') || '1';
    const width = digits.length;
    const out: string[] = [];
    let n = BigInt(digits);
    for (let i = 0; i < Math.min(count, MAX); i++, n++) {
        let code = prefix + n.toString().padStart(width, '0');
        if (ean && /^\d{12}$/.test(code)) code += ean13Check(code);
        out.push(code);
    }
    return out;
}

export default function Generator() {
    const [mode, setMode] = useState<Mode>('list');
    const [text, setText] = useState('');
    const [title, setTitle] = useState('');
    const [line2, setLine2] = useState('');
    const [copies, setCopies] = useState(1);
    const [fmt, setFmt] = useState<Fmt>('auto');
    const [size, setSize] = useState<Size>('50x30');
    const [seq, setSeq] = useState({ prefix: '', start: '0001', count: '50', ean: false });
    const preview = useRef<SVGSVGElement | null>(null);

    // List mode: one code per line, optionally "code, title, line 2".
    const rows: Row[] = useMemo(() => {
        if (mode === 'sequence') return sequence(seq.prefix.trim(), seq.start, parseInt(seq.count) || 0, seq.ean).map((code) => ({ code, title, line2, copies }));
        return text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean).slice(0, MAX).map((l) => {
            const [code, t, l2] = l.split(/\s*[,\t]\s*/);
            return { code, title: t ?? title, line2: l2 ?? line2, copies };
        }).filter((r) => r.code);
    }, [mode, text, title, line2, copies, seq]);
    const total = rows.reduce((a, r) => a + r.copies, 0);
    const labels = total > MAX ? [] : rows.flatMap((r) => Array.from({ length: r.copies }, (_, n) => ({ ...r, n })));
    const first = rows[0]?.code ?? '';
    const bad = rows.filter((r) => fmt === 'EAN13' && pickFormat(r.code, 'EAN13') !== 'EAN13').length;

    return (
        <AuthenticatedLayout>
            <Head title="Barcode generator" />
            <style>{`@media print { @page { margin: 4mm; } body { background: #fff !important; } }`}</style>
            <div className="print:hidden">
                <PageHeader title="Barcode generator" subtitle="Make barcodes for anything: your own codes, carton / shelf / bin labels, number sequences. Product barcodes are created automatically per colour + size."
                    actions={<><LinkButton variant="secondary" href="/products">Product labels</LinkButton><Button onClick={() => window.print()} disabled={!labels.length}>Print {num(total)} labels</Button></>} />

                <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
                    <Card>
                        <div className="space-y-4 p-5">
                            <div className="inline-flex rounded-lg bg-slate-100 p-1 text-sm">
                                {(['list', 'sequence'] as Mode[]).map((m) => (
                                    <button key={m} type="button" onClick={() => setMode(m)} className={`rounded-md px-3 py-1.5 ${mode === m ? 'bg-white font-medium shadow-sm' : 'text-slate-600'}`}>
                                        {m === 'list' ? 'My codes' : 'Number sequence'}
                                    </button>
                                ))}
                            </div>
                            {mode === 'list' ? (
                                <Field label="Codes — one per line" hint="Optional: code, title, second line  (e.g.  CTN-001, Carton 1, White M x 50)">
                                    <textarea rows={7} value={text} onChange={(e) => setText(e.target.value)} placeholder={'CTN-001\nCTN-002, Carton 2, Black L x 40\n8901234567890'}
                                        className="block w-full rounded-lg border-slate-300 font-mono text-sm focus:border-brand-500 focus:ring-brand-500" />
                                </Field>
                            ) : (
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <Field label="Prefix"><Input value={seq.prefix} onChange={(e) => setSeq({ ...seq, prefix: e.target.value.replace(/\s/g, '') })} placeholder="BIN-" /></Field>
                                    <Field label="Start number"><Input value={seq.start} onChange={(e) => setSeq({ ...seq, start: e.target.value.replace(/\D/g, '') })} /></Field>
                                    <Field label="How many"><Input type="number" min="1" max={MAX} value={seq.count} onChange={(e) => setSeq({ ...seq, count: e.target.value })} /></Field>
                                    <label className="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" checked={seq.ean} onChange={(e) => setSeq({ ...seq, ean: e.target.checked })} className="rounded border-slate-300 text-brand-600" /> Add EAN-13 check digit</label>
                                </div>
                            )}
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <Field label="Title (top line)"><Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="SHEIKH IT" /></Field>
                                <Field label="Second line"><Input value={line2} onChange={(e) => setLine2(e.target.value)} placeholder="Rack A-3" /></Field>
                                <Field label="Copies of each"><Input type="number" min="1" max="500" value={copies} onChange={(e) => setCopies(Math.max(1, Math.min(500, parseInt(e.target.value) || 1)))} /></Field>
                                <Field label="Label size"><Select value={size} onChange={(e) => setSize(e.target.value as Size)}>{Object.keys(SIZES).map((k) => <option key={k} value={k}>{k.replace('x', ' × ')} mm</option>)}</Select></Field>
                            </div>
                            <Field label="Barcode type" hint="Auto = EAN-13 for valid 13-digit retail codes, Code 128 for everything else (letters, dashes, any length).">
                                <Select value={fmt} onChange={(e) => setFmt(e.target.value as Fmt)} className="max-w-xs">
                                    <option value="auto">Auto</option><option value="CODE128">Code 128 (any text)</option><option value="EAN13">EAN-13 (12–13 digits)</option>
                                </Select>
                            </Field>
                            {bad > 0 && <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{bad} code(s) are not valid EAN-13 and will print as Code 128.</p>}
                            {total > MAX && <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{num(total)} labels — print at most {num(MAX)} at a time.</p>}
                        </div>
                    </Card>
                    <Card title="Preview">
                        <div className="space-y-3 p-5 text-center">
                            {first ? <>
                                <BarcodeSvg value={first} fmt={fmt} height={70} svgRef={(el) => { preview.current = el; }} />
                                <div className="font-mono text-xs text-slate-500">{first} · {pickFormat(first, fmt) === 'EAN13' ? 'EAN-13' : 'Code 128'}</div>
                                <Button type="button" variant="secondary" onClick={() => preview.current && downloadPng(preview.current, first)}>Download PNG</Button>
                                <p className="text-xs text-slate-500">{rows.length} code(s) × {copies} = <b>{num(total)}</b> labels</p>
                            </> : <p className="py-10 text-sm text-slate-400">Type a code to see it here.</p>}
                        </div>
                    </Card>
                </div>
            </div>

            <div className="mt-6 flex flex-wrap gap-[2mm] print:mt-0">
                {labels.map((l, i) => (
                    <div key={i} className={`${SIZES[size]} flex flex-col justify-between overflow-hidden rounded border border-slate-300 bg-white px-[2mm] py-[1.5mm] text-black print:break-inside-avoid print:rounded-none`}>
                        {(l.title || l.line2) && <div className="leading-tight">
                            {l.title && <div className="truncate text-[8pt] font-semibold">{l.title}</div>}
                            {l.line2 && <div className="truncate text-[8pt] font-bold">{l.line2}</div>}
                        </div>}
                        <img src={barcodeDataUrl(l.code, { fmt, height: 50 })} alt={l.code} className={`mx-auto w-auto max-w-full object-contain ${size === '38x25' ? 'h-[11mm]' : size === '100x50' || size === '60x40' ? 'h-[22mm]' : 'h-[15mm]'}`} />
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
