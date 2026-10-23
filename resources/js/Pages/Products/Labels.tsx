import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, LinkButton, PageHeader, Select } from '@/Components/ui';
import { num, taka } from '@/lib/format';
import { barcodeDataUrl, downloadPng } from '@/lib/barcode';
import Barcode from '@/Components/BarcodeSvg';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';

type Item = { id: number; sku: string; name: string; color: string | null; size: string | null; barcode: string | null; price_minor: number; available: number };
type LabelSize = '50x30' | '38x25' | '40x30' | '60x40';
const SIZES: Record<LabelSize, string> = { '50x30': 'w-[50mm] h-[30mm]', '38x25': 'w-[38mm] h-[25mm]', '40x30': 'w-[40mm] h-[30mm]', '60x40': 'w-[60mm] h-[40mm]' };

const MAX_LABELS = 20000; // keep the browser responsive; print large runs in batches

export default function Labels({ items, copies: initial }: PageProps<{ items: Item[]; copies: 'stock' | 'one' }>) {
    const start = (mode: 'stock' | 'one') => Object.fromEntries(items.map((i) => [i.id, mode === 'stock' ? i.available : 1]));
    const [copies, setCopies] = useState<Record<number, number>>(() => start(initial));
    const [size, setSize] = useState<LabelSize>('50x30');
    const [showPrice, setShowPrice] = useState(true);
    const [showSku, setShowSku] = useState(false);
    const svgs = useRef<Record<number, SVGSVGElement | null>>({});
    const total = items.reduce((a, i) => a + Math.max(0, copies[i.id] ?? 0), 0);
    const labels = useMemo(() => total > MAX_LABELS ? [] : items.flatMap((i) => Array.from({ length: Math.max(0, copies[i.id] ?? 0) }, (_, n) => ({ ...i, n }))), [items, copies, total]);
    const short = size === '38x25';

    return (
        <AuthenticatedLayout>
            <Head title="Print labels" />
            <style>{`@media print { @page { margin: 4mm; } body { background: #fff !important; } }`}</style>
            <div className="print:hidden">
                <PageHeader title="Print barcode labels" subtitle="One barcode per colour + size. Set how many stickers each variant needs, then print on sticker roll or A4."
                    actions={<><LinkButton variant="secondary" href="/barcodes">Barcode generator</LinkButton>
                        <Button onClick={() => window.print()} disabled={!labels.length}>Print {num(total)} labels</Button></>} />
                {items.length === 0 ? <Card><Empty>Nothing selected. Open Products and use “Print labels” on a product.</Empty></Card> : (
                    <Card className="mb-6">
                        <div className="flex flex-wrap items-end gap-4 border-b border-slate-100 p-4">
                            <Field label="Label size">
                                <Select value={size} onChange={(e) => setSize(e.target.value as LabelSize)}>
                                    {Object.keys(SIZES).map((k) => <option key={k} value={k}>{k.replace('x', ' × ')} mm</option>)}
                                </Select>
                            </Field>
                            <label className="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" checked={showPrice} onChange={(e) => setShowPrice(e.target.checked)} className="rounded border-slate-300 text-brand-600" /> Price</label>
                            <label className="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" checked={showSku} onChange={(e) => setShowSku(e.target.checked)} className="rounded border-slate-300 text-brand-600" /> SKU</label>
                            <Field label="Set all to"><Input type="number" min="0" className="!w-24" onChange={(e) => { const n = parseInt(e.target.value) || 0; setCopies(Object.fromEntries(items.map((i) => [i.id, n]))); }} /></Field>
                            <Button type="button" variant="secondary" onClick={() => setCopies(start('stock'))}>Copies = stock in hand</Button>
                            <Button type="button" variant="secondary" onClick={() => setCopies(start('one'))}>1 each</Button>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr><th className="px-4 py-2 text-left">Variant</th><th className="px-4 py-2 text-left">Barcode</th><th className="px-4 py-2 text-right">In stock</th><th className="px-4 py-2 text-center">Labels</th><th className="px-4 py-2" /></tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {items.map((i) => (
                                        <tr key={i.id}>
                                            <td className="px-4 py-2"><div className="font-medium">{[i.color, i.size].filter(Boolean).join(' / ') || i.name}</div><div className="text-xs text-slate-500">{i.sku}</div></td>
                                            <td className="px-4 py-2 font-mono text-xs">{i.barcode}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{num(i.available)}</td>
                                            <td className="px-4 py-2"><Input type="number" min="0" value={copies[i.id] ?? 0} onChange={(e) => setCopies((c) => ({ ...c, [i.id]: parseInt(e.target.value) || 0 }))} className="!w-24 text-center" /></td>
                                            <td className="whitespace-nowrap px-4 py-2 text-right">
                                                <button type="button" className="mr-3 text-xs text-brand-700 hover:underline" title="Print only this variant"
                                                    onClick={() => setCopies(Object.fromEntries(items.map((x) => [x.id, x.id === i.id ? Math.max(1, copies[i.id] || i.available || 1) : 0])))}>Only this</button>
                                                {i.barcode && <button type="button" className="text-xs text-brand-700 hover:underline"
                                                    onClick={() => { const el = svgs.current[i.id]; if (el) downloadPng(el, `${i.sku}-${i.barcode}`); }}>PNG</button>}
                                                <div className="hidden">{i.barcode && <Barcode value={i.barcode} height={60} svgRef={(el) => { svgs.current[i.id] = el; }} />}</div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {total > MAX_LABELS && <p className="m-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{num(total)} labels is too many for one print job. Print up to {num(MAX_LABELS)} at a time — use “Only this” to print one variant per batch.</p>}
                    </Card>
                )}
            </div>
            <div className="flex flex-wrap gap-[2mm] print:gap-[2mm]">
                {labels.map((l) => (
                    <div key={`${l.id}-${l.n}`} className={`${SIZES[size]} flex flex-col justify-between overflow-hidden rounded border border-slate-300 bg-white px-[2mm] py-[1.5mm] text-black print:break-inside-avoid print:rounded-none`}>
                        <div className="flex items-baseline justify-between gap-1 leading-tight">
                            <span className="truncate text-[8pt] font-semibold">{l.name}</span>
                            {showPrice && <span className="whitespace-nowrap text-[8pt] font-bold">{taka(l.price_minor)}</span>}
                        </div>
                        <div className="flex items-baseline justify-between gap-1 leading-tight">
                            <span className="text-[9pt] font-bold">{[l.color, l.size].filter(Boolean).join(' · ')}</span>
                            {showSku && <span className="truncate text-[6.5pt]">{l.sku}</span>}
                        </div>
                        {l.barcode && <img src={barcodeDataUrl(l.barcode, { height: 50 })} alt={l.barcode} className={`mx-auto w-auto max-w-full object-contain ${short ? 'h-[11mm]' : size === '60x40' ? 'h-[20mm]' : 'h-[15mm]'}`} />}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
