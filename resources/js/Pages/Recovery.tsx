import { Form, Head, Link, usePage } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { Field } from '../Components/Forms';

export default function Recovery({ token, email }: { token: string | null; email: string }) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    return <div className="auth-page"><section className="auth-story"><h1>Hisobingizga<br /><em>xavfsiz qayting.</em></h1></section><section className="auth-form"><div><Head title="Parolni tiklash" /><div className="feature-icon"><KeyRound /></div><h2>Parolni tiklash</h2>{flash.success && <p className="success" role="status">{flash.success}</p>}<Form action={token ? '/reset-password' : '/forgot-password'} method="post">{({ errors, processing }) => <><Field name="email" label="Email" type="email" defaultValue={email} />{token && <><input type="hidden" name="token" value={token} /><Field name="password" label="Yangi parol (12+ belgi, harf va raqam)" type="password" /><Field name="password_confirmation" label="Yangi parolni tasdiqlang" type="password" /></>}{Object.values(errors).map((error, i) => <p key={i} role="alert" className="error">{error}</p>)}<button className="button primary" disabled={processing}>{token ? 'Parolni yangilash' : 'Tiklash havolasini yuborish'}</button></>}</Form><p className="auth-switch"><Link href="/login">Kirish sahifasiga qaytish</Link></p></div></section></div>;
}
