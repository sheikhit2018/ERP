import { Wordmark } from '@/Components/ApplicationLogo';
import { Role } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useEffect, useState } from 'react';

type NavItem = { label: string; href: string; match: string; roles?: Role[]; icon: string };
type NavGroup = { title: string; items: NavItem[] };

// Simple inline icons (24px stroke paths) keep the bundle small.
const I = {
    home: 'M3 10.5 12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z',
    order: 'M6 2h12l2 4v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6zM4 6h16M9 11h6M9 15h6',
    box: 'M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
    ret: 'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
    raw: 'M4 20h16M6 20V9l6-5 6 5v11M10 20v-6h4v6',
    bom: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    factory: 'M3 21V10l6 4V10l6 4V4h6v17zM7 17h2M12 17h2M17 17h2',
    ledger: 'M4 4h16v16H4zM4 9h16M9 9v11',
    scan: 'M3 7V4h3M21 7V4h-3M3 17v3h3M21 17v3h-3M7 8v8M10 8v8M13 8v8M17 8v8',
    truck: 'M1 4h13v12H1zM14 8h4l3 4v4h-7M5.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3M17.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3',
    money: 'M2 6h20v12H2zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M6 9v.01M18 15v.01',
    chart: 'M3 3v18h18M7 15l4-4 3 3 5-6',
    gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
    team: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
};

const NAV: NavGroup[] = [
    { title: 'Overview', items: [{ label: 'Dashboard', href: '/dashboard', match: 'dashboard', icon: I.home },
        { label: 'Reports', href: '/reports', match: 'reports.*', roles: ['sales'], icon: I.chart }] },
    { title: 'Sales & logistics', items: [
        { label: 'Orders', href: '/orders', match: 'orders.*', roles: ['sales', 'warehouse'], icon: I.order },
        { label: 'Customers', href: '/customers', match: 'customers.*', roles: ['sales', 'warehouse'], icon: I.team },
        { label: 'Packing station', href: '/courier/pack', match: 'pack.*', roles: ['warehouse'], icon: I.scan },
        { label: 'Courier hub', href: '/courier', match: 'courier.*', roles: ['sales', 'warehouse', 'qc'], icon: I.truck },
        { label: 'COD & payments', href: '/cod', match: 'cod.*', roles: ['sales'], icon: I.money },
        { label: 'Returns & QC', href: '/returns', match: 'returns.*', roles: ['qc', 'warehouse'], icon: I.ret },
    ] },
    { title: 'Inventory', items: [
        { label: 'Products & stock', href: '/products', match: 'products.*', roles: ['sales', 'warehouse', 'manufacturer'], icon: I.box },
        { label: 'Purchases', href: '/purchases', match: 'purchases.*', roles: ['warehouse', 'manufacturer'], icon: I.order },
        { label: 'Suppliers', href: '/suppliers', match: 'suppliers.*', roles: ['warehouse', 'manufacturer'], icon: I.team },
        { label: 'Stock In (scan)', href: '/stock-in', match: 'stockin.*', roles: ['warehouse'], icon: I.scan },
        { label: 'Stock ledger', href: '/stock-ledger', match: 'stock.*', roles: ['sales', 'warehouse', 'manufacturer'], icon: I.ledger },
    ] },
    { title: 'Factory', items: [
        { label: 'Raw materials', href: '/raw-materials', match: 'materials.*', roles: ['manufacturer', 'warehouse'], icon: I.raw },
        { label: 'BOM / recipes', href: '/boms', match: 'boms.*', roles: ['manufacturer'], icon: I.bom },
        { label: 'Production', href: '/manufacturing', match: 'manufacturing.*', roles: ['manufacturer'], icon: I.factory },
    ] },
    { title: 'Finance', items: [{ label: 'Accounts', href: '/accounts', match: 'accounts.*', roles: ['owner'], icon: I.money }] },
    { title: 'Company', items: [{ label: 'Team & roles', href: '/team', match: 'team.*', roles: ['owner'], icon: I.team },
        { label: 'Settings', href: '/settings', match: 'settings.*', roles: ['owner'], icon: I.gear }] },
];

