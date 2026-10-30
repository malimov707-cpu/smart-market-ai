import { Form, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { Pagination } from '../types';

export function Field({ label, name, type = 'text', required = true, children, ...props }: { label: string; name: string; type?: string; required?: boolean; children?: ReactNode; defaultValue?: string; min?: string; max?: string; step?: string; placeholder?: string }) {
    return <label className="field"><span>{label}{!required && <small> (ixtiyoriy)</small>}</span>{children ? <select name={name} required={required} {...props}>{children}</select> : <input name={name} type={type} required={required} {...props} />}</label>;
}
export function SaveForm({ action, children, label = 'Saqlash' }: { action: string; children: ReactNode; label?: string }) {
    return <Form action={action} method="post" resetOnSuccess>{({ errors, processing }) => <><div className="form-grid">{children}</div>{Object.entries(errors).map(([key, error]) => <p className="error" role="alert" key={key}>{error}</p>)}<button className="button primary" disabled={processing}>{processing ? 'Saqlanmoqda…' : label}</button></>}</Form>;
}
export function Pager<T>({ page }: { page: Pagination<T> }) {
    return <div className="pager"><span>{page.total} ta yozuv</span><div>{page.links.map((link, i) => link.url ? <Link key={i} href={link.url} className={link.active ? 'selected' : ''}>{link.label.replace(/&laquo;|&raquo;/g, '').replace('Previous', 'Oldingi').replace('Next', 'Keyingi')}</Link> : null)}</div></div>;
}
