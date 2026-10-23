// Short audible feedback for barcode scanning stations (no audio files needed).
let ctx: AudioContext | null = null;
export function beep(ok = true) {
    try {
        ctx = ctx ?? new (window.AudioContext || (window as any).webkitAudioContext)();
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'square'; o.frequency.value = ok ? 1200 : 220;
        g.gain.value = 0.05; o.connect(g); g.connect(ctx.destination);
        o.start(); o.stop(ctx.currentTime + (ok ? 0.08 : 0.35));
    } catch { /* audio not available */ }
}

export type Variant = { id: number; sku: string; label: string; name: string; color: string | null; size: string | null; barcode: string | null; price_minor: number; available: number };
export type Lookup = { style: { id: number; code: string; name: string; barcode: string | null } | null; matched_id: number | null; variants: Variant[] };

export async function lookupBarcode(code: string, path = '/scan/lookup'): Promise<Lookup> {
    const r = await fetch(`${path}?code=${encodeURIComponent(code)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    const j = await r.json().catch(() => null);
    if (!r.ok) throw new Error(j?.message || `Lookup failed (${r.status})`);
    return j as Lookup;
}

/** Unique values in first-seen order (keeps the colour / size order the user entered). */
export const uniq = <T,>(xs: (T | null | undefined)[]) => xs.filter((x, i) => x != null && xs.indexOf(x) === i) as T[];
