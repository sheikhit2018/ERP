// Same-origin JSON POST using Laravel's session + XSRF-TOKEN cookie (set on every web response).
export async function jsonApi<T>(path: string, body: unknown): Promise<T> {
    const raw = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    if (!raw) throw new Error('Session expired. Please reload the page and sign in again.');
    const response = await fetch(path, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(raw), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
    });
    const json = await response.json().catch(() => null);
    if (!response.ok) {
        const detail = json?.errors ? Object.values(json.errors).flat().join(' ') : null;
        throw new Error(detail || json?.message || `Request failed (${response.status}).`);
    }
    return json as T;
}
