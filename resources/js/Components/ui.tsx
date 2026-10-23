import { Link } from '@inertiajs/react';
import { InputHTMLAttributes, PropsWithChildren, ReactNode, SelectHTMLAttributes, forwardRef } from 'react';
import { STATUS_LABEL, statusTone } from '@/lib/format';

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: string; actions?: ReactNode }) {
    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

export function Card({ children, className = '', title, actions }: PropsWithChildren<{ className?: string; title?: string; actions?: ReactNode }>) {
    return (
        <section className={`min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm print:border-0 print:shadow-none ${className}`}>
            {(title || actions) && (
                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
                    {actions}
                </div>
            )}
            {children}
        </section>
    );
}

export function StatusBadge({ status }: { status: string }) {
    return (
        <span className={`inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${statusTone(status)}`}>
            {STATUS_LABEL[status] ?? status}
        </span>
    );
}

type BtnProps = React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'danger' | 'ghost' };
export function Button({ variant = 'primary', className = '', ...p }: BtnProps) {
    const v = {
        primary: 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:outline-brand-600',
        secondary: 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
        danger: 'bg-rose-600 text-white hover:bg-rose-700',
        ghost: 'text-slate-600 hover:bg-slate-100',
    }[variant];
    return <button {...p} className={`inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${v} ${className}`} />;
}

export function LinkButton({ href, children, variant = 'primary' }: PropsWithChildren<{ href: string; variant?: 'primary' | 'secondary' }>) {
    const v = variant === 'primary' ? 'bg-brand-600 text-white hover:bg-brand-700' : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50';
    return <Link href={href} className={`inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-semibold shadow-sm ${v}`}>{children}</Link>;
}

export function Field({ label, error, children, hint }: PropsWithChildren<{ label: string; error?: string; hint?: string }>) {
    return (
        <label className="block">
            <span className="mb-1 block text-sm font-medium text-slate-700">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-rose-600">{error}</span>}
        </label>
    );
}

const inputCls = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 disabled:bg-slate-100';
export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(({ className = '', ...p }, ref) => (
    <input ref={ref} {...p} className={`${inputCls} ${className}`} />
));
export function Select({ className = '', children, ...p }: SelectHTMLAttributes<HTMLSelectElement>) {
    return <select {...p} className={`${inputCls} ${className}`}>{children}</select>;
}

export function Pagination({ links }: { links: { url: string | null; label: string; active: boolean }[] }) {
    if (links.length <= 3) return null;
    return (
        <nav className="flex flex-wrap gap-1 border-t border-slate-100 px-4 py-3">
            {links.map((l, i) => (
                <Link key={i} href={l.url ?? '#'} preserveScroll
                    className={`rounded-md px-3 py-1 text-sm ${l.active ? 'bg-brand-600 text-white' : l.url ? 'text-slate-600 hover:bg-slate-100' : 'pointer-events-none text-slate-300'}`}
                    dangerouslySetInnerHTML={{ __html: l.label }} />
            ))}
        </nav>
    );
}

export function Empty({ children }: PropsWithChildren) {
    return <div className="px-5 py-12 text-center text-sm text-slate-500">{children}</div>;
}

export const th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
export const td = 'px-4 py-3 text-sm text-slate-700';
