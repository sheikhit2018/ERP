import { usePage } from '@inertiajs/react';

/** Lightweight Bangla translation for navigation, headings and common actions. Unknown strings fall back to English. */
const BN: Record<string, string> = {
    Overview: 'সারসংক্ষেপ', Dashboard: 'ড্যাশবোর্ড', Reports: 'রিপোর্ট', 'Sales & logistics': 'বিক্রয় ও ডেলিভারি', 'POS (counter sale)': 'POS (দোকান বিক্রি)', Orders: 'অর্ডার',
    Customers: 'কাস্টমার', 'Packing station': 'প্যাকিং স্টেশন', 'Courier hub': 'কুরিয়ার হাব', 'COD & payments': 'COD ও পেমেন্ট', 'Returns & QC': 'রিটার্ন ও QC', Inventory: 'ইনভেন্টরি',
    'Products & stock': 'প্রোডাক্ট ও স্টক', Purchases: 'ক্রয়', Suppliers: 'সাপ্লায়ার', 'Stock In (scan)': 'স্টক ইন (স্ক্যান)', 'Warehouses & shops': 'গুদাম ও দোকান', 'Stock count': 'স্টক গণনা',
    'Stock ledger': 'স্টক লেজার', Factory: 'ফ্যাক্টরি', 'Raw materials': 'কাঁচামাল', 'BOM / recipes': 'BOM / রেসিপি', Production: 'প্রোডাকশন', 'Work log (pieces)': 'কাজের হিসাব (পিস)',
    Attendance: 'হাজিরা', 'Finance & HR': 'হিসাব ও HR', Accounts: 'হিসাব-নিকাশ', Staff: 'কর্মী', Payroll: 'বেতন', Company: 'কোম্পানি', 'Team & roles': 'টিম ও রোল', Settings: 'সেটিংস',
    'Plan & billing': 'প্ল্যান ও বিল', 'Activity log': 'অ্যাক্টিভিটি লগ', 'Platform admin': 'প্ল্যাটফর্ম অ্যাডমিন', 'Log out': 'লগ আউট', 'Two-factor login': 'টু-ফ্যাক্টর লগইন',
    Language: 'ভাষা', 'Install app': 'অ্যাপ ইনস্টল', 'Scan with camera': 'ক্যামেরায় স্ক্যান', Close: 'বন্ধ',
    'Subscription expired — read-only. Renew to keep working.': 'সাবস্ক্রিপশন শেষ — শুধু দেখা যাবে। কাজ চালাতে রিনিউ করুন।',
'Bill of materials': 'বিল অব ম্যাটেরিয়ালস', 'Daily attendance': 'দৈনিক হাজিরা', 'Hand over to courier': 'কুরিয়ারে হস্তান্তর',
    'Import courier payment statement': 'কুরিয়ার পেমেন্ট স্টেটমেন্ট ইমপোর্ট', 'Import orders': 'অর্ডার ইমপোর্ট', 'Mark parcels delivered': 'পার্সেল ডেলিভারড করুন', 'New order': 'নতুন অর্ডার',
    'New purchase order': 'নতুন ক্রয় অর্ডার', 'New purchase': 'নতুন ক্রয়', 'Print barcode labels': 'বারকোড লেবেল প্রিন্ট', 'Production work log': 'প্রোডাকশন কাজের হিসাব',
    'Receive courier returns': 'কুরিয়ার রিটার্ন গ্রহণ', 'Settings & integrations': 'সেটিংস ও ইন্টিগ্রেশন', 'Staff & workers': 'কর্মী ও শ্রমিক', 'Stock count (audit)': 'স্টক গণনা (অডিট)',
    'Transfer stock': 'স্টক ট্রান্সফার', 'Two-factor login (2FA)': 'টু-ফ্যাক্টর লগইন (2FA)', 'Point of sale': 'পয়েন্ট অব সেল',
    'days left in your trial': 'দিন ট্রায়াল বাকি', 'days left on your plan': 'দিন প্ল্যান বাকি', 'View plan': 'প্ল্যান দেখুন',
};

export type Locale = 'en' | 'bn';
export function useLocale(): Locale {
    const { auth, brand } = usePage().props as any;
    return ((auth?.user?.locale || brand?.locale || 'en') === 'bn' ? 'bn' : 'en');
}
export function useT() {
    const l = useLocale();
    return (s: string) => (l === 'bn' ? BN[s] ?? s : s);
}
