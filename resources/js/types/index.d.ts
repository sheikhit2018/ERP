export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

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
    flash: { success?: string | null; error?: string | null; report?: string[] | null };
    appName: string;
    errors: Record<string, string>;
};
