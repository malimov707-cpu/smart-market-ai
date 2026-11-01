export type Product = { id: number; name: string; sku: string; unit: string; category: string | null };
export type Supplier = { id: number; name: string; contact: string | null; city: string; verification_status?: string; platform_verification_status?: string };
export type Offer = { source_type?: string; id: number; product: Product; supplier: Supplier; price: string; currency: string; unit: string; package_quantity: string; observed_at: string; expires_at: string | null; provenance: string; source_reference: string; delivery_fee: string | null };
export type Pagination<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
export type Shared = { auth: { name: string; email: string; is_platform_admin: boolean; email_verified_at?: string | null }; organization: { id: number; name: string; city: string; pivot: { role: string } }; organizations: { id: number; name: string }[]; flash: { success?: string }; [key: string]: unknown };
export const money = (value: string) => {
    const [whole, fraction = '00'] = value.split('.');
    const grouped = BigInt(whole).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
    return fraction === '00' ? grouped : `${grouped},${fraction.padEnd(2, '0')}`;
};
export const date = (value: string) => new Intl.DateTimeFormat('en-GB', { dateStyle: 'short', timeZone: 'Asia/Tashkent' }).format(new Date(value));
