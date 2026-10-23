import { drawBarcode, Fmt } from '@/lib/barcode';
import { useEffect, useRef } from 'react';

export default function BarcodeSvg({ value, height = 38, fmt = 'auto', svgRef }: { value: string; height?: number; fmt?: Fmt; svgRef?: (el: SVGSVGElement | null) => void }) {
    const ref = useRef<SVGSVGElement | null>(null);
    useEffect(() => { if (ref.current && value) drawBarcode(ref.current, value, { height, fmt }); }, [value, height, fmt]);
    return <svg ref={(el) => { ref.current = el; svgRef?.(el); }} className="mx-auto max-w-full" />;
}
