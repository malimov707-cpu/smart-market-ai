import { Link, Form } from '@inertiajs/react';
import IdentityLayout from '../Components/IdentityLayout';

export default function VerifyEmail({ mailAvailable }: { mailAvailable: boolean }) {
    return <IdentityLayout title="Emailni tasdiqlang"><section className="panel">{!mailAvailable && <p className="notice">Email transporti sozlanmagan. Administrator mail xizmatini sozlaguncha tasdiqlash xati yuborilmaydi. Maxfiy havolalar logga yozilmaydi.</p>}<h2>Ish maydoniga kirish uchun emailni tasdiqlash kerak</h2><p>Emailga kelgan havolani oching. Xat topilmasa spam papkasini tekshiring yoki qayta yuboring. Email manzilini hisob sozlamalarida tuzatishingiz mumkin.</p><Form action="/email/verification-notification" method="post">{({ processing, errors }) => <>{Object.values(errors).map((error, i) => <p className="error" key={i}>{error}</p>)}<button className="button primary" disabled={processing}>Tasdiqlash xatini qayta yuborish</button></>}</Form><p className="mt-5"><Link href="/account">Email manzilini o‘zgartirish</Link></p></section></IdentityLayout>;
}
