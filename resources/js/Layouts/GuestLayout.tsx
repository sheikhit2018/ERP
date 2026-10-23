import { Wordmark } from '@/Components/ApplicationLogo';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="grid min-h-screen lg:grid-cols-2">
            <div className="relative hidden overflow-hidden bg-ink-900 p-12 text-white lg:flex lg:flex-col lg:justify-between">
                <Wordmark dark />
                <div className="relative z-10 max-w-md">
                    <h1 className="text-3xl font-semibold leading-tight">Factory floor to customer doorstep, in one system.</h1>
                    <p className="mt-4 text-slate-300">Stock, orders, courier dispatch, returns and production for growing D2C brands.</p>
                </div>
                <p className="relative z-10 text-sm text-slate-500">Opryx ERP · by Sheikh IT</p>
                <div className="pointer-events-none absolute -right-32 -top-32 h-[28rem] w-[28rem] rounded-full border-[48px] border-brand-500/20" />
                <div className="pointer-events-none absolute -bottom-24 right-24 h-40 w-40 rounded-full bg-brand-400/10" />
            </div>
            <div className="flex flex-col items-center justify-center bg-white px-6 py-12">
                <div className="mb-8 lg:hidden"><Wordmark /></div>
                <div className="w-full max-w-sm">{children}</div>
            </div>
        </div>
    );
}
