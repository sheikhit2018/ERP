import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button, Card, Empty, Field, Input, PageHeader, Select } from '@/Components/ui';
import { taka } from '@/lib/format';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import JsBarcode from 'jsbarcode';
import { useEffect, useRef, useState } from 'react';

type Item = { id: number; sku: string; name: string; color: string | null; size: string | null; barcode: string | null; price_minor: number };

function Barcode({ value }: { value: string }) {
    const ref = useRef<SVGSVGElement>(null);
    useEffect(() => {
        if (!ref.current) return;
        const ean = /^\d{13}$/.test(value);
        try { JsBarcode(ref.current, ean ? value.slice(0, 12) : value, { format: ean ? 'EAN13' : 'CODE128', height: 38, width: 1.4, fontSize: 11, margin: 0, flat: true }); }
        catch { JsBarcode(ref.current, value, { format: 'CODE128', height: 38, width: 1.4, fontSize: 11, margin: 0 }); }
    }, [value]);
    return <svg ref={ref} className="mx-auto max-w-full" />;
}

export default function Labels({ items }: PageProps<{ items: Item[] }>) {
    const [copies, setCopies] = useState<Record<number, number>>(() => Object.fromEntries(items.map((i) => [i.id, 1])));
    const [size, setSize] = useState<'50x30' | '38x25'>('50x30');
    const [showPrice, setShowPrice] = useState(true);
    const labels = items.flatMap((i) => Array.from({ length: Math.max(0, copies[i.id] ?? 0) }, (_, n) => ({ ...i, n })));
    const dim = size === '50x30' ? 'w-[50mm] h-[30mm]' : 'w-[38mm] h-[25mm]';

    return (
        <AuthenticatedLayout>
            <Head title="Print labels" />
            <style>{`@media print { @page { margin: 4mm; } body { background: #fff !important; } }`}</style>
            <div className="print:hidden">
                <PageHeader title="Print barcode labels" subtitle="Set how many labels for each variant, then print on sticker paper (or A4)." actions={<Button onClick={() => window.print()} disabled={!labels.length}>Print {labels.length} labels</Button>} />
                {items.length === 0 ? <Card><Empty>Nothing selected. Open Products and use “Print labels”.</Empty></Card> : (
                    <Card className="mb-6">
                        <div className="flex flex-wrap items-end gap-4 border-b border-slate-100 p-4">
                            <Field label="Label size"><Select value={size} onChange={(e) => setSize(e.target.value as any)}><option value="50x30">50 × 30 mm</option><option value="38x25">38 × 25 mm</option></Select></Field>
                            <label className="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" checked={showPrice} onChange={(e) => setShowPrice(e.target.checked)} className="rounded border-slate-300 text-brand-600" /> Show price</label>
                            <Field label="Set all to"><Input type="number" min="0" className="!w-24" onChange={(e) => { const n = parseInt(e.target.value) || 0; setCopies(Object.fromEntries(items.map((i) => [i.id, n]))); }} /></Field>
                        </div>
                        <div className="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3">
                            {items.map((i) => (
                                <div key={i.id} className="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                                    <span className="truncate">{[i.color, i.size].filter(Boolean).join(' / ') || i.name}</span>
                                    <div className="w-20 shrink-0"><Input type="number" min="0" value={copies[i.id] ?? 0} onChange={(e) => setCopies((c) => ({ ...c, [i.id]: parseInt(e.target.value) || 0 }))} className="text-center" /></div>
                                </div>
                            ))}
                        </div>
                    </Card>
                )}
            </div>
            <div className="flex flex-wrap gap-[2mm] print:gap-[2mm]">
                {labels.map((l) => (
                    <div key={`${l.id}-${l.n}`} className={`${dim} flex flex-col justify-between overflow-hidden rounded border border-slate-300 bg-white px-[2mm] py-[1.5mm] text-black print:break-inside-avoid print:rounded-none`}>
                        <div className="flex items-baseline justify-between gap-1 leading-tight">
                            <span className="truncate text-[8pt] font-semibold">{l.name}</span>
                            {showPrice && <span className="whitespace-nowrap text-[8pt] font-bold">{taka(l.price_minor)}</span>}
                        </div>
                        <div className="text-[9pt] font-bold leading-tight">{[l.color, l.size].filter(Boolean).join(' · ')}</div>
                        {l.barcode && <Barcode value={l.barcode} />}
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
