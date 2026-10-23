import JsBarcode from 'jsbarcode';

/** EAN-13 check digit for a 12-digit body. */
export function ean13Check(body12: string): number {
    let sum = 0;
    for (let i = 0; i < 12; i++) sum += Number(body12[i]) * (i % 2 ? 3 : 1);
    return (10 - (sum % 10)) % 10;
}

export type Fmt = 'auto' | 'EAN13' | 'CODE128';

/** Which symbology to use: a valid 13-digit EAN prints as EAN-13 (retail scanners), anything else as Code 128. */
export function pickFormat(value: string, fmt: Fmt = 'auto'): 'EAN13' | 'CODE128' {
    if (fmt === 'CODE128') return 'CODE128';
    const ok = /^\d{13}$/.test(value) && ean13Check(value.slice(0, 12)) === Number(value[12]);
    if (fmt === 'EAN13') return ok || /^\d{12}$/.test(value) ? 'EAN13' : 'CODE128';
    return ok ? 'EAN13' : 'CODE128';
}

export function drawBarcode(el: SVGSVGElement, value: string, opts: { fmt?: Fmt; height?: number; width?: number; fontSize?: number } = {}) {
    const f = pickFormat(value, opts.fmt);
    const base = { height: opts.height ?? 38, width: opts.width ?? 1.4, fontSize: opts.fontSize ?? 11, margin: 0 };
    try {
        JsBarcode(el, f === 'EAN13' ? value.slice(0, 12) : value, { ...base, format: f, flat: true });
    } catch {
        JsBarcode(el, value, { ...base, format: 'CODE128' });
    }
}

/** Save an on-screen barcode SVG as a PNG (for sending to a printing shop / packaging designer). */
export async function downloadPng(svg: SVGSVGElement, name: string, scale = 4) {
    const xml = new XMLSerializer().serializeToString(svg);
    const img = new Image();
    const w = svg.width.baseVal.value || svg.getBoundingClientRect().width, h = svg.height.baseVal.value || svg.getBoundingClientRect().height;
    await new Promise<void>((ok, bad) => { img.onload = () => ok(); img.onerror = () => bad(); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml); });
    const c = document.createElement('canvas');
    c.width = Math.ceil(w * scale); c.height = Math.ceil(h * scale);
    const g = c.getContext('2d')!;
    g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
    g.drawImage(img, 0, 0, c.width, c.height);
    const a = document.createElement('a');
    a.href = c.toDataURL('image/png'); a.download = `${name.replace(/[^\w.-]+/g, '_')}.png`; a.click();
}

/** Render once, reuse everywhere: an SVG data URL for a code (thousands of labels stay fast). */
const cache = new Map<string, string>();
export function barcodeDataUrl(value: string, opts: { fmt?: Fmt; height?: number } = {}): string {
    const k = `${value}|${opts.fmt ?? 'auto'}|${opts.height ?? 38}`;
    const hit = cache.get(k);
    if (hit) return hit;
    const el = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    drawBarcode(el, value, { ...opts, width: 2 });
    const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(el));
    cache.set(k, url);
    return url;
}
