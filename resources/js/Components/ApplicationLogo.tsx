import { SVGAttributes } from 'react';

/** Opryx mark: an orbit (flow of stock & orders) with a satellite node. */
export default function ApplicationLogo(props: SVGAttributes<SVGElement>) {
    return (
        <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {...props}>
            <rect width="64" height="64" rx="14" fill="#0b1220" />
            <circle cx="32" cy="32" r="15" fill="none" stroke="#43bfaa" strokeWidth="7" />
            <circle cx="44" cy="20" r="5" fill="#d5f5ee" />
        </svg>
    );
}

export function Wordmark({ dark = false }: { dark?: boolean }) {
    return (
        <span className="flex items-center gap-2.5">
            <ApplicationLogo className="h-8 w-8" />
            <span className={`text-lg font-bold tracking-tight ${dark ? 'text-white' : 'text-slate-900'}`}>
                opryx<span className="text-brand-400">.</span>
            </span>
        </span>
    );
}
