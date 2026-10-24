import GuestLayout from '@/Layouts/GuestLayout';
import { Head } from '@inertiajs/react';
export default function SignupDone({ url, company }: { url: string; company: string }) {
    return (
        <GuestLayout>
            <Head title="Ready" />
            <h1 className="text-lg font-semibold text-slate-900">{company} is ready 🎉</h1>
            <p className="mt-2 text-sm text-slate-600">Bookmark your company address and log in with the email and password you just chose:</p>
            <a href={url} className="mt-4 block rounded-md bg-slate-900 px-4 py-3 text-center font-semibold text-white">{url.replace('https://', '').replace('/login', '')}</a>
        </GuestLayout>
    );
}
