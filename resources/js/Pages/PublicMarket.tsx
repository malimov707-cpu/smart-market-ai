import { Form, Link, router, useHttp, usePoll } from '@inertiajs/react';
import { ArrowUpRight, Camera, Clock3, ImagePlus, LoaderCircle, MapPin, Search, ShieldCheck, Store, X } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import Layout from '../Components/Layout';
import type { AssistantConfig } from '../Components/AiCopilot';
import { timestamp } from '../Components/PriceFacts';
import { money } from '../types';

type PublicPrice = { id: number; name: string; price: string; currency: string; unit_label: string; category: string; image: string | null; valid_until: string; card_required: boolean; conditions: string; source_url: string };
type Market = { checked_at: string | null; stale: boolean; sync_failed: boolean; total: number; products: PublicPrice[]; coverage: string };
type Recognition = { name: string; brand: string | null; package: string | null; uncertain: boolean; search_terms: string[] };
export default function PublicMarket({ market, filters, assistant }: { market: Market; filters: { q: string }; assistant: AssistantConfig }) {
    usePoll(1000, { only: ['market'] });
    const upload = useHttp<{ image: File | null }, { recognition: Recognition; market: Market }>({ image: null });
    const [preview, setPreview] = useState('');
    const [recognition, setRecognition] = useState<Recognition | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        if (!upload.data.image) { setPreview(''); return; }
        const url = URL.createObjectURL(upload.data.image); setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [upload.data.image]);
    async function scan(event: FormEvent) {
        event.preventDefault(); setError('');
        let handled = false;
        try {
            const response = await upload.post('/market/scan', {
                onError: errors => { handled = true; setError(Object.values(errors).join(' ')); },
                onHttpException: response => {
                    handled = true;
                    let message = response.status === 429 ? 'So‘rov limiti tugadi. Bir daqiqadan so‘ng qayta urinib ko‘ring.' : 'Suratni tahlil qilib bo‘lmadi. Qayta urinib ko‘ring.';
                    try { const body = typeof response.data === 'string' ? JSON.parse(response.data) : response.data; if (typeof body?.message === 'string') message = body.message; } catch {}
                    setError(message); return false;
                },
                onNetworkError: () => { handled = true; setError('Ulanish uzildi. Qayta urinib ko‘ring.'); return false; },
            });
            setRecognition(response.recognition);
            router.get('/market', { q: response.recognition.search_terms[0] || response.recognition.name }, { preserveState: true, preserveScroll: true });
        } catch { if (!handled) setError('Tahlil bajarilmadi. Boshqa surat bilan urinib ko‘ring.'); }
    }
    return <Layout title="Toshkent narxlari" action={<span className="market-location"><MapPin size={15} />Toshkent shahri va viloyati</span>}>
        <section className="market-hero"><div><p className="eyebrow">SMART MARKET · KO‘RING. TOPING. SOLISHTIRING.</p><h2>Bir surat.<br /><em>Haqiqiy narx manbalari.</em></h2><p>Mahsulotni suratga oling yoki nomini yozing.<br />Korzinka ochiq katalogidagi mos takliflarni toping.</p><div className="market-trust"><ShieldCheck size={15} />AI narx to‘qimaydi — narxlar katalogdan olinadi.</div></div><form className="market-camera" onSubmit={scan}><label className={`camera-drop ${preview ? 'has-preview' : ''}`}>
            {preview ? <img src={preview} alt="Tanlangan mahsulot surati" /> : <><Camera size={40} strokeWidth={1.2} /><strong>Mahsulotni suratga oling</strong><span>yoki rasmni tanlang · JPG, PNG, WebP · 4 MB</span></>}
            <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" aria-label="Mahsulot rasmini tanlash" disabled={upload.processing} onChange={event => { upload.setData('image', event.target.files?.[0] ?? null); setRecognition(null); setError(''); }} />
        </label><div className="camera-actions"><span><ImagePlus size={14} />Rasm saqlanmaydi; OpenRouter’ga yuboriladi</span><button className="button primary" disabled={!upload.data.image || upload.processing || !assistant.configured}>{upload.processing ? <LoaderCircle size={16} className="animate-spin" /> : <Search size={16} />}{upload.processing ? 'Tanilmoqda…' : 'Suratdan topish'}</button></div>{!assistant.configured && <small>Suratni tanish uchun OpenRouter kaliti sozlanishi kerak.</small>}</form></section>
        {error && <p className="error" role="alert">{error}</p>}
        {recognition && <section className="recognition-result"><Camera size={23} /><div><small>AI tanigan mahsulot · {recognition.uncertain ? 'Aniqlashtirish kerak' : 'Mosligini tekshiring'}</small><strong>{recognition.name}{recognition.brand ? ` · ${recognition.brand}` : ''}{recognition.package ? ` · ${recognition.package}` : ''}</strong><p>Quyidagilar qidiruvga mos nomzodlar. Brend, nav va qadoq aynan mosligini tekshiring.</p></div><button aria-label="Tanish natijasini yopish" onClick={() => setRecognition(null)}><X size={17} /></button></section>}
        <section className="panel market-search-panel"><Form action="/market" method="get" options={{ preserveScroll: true }} className="market-search"><Search size={21} /><input name="q" aria-label="Bozor mahsulotini qidirish" placeholder="Mahsulot nomi: olma, sut, yog‘…" defaultValue={filters.q} key={filters.q} /><button className="button primary">Narxlarni topish</button></Form><div className="market-suggestions">{['Olma', 'Sut', 'Guruch', 'Yog', 'Tuxum'].map(name => <Link href={`/market?q=${encodeURIComponent(name)}`} key={name}>{name}</Link>)}{filters.q && <Link href="/market">Barcha mahsulotlar</Link>}</div></section>
        <div className="market-source-grid"><section className="panel"><Store size={23} /><div><strong>Korzinka katalogi</strong><small>{market.checked_at ? `${market.total} ta qidiruv natijasi` : 'Birinchi sinxronizatsiya kutilmoqda'}</small></div><span className={`badge ${market.stale || market.sync_failed ? 'amber' : 'green'}`}>{market.checked_at ? market.stale || market.sync_failed ? 'Tekshirish kerak' : 'Katalog olindi' : 'Kutilmoqda'}</span></section><section className="panel"><Store size={23} /><div><strong>Uzum Market</strong><small>Narx manbasi hali ulanmagan</small></div><span className="badge neutral">Ulanmagan</span></section><section className="panel"><MapPin size={23} /><div><strong>Dehqon bozorlari</strong><small>Jonli sotuvchi ma’lumoti hali yo‘q</small></div><a href="https://toshstat.uz/uz/rasmiy-statistika/prices-and-indexes-2" target="_blank" rel="noreferrer" className="badge neutral">Rasmiy o‘rtacha <ArrowUpRight size={11} className="inline" /></a></section></div>
        <div className="market-status"><span><Clock3 size={14} />{market.checked_at ? `Manba tekshirildi: ${timestamp(market.checked_at)}` : 'Katalog hali yuklanmagan'}</span><span>Ilova: 1 soniyada tekshiradi · manba: har 5 daqiqada</span></div>
        {(market.stale || market.sync_failed) && market.checked_at && <p className="notice">Yangilash kechikkan yoki manba javob bermagan. Oxirgi muvaffaqiyatli katalog ko‘rsatilmoqda; narxni sotuvchidan tekshiring.</p>}
        <div className="section-header"><div><h2>{filters.q ? `“${filters.q}” uchun topilgan takliflar` : 'Korzinka katalogidagi takliflar'}</h2><p>{market.coverage}</p></div></div>
        <div className="public-price-grid">{market.products.map(product => <article className="public-price-card" key={product.id}><div className="public-product-image">{product.image ? <img src={product.image} alt={product.name} loading="lazy" referrerPolicy="no-referrer" /> : <Store size={35} />}<span className="public-source">Korzinka</span></div><div className="public-product-body"><small>{product.category}</small><h3>{product.name}</h3><p className="public-unit">{product.unit_label}</p><strong className="public-product-price">{money(product.price)}<small> so‘m</small></strong>{product.card_required && <p className="public-condition">Korzinka kartasi talab etiladi</p>}{product.conditions && <p className="public-condition">{product.conditions}</p>}<p className="public-expiry">Amal qiladi: {timestamp(product.valid_until)}</p><a className="decision-link" href={product.source_url} target="_blank" rel="noreferrer">Manbada tekshirish <ArrowUpRight size={15} /></a></div></article>)}</div>
        {!market.products.length && <section className="panel intelligence-empty"><Search size={30} /><h2>{market.checked_at ? 'Bu qidiruv uchun katalogda mos taklif topilmadi.' : 'Korzinka katalogi yuklanishi kutilmoqda.'}</h2><p>{market.checked_at ? 'Bu mahsulot bozorda yo‘q degani emas. Umumiyroq nom bilan qidiring; katalog barcha assortimentni qamramaydi.' : 'Administrator market:sync orqali manbani yangilashi mumkin.'}</p></section>}
        {market.total > 60 && <p className="notice">Dastlabki 60 ta natija ko‘rsatilgan. Qidiruvni aniqroq yozing.</p>}
        <p className="market-disclaimer">Narxlar so‘mda. Yetkazish narxi kiritilmagan. Aksiya shartlari, filial, nav, brend va qadoqqa qarab farq bo‘lishi mumkin. Bu butun Toshkent bozorining o‘rtacha narxi emas.</p>
    </Layout>;
}
