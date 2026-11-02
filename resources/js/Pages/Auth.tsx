import { Form, Head, Link } from '@inertiajs/react';
import { Activity, ArrowRight, BarChart3, Sparkles, Trophy } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Field } from '../Components/Forms';
import ThemeToggle from '../Components/ThemeToggle';

type Providers = {
    google: boolean;
    telegram: boolean;
    telegramUsername: string;
    telegramCallback: string;
};

export default function Auth({ register, providers }: { register: boolean; providers: Providers }) {
    return <div className={`auth-page${register ? ' auth-page-register' : ''}`}>
        <Head title={register ? 'Ro‘yxatdan o‘tish' : 'Kirish'} />
        <section className="auth-story">
            <div className="brand"><span className="brand-icon"><Activity /></span>smartmarket AI</div>
            <div className="auth-story-copy">
                <p className="eyebrow">XARIDNING YANGI IMKONIYATLARI</p>
                <h1>Xaridni osonroq.<br />Qarorni <em>aniqroq</em><br />qiling.</h1>
                <p>Bozordagi narxlarni solishtiring, mahsulotlarni suratdan toping va xaridni ishonchli ma’lumot bilan rejalashtiring.</p>
                <ul className="auth-feature-list">
                    <li><span><Trophy size={17} /></span>Tekshirilgan manbalardan narxlar</li>
                    <li><span><BarChart3 size={17} /></span>Narxlar va bozor tahlili</li>
                    <li><span><Sparkles size={17} /></span>AI orqali mahsulot qidirish</li>
                </ul>
            </div>
            <blockquote className="auth-quote">
                “Bozor narxlarini solishtirish va oqilona xarid qilish endi osonroq.”
                <cite>— Smart Market AI</cite>
            </blockquote>
        </section>
        <section className="auth-form">
            <div>
                <div className="auth-theme-control"><ThemeToggle /></div>
                <Link className="auth-back" href="/">← Bosh sahifaga qaytish</Link>
                <p className="eyebrow">{register ? 'YANGI HISOB' : 'SMART MARKET AI GA XUSH KELIBSIZ'}</p>
                <h2>{register ? 'Hisob yarating' : 'Hisobingizga kiring'}</h2>
                <p>{register ? 'Ro‘yxatdan o‘ting va Toshkentdagi narxlarni bilib boring.' : 'Xaridlaringiz haqidagi ma’lumotlar bir joyda.'}</p>
                {register && <>
                    <div className="auth-social-grid">
                        {providers.google
                            ? <a className="button secondary auth-social-button" href="/auth/google"><strong className="google-mark">G</strong>Google</a>
                            : <button className="button secondary auth-social-button" disabled title="GOOGLE_CLIENT_ID va GOOGLE_CLIENT_SECRET sozlang"><strong className="google-mark">G</strong>Google</button>}
                        {providers.telegram
                            ? <TelegramLoginWidget username={providers.telegramUsername} callback={providers.telegramCallback} />
                            : <button className="button secondary auth-social-button" disabled>Telegram bilan kirish</button>}
                    </div>
                    <div className="auth-divider"><span />yoki email bilan<span /></div>
                </>}
                <Form action={register ? '/register' : '/login'} method="post" aria-label={register ? 'Hisob yaratish' : 'Hisobga kirish'}>
                    {({ errors, processing }) => <>
                        {register && <Field name="name" label="Ismingiz" />}
                        <Field name="email" type="email" label="Email" />
                        <Field name="password" type="password" label="Parol" />
                        {register && <>
                            <small>Kamida 12 belgi, harf va raqam.</small>
                            <Field name="password_confirmation" type="password" label="Parolni tasdiqlang" />
                        </>}
                        {Object.entries(errors).map(([key, value]) => <p role="alert" className="error" key={key}>{value}</p>)}
                        <button className="button primary auth-submit" disabled={processing}>
                            {processing ? 'Kutilmoqda…' : register ? 'Hisob yaratish' : 'Kirish'}<ArrowRight size={17} />
                        </button>
                    </>}
                </Form>
                {!register && <details className="mt-6">
                    <summary>Faolsiz hisobni qayta faollashtirish</summary>
                    <Form action="/account/reactivate" method="post">
                        {({ processing, errors }) => <>
                            <Field name="email" label="Email" type="email" />
                            <Field name="password" label="Parol" type="password" />
                            {Object.values(errors).map((error, index) => <p key={index} role="alert" className="error">{error}</p>)}
                            <button className="button secondary" disabled={processing}>Hisobni qayta faollashtirish</button>
                        </>}
                    </Form>
                </details>}
                {!register && <p className="auth-switch"><Link href="/forgot-password">Parolni unutdingizmi?</Link></p>}
                <p className="auth-switch">{register ? 'Hisobingiz bormi?' : 'Hali hisobingiz yo‘qmi?'} <Link href={register ? '/login' : '/register'}>{register ? 'Kirish' : 'Ro‘yxatdan o‘tish'}</Link></p>
            </div>
        </section>
    </div>;
}

function TelegramLoginWidget({ username, callback }: { username: string; callback: string }) {
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const element = container.current;
        if (!element) {
            return;
        }

        const script = document.createElement('script');
        script.src = 'https://telegram.org/js/telegram-widget.js?22';
        script.async = true;
        script.setAttribute('data-telegram-login', username);
        script.setAttribute('data-size', 'large');
        script.setAttribute('data-radius', '8');
        script.setAttribute('data-auth-url', callback);
        script.setAttribute('data-request-access', 'write');
        element.replaceChildren(script);

        return () => element.replaceChildren();
    }, [username, callback]);

    return <div ref={container} className="telegram-login-button" aria-label="Log in with Telegram" />;
}
