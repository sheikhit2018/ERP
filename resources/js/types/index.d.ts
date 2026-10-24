export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    is_super_admin?: boolean;
    locale?: string | null;
}

export type Brand = { logo: string | null; address: string | null; phone: string | null; email: string | null; invoice_note: string | null; locale: string };
export type SubscriptionState = { plan: { id: number; name: string; price_minor: number; users_limit: number | null; orders_limit: number | null; locations_limit: number | null } | null;
    status: string; until: string | null; days_left: number | null; read_only: boolean; suspended: boolean };

export type Role = 'owner' | 'manager' | 'sales' | 'warehouse' | 'manufacturer' | 'qc';

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: { user: User };
    tenant: { name: string } | null;
    role: Role | null;
    flash: { success?: string | null; error?: string | null; report?: string[] | null; pos_sale?: number | null };
    appName: string;
    brand: Brand | null;
    subscription: SubscriptionState | null;
    errors: Record<string, string>;
};
