import { Form, Link, router, useHttp, usePoll } from '@inertiajs/react';
import { ArrowUpRight, Bell, Camera, Clock3, ImagePlus, LoaderCircle, MapPin, Search, ShieldCheck, Store, Upload, X } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import Layout from '../Components/Layout';
import type { AssistantConfig } from '../Components/AiCopilot';
import { timestamp } from '../Components/PriceFacts';
import { money } from '../types';
import Sparkline from '../Components/Sparkline';

type PublicPrice = { id: number | string; key: string; source_id: string; source_name: string; checked_at: string | null; availability: string; name: string; price: string; currency: string; unit_label: string; category: string; image: string | null; valid_until: string | null; card_required: boolean; conditions: string; source_url: string; data_period?: string | null };
type MarketSource = { id: string; name: string; department: string; checked_at: string | null; count: number; dataset_count?: number; stale: boolean; sync_failed: boolean; partial?: boolean; enabled?: boolean; interval: number; on_demand?: boolean; official_average?: boolean; data_period?: string | null };
type Market = { sources: MarketSource[]; checked_at: string | null; stale: boolean; sync_failed: boolean; partial: boolean; total: number; products: PublicPrice[]; coverage: string };
type Recognition = { name: string; brand: string | null; package: string | null; uncertain: boolean; search_terms: string[] };
type Insight = { query: string; best: { name: string; price: string; currency: string; source_name: string; source_url: string; unit_label: string }; worst: { name: string; price: string }; savings: string; savings_percent: number; offers: number; sources: string[] };
type Trend = { series: { date: string; price: number }[]; change: number | null };
type Deal = { query: string; price: number; percent: number; previous_average: number };
type Watch = { watching: boolean; threshold: string };
export default function PublicMarket({ market, filters, assistant, verification, trend, watch, deal }: { verification: { verified: boolean; required: boolean; canUse: boolean; mailAvailable: boolean }; market: Market; filters: { q: string; terms: string[]; department: string; source: string }; assistant: AssistantConfig; trend: Trend | null; watch: Watch | null; deal: Deal | null }) {
    const catalogGroups = market.sources
        .filter(source => filters.department === 'all' || source.department === filters.department)
        .filter(source => filters.source === 'all' || source.id === filters.source)
        .map(source => ({
            ...source,
            products: market.products.filter(product => product.source_id === source.id),
        }))
        .filter(source => source.products.length > 0);
    const uncategorizedProducts = market.products.filter(product => !market.sources.some(source => source.id === product.source_id));

    usePoll(30_000, { only: ['market'] });
    const upload = useHttp<{ image: File | null }, { recognition: Recognition; insight: Insight | null; market: Market }>({ image: null });
    const fileInput = useRef<HTMLInputElement>(null);
    const video = useRef<HTMLVideoElement>(null);
    const [cameraStream, setCameraStream] = useState<MediaStream | null>(null);
    const [cameraOpen, setCameraOpen] = useState(false);
    const [cameraFallback, setCameraFallback] = useState(false);
    const [preview, setPreview] = useState('');
    const [recognition, setRecognition] = useState<Recognition | null>(null);
    const [insight, setInsight] = useState<Insight | null>(null);
    const [error, setError] = useState('');
    useEffect(() => {
        if (!upload.data.image) { setPreview(''); return; }
        const url = URL.createObjectURL(upload.data.image); setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [upload.data.image]);
    useEffect(() => {
        if (!cameraStream || !cameraOpen || !video.current) return;
        video.current.srcObject = cameraStream;
        return () => cameraStream.getTracks().forEach(track => track.stop());
    }, [cameraOpen, cameraStream]);
    function selectImage(image: File | null) {
        closeCamera();
        upload.setData('image', image);
        setRecognition(null);
        setInsight(null);
        setError('');
        setCameraFallback(false);
    }
    async function openCamera() {
        setError('');
        setCameraFallback(false);
        if (!navigator.mediaDevices?.getUserMedia) {
            setError('Brauzer bevosita kamera oqimini bermadi. Qurilma kamerasi tanlovini oching; bevosita kamera uchun HTTPS yoki localhost va kamera ruxsati kerak.');
            setCameraFallback(true);
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
            setCameraStream(stream);
            setCameraOpen(true);
        } catch {
            setError('Kameraga ruxsat berilmadi yoki kamera topilmadi. Ruxsatni tekshiring yoki fayldan tanlang.');
            setCameraFallback(true);
        }
    }
    function closeCamera() {
        setCameraOpen(false);
        setCameraStream(null);
    }
    function capturePhoto() {
        const element = video.current;
        if (!element || element.videoWidth === 0 || element.videoHeight === 0) {
            setError('Kamera tasviri hali tayyor emas. Biroz kutib qayta oling.');
            return;
        }
        const canvas = document.createElement('canvas');
        const scale = Math.min(1, 1600 / Math.max(element.videoWidth, element.videoHeight));
        canvas.width = Math.max(1, Math.round(element.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(element.videoHeight * scale));
        const context = canvas.getContext('2d');
        if (!context) {
            setError('Kamera suratini tayyorlab bo‘lmadi. Qayta urinib ko‘ring.');
            return;
        }
        context.drawImage(element, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(blob => {
            if (!blob) {
                setError('Kamera suratini tayyorlab bo‘lmadi. Qayta urinib ko‘ring.');
                return;
            }
            selectImage(new File([blob], `product-${Date.now()}.jpg`, { type: 'image/jpeg' }));
            closeCamera();
        }, 'image/jpeg', 0.86);
    }
    async function scan(event: FormEvent) {
        event.preventDefault(); setError('');
        if (!verification.canUse) { setError('Suratdan qidirish uchun avval emailni tasdiqlang.'); return; }
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
            setInsight(response.insight ?? null);
            router.get('/market', {
                q: response.recognition.name,
                terms: response.recognition.search_terms,
                department: 'all',
                source: 'all',
            }, { preserveState: true, preserveScroll: true });
        } catch { if (!handled) setError('Tahlil bajarilmadi. Boshqa surat bilan urinib ko‘ring.'); }
    }
    return <Layout title="Toshkent narxlari" action={<span className="market-location"><MapPin size={15} />Toshkent shahri va viloyati</span>}>
        {verification.required && !verification.verified && <section className="panel verification-banner" aria-label="Hisob tasdig‘i"><div><ShieldCheck size={23} /><h2>Narxlar panelingiz tayyor</h2></div><p>Ommaviy katalog narxlarini hoziroq qidirishingiz mumkin. Suratdan qidirish va ichki ish maydoni uchun emailni tasdiqlang.</p>{!verification.mailAvailable && <p className="notice">Email xizmati hali sozlanmagan. Hozir narxlarni nomi orqali qidirishingiz mumkin; tasdiqlash xati xizmat sozlangach yuboriladi.</p>}<div className="verification-actions"><Link className="button secondary" href="/account">Emailni tekshirish</Link><Form action="/email/verification-notification" method="post">{({ processing, errors }) => <><button className="button primary" disabled={processing || !verification.mailAvailable}>{processing ? 'Yuborilmoqda…' : 'Tasdiqlash xatini yuborish'}</button>{Object.values(errors).map(error => <p className="error" role="alert" key={error}>{error}</p>)}</>}</Form></div></section>}
        <section className="market-hero"><div><p className="eyebrow">SMART MARKET · KO‘RING. TOPING. SOLISHTIRING.</p><h2>Bir surat.<br /><em>Haqiqiy narx manbalari.</em></h2><p>Mahsulotni kamera bilan suratga oling yoki rasm faylini yuklang.<br />Oziq-ovqat, qurilish va boshqa mahsulot kataloglaridan qidiring.</p><div className="market-trust"><ShieldCheck size={15} />AI narx to‘qimaydi — narxlar katalogdan olinadi.</div></div><form className="market-camera" onSubmit={scan}><div className={`camera-drop ${preview ? 'has-preview' : ''}`}>
            {preview ? <img src={preview} alt="Tanlangan mahsulot surati" /> : <><ImagePlus size={40} strokeWidth={1.2} /><strong>Mahsulot rasmini tanlang</strong><span>JPG, PNG, WebP · 4 MB gacha</span></>}
        </div><input id="camera-capture" className="camera-file-input" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" aria-label="Kamera bilan suratga olish" disabled={upload.processing} onChange={event => { selectImage(event.target.files?.[0] ?? null); event.target.value = ''; }} /><input ref={fileInput} className="camera-file-input" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Rasm faylini yuklash" disabled={upload.processing} onChange={event => { selectImage(event.target.files?.[0] ?? null); event.target.value = ''; }} />
        <div className="camera-pick-actions"><button className="button secondary" type="button" disabled={upload.processing || cameraOpen} onClick={() => { void openCamera(); }}><Camera size={16} />Kamera bilan olish</button><button className="button secondary" type="button" disabled={upload.processing || cameraOpen} onClick={() => fileInput.current?.click()}><Upload size={16} />Fayldan yuklash</button></div>
        {cameraOpen && <div className="camera-live" role="dialog" aria-modal="true" aria-label="Mahsulotni suratga olish"><video ref={video} autoPlay playsInline muted /><div><button className="button secondary" type="button" onClick={closeCamera}>Bekor qilish</button><button className="button primary" type="button" onClick={capturePhoto}><Camera size={16} />Suratga olish</button></div></div>}
        <div className="camera-actions"><span><ImagePlus size={14} />Rasm saqlanmaydi; OpenRouter’ga yuboriladi</span><button className="button primary" disabled={!upload.data.image || upload.processing || !assistant.configured || !verification.canUse}>{upload.processing ? <LoaderCircle size={16} className="animate-spin" /> : <Search size={16} />}{upload.processing ? 'AI tahlil qilmoqda…' : 'AI bilan mahsulotni topish'}</button></div>{verification.required && !verification.verified && <small>Suratdan qidirish email tasdiqlangach ochiladi. <Link href="/verify-email">Emailni tasdiqlash</Link></small>}{!assistant.configured && <small className="error" role="status">AI rasm tanishi hozir ishlamaydi: serverda OPENROUTER_API_KEY sozlanmagan. Admin .env fayliga kalit qo‘shib, config cache’ni yangilashi kerak.</small>}</form></section>
        {error && <div className="camera-feedback"><p className="error" role="alert">{error}</p>{cameraFallback && <label htmlFor="camera-capture" className="button secondary"><Camera size={16} />Qurilma kamerasi orqali olish</label>}</div>}
        {deal && <section className="deal-banner" role="status" aria-label="Xarid oynasi"><span className="deal-flame">🔥</span><div><strong>Xarid oynasi: {deal.query} −{deal.percent}%</strong><p>Eng arzon narx o‘rtacha {deal.previous_average.toLocaleString('uz-UZ')} so‘mdan <b>{deal.price.toLocaleString('uz-UZ')} so‘m</b>ga tushdi. Bugun xarid uchun yaxshi kun.</p></div><Link className="button light" href={`/market/product/${deal.query.replace(/\s+/g, '-')}`}>Batafsil</Link></section>}
        {recognition && insight && <section className="panel insight-banner-deal" aria-label="Narx xulosasi"><div className="insight-deal-head"><span className="badge green">NARX FARQI TOPILDI</span><small>{insight.offers} ta taklif · {insight.sources.join(', ')}</small></div><div className="insight-deal-grid"><div><small>Eng arzon</small><strong>{money(insight.best.price)}<small> so‘m</small></strong><p>{insight.best.name}</p><span className="badge neutral">{insight.best.source_name}</span></div><div><small>Eng qimmat</small><strong>{money(insight.worst.price)}<small> so‘m</small></strong><p>{insight.worst.name}</p></div><div className="insight-deal-savings"><small>Bitta to‘g‘ri tanlov bilan tejaladi</small><strong>{money(insight.savings)}<small> so‘m</small></strong><span className="badge amber">−{insight.savings_percent}%</span></div></div><small className="insight-deal-note">Narxlar manba kataloglaridan olingan; qadoq va birlik farqi bo‘lishi mumkin.</small></section>}
        {recognition && <section className="recognition-result"><Camera size={23} /><div><small>{recognition.uncertain ? 'AI taxmini · tekshirib ko‘ring' : 'AI aniqlagan mahsulot · tekshirib ko‘ring'}</small><strong>{recognition.name}{recognition.brand ? ` · ${recognition.brand}` : ''}{recognition.package ? ` · ${recognition.package}` : ''}</strong><p>{recognition.uncertain ? 'AI rasmga qarab taxmin qildi. Mahsulot nomi yoki turi noto‘g‘ri bo‘lishi mumkin; kerak bo‘lsa, qidiruv maydonida nomini o‘zgartiring.' : 'Mahsulot nomi, brendi va qadoq hajmi mosligini tekshiring. Narxlar mos mahsulotlardan olinadi.'}</p></div><button aria-label="Tanish natijasini yopish" onClick={() => { setRecognition(null); setInsight(null); }}><X size={17} /></button></section>}
        <nav className="market-departments" aria-label="Mahsulot yo‘nalishi">{[{ id: 'all', label: 'Barcha yo‘nalishlar' }, { id: 'grocery', label: 'Oziq-ovqat va kundalik' }, { id: 'construction', label: 'Qurilish mollari' }, { id: 'general', label: 'Elektronika, uy-ro‘zg‘or va boshqalar' }].map(item => <Link href={marketHref(item.id, 'all', filters.q, filters.terms)} key={item.id} className={filters.department === item.id ? 'selected' : ''}>{item.label}</Link>)}</nav>
<section className="panel market-search-panel"><Form action="/market" method="get" options={{ preserveScroll: true }} className="market-search"><input type="hidden" name="department" value={filters.department} /><input type="hidden" name="source" value={filters.source} /><Search size={21} /><input name="q" aria-label="Bozor mahsulotini qidirish" placeholder="Mahsulot nomi: AirPods, telefon, sut yoki sement…" defaultValue={filters.q} key={filters.q} /><button className="button primary">Narxlarni topish</button></Form><div className="market-suggestions">{(filters.department === 'construction' ? ['Sement', 'Gipsokarton', 'Laminat', 'Germetik', 'Yelim'] : filters.department === 'general' ? ['Quloqchin', 'Telefon', 'Termos', 'Changyutgich', 'Kitob'] : ['Olma', 'Sut', 'Sement', 'Quloqchin', 'Telefon', 'Kitob']).map(name => <Link href={`/market?department=${filters.department}&q=${encodeURIComponent(name)}`} key={name}>{name}</Link>)}<Link href="/market/products" className="product-index-link">Barcha kuzatuvlar →</Link>{(filters.q || filters.source !== 'all') && <Link href={`/market?department=${filters.department}`}>Filtrlarni tozalash</Link>}</div></section>
        <div className="market-source-grid">{market.sources.filter(source => filters.department === 'all' || source.department === filters.department).map(source => {
            const details = source.official_average
                ? source.checked_at
                    ? source.count > 0
                        ? `${source.count} ta mos mahsulot · jami ${source.dataset_count ?? 0} ta kuzatuv · ${marketPeriod(source.data_period)} oylik o‘rtacha`
                        : `${source.dataset_count ?? 0} ta tovar kuzatiladi · davr: ${marketPeriod(source.data_period)} · bu qidiruvga mos o‘rtacha narx yo‘q`
                    : 'Toshkent bozorlaridagi oylik rasmiy o‘rtacha narxlar'
                : source.sync_failed
                    ? source.checked_at
                        ? `${source.count} ta oxirgi saqlangan taklif · manbadan yangilab bo‘lmadi`
                        : 'Manbadan hozir javob yo‘q; tekshirilgan taklif mavjud emas'
                : source.on_demand
                    ? !source.enabled
                        ? 'Manba shartlari tasdiqlanmagani uchun katalog hozircha o‘chirilgan.'
                        : source.partial
                            ? `${source.count} ta taklif; qidiruv to‘liq emas`
                            : source.checked_at
                                ? `${source.count} ta mos taklif · qidiruvda tekshirildi`
                                : 'Ochiq mahsulot sahifalari qidiruvda tekshiriladi'
                    : source.checked_at ? `${source.count} ta katalog taklifi · ${source.interval} daqiqa` : 'Katalog hali olinmagan';
            const badge = source.official_average
                ? source.sync_failed
                    ? 'Ma’lumot yangilanmadi'
                    : source.stale
                        ? 'Eski ma’lumot'
                        : source.checked_at && source.count === 0
                            ? 'Mos mahsulot yo‘q'
                    : source.checked_at
                        ? 'Rasmiy o‘rtacha'
                        : 'Oylik statistika'
                : !source.enabled && source.on_demand
                    ? 'Ruxsat kutilmoqda'
                    : source.sync_failed
                        ? 'Manba javob bermadi'
                        : source.partial
                            ? 'Qisman qidiruv'
                            : source.stale
                                ? source.checked_at ? 'Ma’lumot eskirgan' : 'Katalog yangilanmagan'
                                : source.checked_at
                                    ? 'Katalog olindi'
                                    : source.on_demand
                                        ? 'Qidiruvda'
                                        : 'Kutilmoqda';
            const card = <><Store size={23} /><div><strong>{source.name}</strong><small>{details}</small>{source.checked_at && <small>Yangilangan: {timestamp(source.checked_at)}</small>}</div><span className={`badge ${source.official_average ? source.stale || source.sync_failed ? 'amber' : 'neutral' : !source.enabled && source.on_demand || source.sync_failed || source.stale || source.partial ? 'amber' : source.on_demand && !source.checked_at ? 'neutral' : 'green'}`}>{badge}</span></>;

            return source.enabled === false
                ? <section className="panel source-filter" key={source.id}>{card}</section>
                : <Link className={`panel source-filter ${filters.source === source.id ? 'source-selected' : ''}`} href={marketHref(source.department, source.id, filters.q, filters.terms)} key={source.id}>{card}</Link>;
        })}{filters.department !== 'construction' && filters.department !== 'general' && <><section className="panel"><Store size={23} /><div><strong>Uzum Market</strong><small>Narx manbasi hali ulanmagan</small></div><span className="badge neutral">Ulanmagan</span></section><section className="panel"><MapPin size={23} /><div><strong>Dehqon bozorlari</strong><small>Jonli sotuvchi ma’lumoti hali yo‘q</small></div><a href="https://toshstat.uz/uz/rasmiy-statistika/prices-and-indexes-2" target="_blank" rel="noreferrer" className="badge neutral">Rasmiy o‘rtacha <ArrowUpRight size={11} className="inline" /></a></section></>}</div>
        <div className="market-status"><span><Clock3 size={14} />{market.checked_at ? `Eng so‘nggi manba tekshiruvi: ${timestamp(market.checked_at)}` : 'Katalog hali yuklanmagan'}</span><span>Korzinka: 5 daqiqa · qurilish: 15 daqiqa · Stat.uz: oylik o‘rtacha · Asaxiy: mahsulot qidirilganda</span></div>
        {(market.stale || market.sync_failed) && <p className="notice">{market.sync_failed
            ? market.checked_at
                ? 'Manba javob bermadi. Oxirgi muvaffaqiyatli katalog saqlangan; narxni sotuvchida qayta tekshiring.'
                : 'Manba javob bermadi; hozircha tekshirilgan taklif yo‘q.'
            : 'Katalogni yangilash jadvali kechikdi. Mahalliy ishlashda alohida terminalda php artisan schedule:work, serverda esa Laravel scheduler ishlayotganini tekshiring. Oxirgi saqlangan narxlar ko‘rsatilmoqda; xariddan oldin sotuvchida tasdiqlang.'}</p>}
        {market.partial && <p className="notice">{market.sync_failed ? 'Ayrim narx manbalari javob bermadi; natijalar to‘liq emas va eng arzon taklifni kafolatlamaydi.' : 'Ayrim manbalarda faqat eng mos mahsulotlar tekshirildi; qidiruv to‘liq emas va eng arzon taklifni kafolatlamaydi.'}</p>}
        <div className="section-header"><div><h2>{filters.q ? `“${filters.q}” uchun topilgan takliflar` : 'Katalogdagi takliflar'}</h2><p>{market.coverage}</p></div></div>
        {trend && <Sparkline series={trend.series} change={trend.change} />}
        {filters.q && trend && <section className="panel watch-panel" aria-label="Narx kuzatuvi"><div><h2>Bu narxni kuzatish</h2><p>Narx {watch?.threshold ?? '5'}% dan ko‘proq o‘zgarganda sahifada bildirishnoma olasiz. Kundalik avtomatik tekshiruv.</p></div>{verification.canUse && <Form action={watch?.watching ? '/market/watch/remove' : '/market/watch'} method="post" options={{ preserveScroll: true }}>
            <input type="hidden" name="query" value={filters.q} />{watch?.watching
                ? <button className="button secondary">Kuzatuvni bekor qilish</button>
                : <button className="button primary">Narxni kuzatish</button>}
        </Form>}{!verification.canUse && <Link className="button secondary" href="/verify-email">Kuzatuv uchun emailni tasdiqlang</Link>}</section>}
        {trend && <section className="panel market-watch" aria-label="Narx kuzatuvi"><div><Bell size={19} /><div><strong>“{filters.q}” narxini kuzatish</strong><p>Eng arzon narx {`±`} o‘zgarganda sahifada bildirishnoma olasiz.</p></div></div><Form action="/market/watch" method="post" options={{ preserveScroll: true }} className="market-watch-form">{({ processing, errors }) => <><input type="hidden" name="query" value={filters.q} /><select name="change_percent" aria-label="O‘zgarish chegarasi" defaultValue="5"><option value="3">3% dan katta</option><option value="5">5% dan katta</option><option value="10">10% dan katta</option></select><button className="button secondary" disabled={processing}>{processing ? 'Saqlanmoqda…' : 'Kuzatuvga olish'}</button>{Object.values(errors).map(err => <p className="error" role="alert" key={err}>{err}</p>)}</>}</Form></section>}
        <div className="market-catalog-list">
            {catalogGroups.map(source => <section className="market-catalog-group" key={source.id} aria-labelledby={`catalog-${source.id}`}>
                <header className="market-catalog-heading">
                    <div className={`market-catalog-icon source-${source.id}`}><Store size={18} /></div>
                    <div><h3 id={`catalog-${source.id}`}>{source.name} katalogi</h3><p>{source.products.length} ta mos mahsulot</p></div>
                    {filters.source !== source.id && <Link className="market-catalog-all" href={marketHref(source.department, source.id, filters.q, filters.terms)}>Faqat {source.name} <ArrowUpRight size={14} /></Link>}
                </header>
                <div className="public-price-grid">{source.products.map(product => <PublicPriceCard key={product.key ?? product.id} product={product} insight={insight} />)}</div>
            </section>)}
            {uncategorizedProducts.length > 0 && <section className="market-catalog-group" aria-labelledby="catalog-other">
                <header className="market-catalog-heading"><div className="market-catalog-icon"><Store size={18} /></div><div><h3 id="catalog-other">Boshqa manbalar</h3><p>{uncategorizedProducts.length} ta mahsulot</p></div></header>
                <div className="public-price-grid">{uncategorizedProducts.map(product => <PublicPriceCard key={product.key ?? product.id} product={product} insight={insight} />)}</div>
            </section>}
        </div>
        {!market.products.length && <section className="panel intelligence-empty"><Search size={30} /><h2>{market.checked_at ? 'Bu qidiruv uchun katalogda mos taklif topilmadi.' : 'Tanlangan manbaning katalogi yuklanishi kutilmoqda.'}</h2><p>{market.checked_at ? 'Bu mahsulot bozorda yo‘q degani emas. Umumiyroq nom bilan qidiring; katalog barcha assortimentni qamramaydi.' : 'Administrator market:sync orqali manbani yangilashi mumkin.'}</p>{market.sources.some(source => source.id === 'asaxiy' && source.enabled === false) && <p className="notice">AI suratdagi buyumni nomlaydi, lekin hozircha umumiy mahsulotlar uchun faol sotuvchi katalogi ulanmagan. Asaxiy katalogi foydalanish shartlari tasdiqlanmagani uchun o‘chiq; shu sabab telefon g‘ilofi, mebel kabi buyumlarga ishonchli sotuvchi narxi chiqmasligi mumkin.</p>}</section>}
        {market.total > 60 && <p className="notice">Dastlabki 60 ta natija ko‘rsatilgan. Qidiruvni aniqroq yozing.</p>}
        <p className="market-disclaimer">Narxlar so‘mda. Yetkazish narxi kiritilmagan. Aksiya shartlari, filial, nav, brend va qadoqqa qarab farq bo‘lishi mumkin. Sotuvchi narxlari butun Toshkent bozorining o‘rtacha narxi emas; rasmiy o‘rtachalar kuzatuv davri bilan ko‘rsatiladi.</p>
    </Layout>;
}

function PublicPriceCard({ product, insight }: { product: PublicPrice; insight: Insight | null }) {
    const validPrice = /^\d+(\.\d+)?$/.test(product.price) ? parseFloat(product.price) : null;
    const bestPrice = insight?.best.price ? parseFloat(insight.best.price) : null;
    const sameProduct = insight !== null && product.name.trim().toLocaleLowerCase() === insight.best.name.trim().toLocaleLowerCase()
        && product.unit_label.trim().toLocaleLowerCase() === insight.best.unit_label.trim().toLocaleLowerCase();
    const diffPercent = sameProduct && validPrice !== null && bestPrice !== null && validPrice > bestPrice
        ? Math.round((validPrice - bestPrice) / bestPrice * 100)
        : null;

    return <article className="public-price-card">
        <div className="public-product-image">
            {product.image ? <img src={product.image} alt={product.name} loading="lazy" referrerPolicy="no-referrer" /> : <Store size={35} />}
            <span className={`public-source source-${product.source_id}`}>{product.source_name}</span>
            {diffPercent !== null && <span className="badge amber seller-diff">+{diffPercent}%</span>}
            {sameProduct && validPrice !== null && bestPrice !== null && validPrice <= bestPrice && <span className="badge green seller-diff">Eng arzon</span>}
        </div>
        <div className="public-product-body">
            <small>{product.category}</small>
            <h3>{product.name}</h3>
            <p className="public-unit">{product.unit_label}</p>
            <strong className="public-product-price">{money(product.price)}<small> so‘m</small></strong>
            {product.card_required && <p className="public-condition">Korzinka kartasi talab etiladi</p>}
            {product.conditions && <p className="public-condition">{product.conditions}</p>}
            {product.availability === 'out_of_stock' && <span className="badge amber product-stock">Manbada mavjud emas</span>}
            {product.availability === 'in_stock' && <span className="badge green product-stock">Manbada mavjud</span>}
            <p className="public-expiry">{product.data_period ? `Kuzatuv davri: ${marketPeriod(product.data_period)}` : product.valid_until ? `Amal qiladi: ${timestamp(product.valid_until)}` : 'Narxning amal muddati manbada ko‘rsatilmagan'}</p>
            {product.checked_at && <p className="public-expiry">Tekshirildi: {timestamp(product.checked_at)}</p>}
            <a className="decision-link" href={product.source_url} target="_blank" rel="noreferrer">{product.source_id === 'statuz' ? 'Rasmiy statistik manba' : 'Sotuvchida tekshirish'} <ArrowUpRight size={15} /></a>
        </div>
    </article>;
}

function marketHref(department: string, source: string, query: string, terms: string[]): string {
    const params = new URLSearchParams({ department, source });
    if (query) {
        params.set('q', query);
    }
    terms.forEach(term => params.append('terms[]', term));

    return `/market?${params.toString()}`;
}

function marketPeriod(period: string | null | undefined): string {
    const match = period?.match(/^(\d{4})-M(\d{2})$/);
    if (!match) return 'oxirgi mavjud';
    const months = ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentabr', 'oktabr', 'noyabr', 'dekabr'];
    const month = Number(match[2]);

    return month >= 1 && month <= 12 ? `${months[month - 1]} ${match[1]}` : 'oxirgi mavjud';
}
