import { Form, usePage } from '@inertiajs/react';
import IdentityLayout from '../Components/IdentityLayout';
import { date } from '../types';

export default function Invitation({ invitation }: { invitation: { organization: string; role: string; expires_at: string } }) {
    const path = usePage().url.split('?')[0];
    return <IdentityLayout title="Tashkilotga taklifnoma"><section className="panel"><h2>{invitation.organization}</h2><p>Taklif qilingan rol: <strong>{invitation.role}</strong>. Amal qilish muddati: {date(invitation.expires_at)}. Qabul qilish tashkilotga a’zolik beradi.</p><Form action={`${path}/accept`} method="post">{({ processing, errors }) => <>{Object.values(errors).map((error, i) => <p className="error" key={i}>{error}</p>)}<button className="button primary" disabled={processing}>Taklifni qabul qilish</button></>}</Form></section></IdentityLayout>;
}
