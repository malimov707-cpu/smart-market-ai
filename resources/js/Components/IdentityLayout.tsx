import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

export default function IdentityLayout({ title, children }: { title: string; children: ReactNode }) {
    const { flash, auth } = usePage<{ flash: { success?: string }; auth?: { is_platform_admin?: boolean } }>().props;
    return <div className="mx-auto max-w-5xl px-5 py-10"><Head title={title} /><header className="mb-8 flex flex-wrap items-center justify-between gap-4"><h1>{title}</h1><div className="flex gap-4 text-sm"><Link href={auth?.is_platform_admin ? '/admin' : '/market'}>Bosh sahifa</Link><Link href="/account">Hisob</Link><Link method="post" href="/logout" as="button">Chiqish</Link></div></header>{flash.success && <p className="success" role="status">{flash.success}</p>}<div className="grid min-w-0 grid-cols-1 gap-6">{children}</div></div>;
}
