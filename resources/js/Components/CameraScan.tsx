import { beep } from '@/lib/scan';
import { useT } from '@/lib/i18n';
import { useEffect, useRef, useState } from 'react';

/**
 * Phone camera as a barcode scanner. Uses the browser's BarcodeDetector when available (Chrome / Android),
 * otherwise loads the ZXing decoder on demand. Each decoded code is typed into the active scan box and submitted,
 * exactly like a USB scanner would do — so every scan screen works without changes.
 */
let lastInput: HTMLInputElement | null = null;
if (typeof document !== 'undefined') document.addEventListener('focusin', (e) => {
    const el = e.target as HTMLInputElement;
    if (el?.tagName === 'INPUT' && ['text', 'search', 'tel', ''].includes(el.type) && !el.closest('[data-camera]')) lastInput = el;
});

function deliver(code: string) {
    const alive = (el: HTMLInputElement | null) => !!el && document.body.contains(el) && el.offsetParent !== null;
    const scanBoxes = Array.from(document.querySelectorAll<HTMLInputElement>('input[data-scan]')).filter(alive);
    const target = (alive(lastInput) && lastInput!.hasAttribute('data-scan') ? lastInput : null) ?? scanBoxes[0] ?? (alive(lastInput) ? lastInput : null)
        ?? document.querySelector<HTMLInputElement>('main input[type=text], main input:not([type])');
    if (!target) return false;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
    setter.call(target, code);
    target.dispatchEvent(new Event('input', { bubbles: true }));
    setTimeout(() => { if (target.form) target.form.requestSubmit(); else target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); }, 30);
    return true;
}

export default function CameraScan() {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [msg, setMsg] = useState('');
    const video = useRef<HTMLVideoElement>(null);
    const stopRef = useRef<() => void>(() => {});
    const last = useRef({ code: '', at: 0 });

    useEffect(() => {
        if (!open) return;
        let cancelled = false;
        const hit = (code: string) => {
            const now = Date.now();
            if (code === last.current.code && now - last.current.at < 1500) return; // same label still in view
            last.current = { code, at: now };
            if (deliver(code)) { beep(true); setMsg(`✓ ${code}`); if (navigator.vibrate) navigator.vibrate(60); } else setMsg(`${code} — tap a scan box first`);
        };
        (async () => {
            try {
                const W = window as any;
                if (W.BarcodeDetector) {
                    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                    if (cancelled) { stream.getTracks().forEach((x) => x.stop()); return; }
                    video.current!.srcObject = stream; await video.current!.play();
                    const det = new W.BarcodeDetector({ formats: ['code_128', 'ean_13', 'ean_8', 'code_39', 'upc_a', 'upc_e', 'qr_code', 'itf'] });
                    let raf = 0;
                    const loop = async () => { if (cancelled) return; try { const r = await det.detect(video.current!); if (r[0]?.rawValue) hit(r[0].rawValue); } catch { /* frame not ready */ } raf = window.setTimeout(loop, 180); };
                    loop();
                    stopRef.current = () => { clearTimeout(raf); stream.getTracks().forEach((x) => x.stop()); };
                } else {
                    const { BrowserMultiFormatReader } = await import('@zxing/browser');
                    const reader = new BrowserMultiFormatReader();
                    const ctl = await reader.decodeFromConstraints({ video: { facingMode: 'environment' } }, video.current!, (res) => { if (res) hit(res.getText()); });
                    if (cancelled) ctl.stop();
                    stopRef.current = () => ctl.stop();
                }
                setMsg('');
            } catch (e) {
                setMsg(e instanceof Error && e.name === 'NotAllowedError' ? 'Camera permission denied. Allow camera for this site in the browser settings.' : 'Camera not available on this device.');
            }
        })();
        return () => { cancelled = true; stopRef.current(); };
    }, [open]);

    return (
        <>
            <button type="button" data-camera onClick={() => setOpen(true)} title={t('Scan with camera')} aria-label={t('Scan with camera')}
                className="fixed bottom-5 right-5 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg hover:bg-brand-700 print:hidden">
                <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M11 8v8M15 8v8M18 8v8" /></svg>
            </button>
            {open && (
                <div data-camera className="fixed inset-x-0 bottom-0 z-50 bg-black/90 p-3 sm:inset-auto sm:bottom-24 sm:right-5 sm:w-96 sm:rounded-xl">
                    <div className="relative overflow-hidden rounded-lg bg-black">
                        <video ref={video} muted playsInline className="h-56 w-full object-cover" />
                        <div className="pointer-events-none absolute inset-x-8 top-1/2 h-0.5 -translate-y-1/2 bg-rose-500/80" />
                    </div>
                    <div className="mt-2 flex items-center justify-between gap-2 text-sm text-white"><span className="truncate">{msg || 'Point at a barcode — it is entered automatically.'}</span>
                        <button onClick={() => setOpen(false)} className="rounded bg-white/15 px-3 py-1.5">{t('Close')}</button></div>
                </div>
            )}
        </>
    );
}