function allowed(role: Role | null, roles?: Role[]) {
    if (!role) return false;
    if (role === 'owner' || !roles) return true;
    if (role === 'manager') return !(roles.length === 1 && roles[0] === 'owner');
    return roles.includes(role);
}

function Flash() {
    const { flash, errors } = usePage().props as any;
    const [hidden, setHidden] = useState(false);
    useEffect(() => setHidden(false), [flash, errors]);
    const inv = errors?.inventory;
    const msg = flash?.error || inv;
    if (hidden || (!flash?.success && !msg)) return null;
    return (
        <div role={msg ? 'alert' : 'status'} className={`mb-5 flex items-start justify-between gap-3 rounded-lg px-4 print:hidden py-3 text-sm ring-1 ring-inset ${msg ? 'bg-rose-50 text-rose-800 ring-rose-200' : 'bg-emerald-50 text-emerald-800 ring-emerald-200'}`}>
            <span>{msg || flash.success}</span>
            <button onClick={() => setHidden(true)} className="text-current opacity-60 hover:opacity-100" aria-label="Dismiss">✕</button>
        </div>
    );
}

export default function Authenticated({ children, header }: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth, tenant, role } = usePage().props;
    const [open, setOpen] = useState(false);
    useEffect(() => router.on('navigate', () => setOpen(false)), []);

    const sidebar = (
        <div className="flex h-full flex-col bg-ink-900 text-slate-300">
            <div className="flex h-16 items-center px-5"><Link href="/dashboard"><Wordmark dark /></Link></div>
            <div className="mx-4 mb-4 rounded-lg bg-ink-800 px-3 py-2">
                <div className="truncate text-sm font-semibold text-white">{tenant?.name ?? '—'}</div>
                <div className="text-xs capitalize text-slate-400">{role}</div>
            </div>
            <nav className="min-h-0 flex-1 space-y-4 overflow-y-auto px-3 pb-6">
                {NAV.map((g) => {
                    const items = g.items.filter((i) => allowed(role, i.roles));
                    if (!items.length) return null;
                    return (
                        <div key={g.title}>
                            <div className="px-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{g.title}</div>
                            {items.map((i) => {
                                const active = route().current(i.match);
                                return (
                                    <Link key={i.href} href={i.href}
                                        className={`flex items-center gap-3 rounded-lg px-2.5 py-1.5 text-sm font-medium transition ${active ? 'bg-brand-600/15 text-brand-300' : 'text-slate-300 hover:bg-white/5 hover:text-white'}`}>
                                        <svg viewBox="0 0 24 24" className="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={i.icon} /></svg>
                                        {i.label}
                                    </Link>
                                );
                            })}
                        </div>
                    );
                })}
            </nav>
            <div className="border-t border-white/10 p-3">
                <Link href="/profile" className="block truncate rounded-lg px-2.5 py-2 text-sm hover:bg-white/5">
                    <span className="font-medium text-white">{auth.user.name}</span>
                    <span className="block truncate text-xs text-slate-400">{auth.user.email}</span>
                </Link>
                <Link href={route('logout')} method="post" as="button" className="mt-1 w-full rounded-lg px-2.5 py-2 text-left text-sm text-slate-400 hover:bg-white/5 hover:text-white">Log out</Link>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen bg-slate-50">
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 lg:block print:!hidden">{sidebar}</aside>
            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-slate-900/50" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 w-72">{sidebar}</aside>
                </div>
            )}
            <div className="lg:pl-64 print:!pl-0">
                <header className="sticky top-0 z-20 flex h-14 print:hidden items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:hidden">
                    <button onClick={() => setOpen(true)} className="rounded-md p-2 text-slate-600 hover:bg-slate-100" aria-label="Open menu">
                        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                    <Wordmark />
                </header>
                <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    {header && <div className="mb-6">{header}</div>}
                    <Flash />
                    {children}
                </main>
            </div>
        </div>
    );
}
