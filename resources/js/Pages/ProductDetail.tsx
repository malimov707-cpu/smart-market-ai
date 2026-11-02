import { Form, Link } from '@inertiajs/react';
import { ArrowUpRight, Store } from 'lucide-react';
import Layout from '../Components/Layout';
import Sparkline from '../Components/Sparkline';
import { money } from '../types';
import { timestamp } from '../Components/PriceFacts';

type PublicProduct = { id: number | string; key: string; source_id: string; source_name: string; checked_at: string | null; name: string; price: string; currency: string; unit_label: string; category: string; image: string | null; source_url: string };
type Insight = { best: { name: string; price: string; source_name: string; source_url: string }; worst: { name: string; price: string }; savings: string; savings_percent: number; offers: number; sources: string[] };
type Props = { query: string; slug: string; market: { checked_at: string | null; total: number; coverage: string; products: PublicProduct[] }; trend: { series: { date: string; price: number }[]; change: number | null }; insight: Insight | null; deal: { query: string; price: number; percent: number; previous_average: number } | null; watch: { watching: boolean; threshold: string }; related: string[] };
export default function ProductDetail({ query, market, trend, insight, deal, watch, related }: Props) {
    const description = `${query} narxi Toshkent: ${market.total} ta taklif, ${insight?.sources.join(', ') ?? 'manbalar'} katalogidan. Narx tarixi va ${insight ? `${insight.savings_percent}% gacha farq` : 'o‘zgarish kuzatuvi'}.`;
    return <Layout title={`${query} narxi`} meta={{ description }}>
        <section className="product-detail-head"><div><p className="eyebrow">TOSHKENT · KUNDALIK KUZATUV</p><h1>{query} narxi</h1><p>{market.total} ta taklif · {market.coverage}</p></div>
            <div className="product-detail-actions"><Link className="button secondary" href={`/market?q=${encodeURIComponent(query)}`}>Qidiruvda ochish</Link>
                <Form action={watch.watching ? '/market/watch/remove' : '/market/watch'} method="post" options={{ preserveScroll: true }}>
                    <input type="hidden" name="query" value={query} />
                    <button className="button primary">{watch.watching ? 'Kuzatuvni bekor qilish' : 'Narxni kuzatish'}</button>
                </Form></div></section>
        {deal && <section className="deal-banner" role="status"><span className="deal-flame">🔥</span><div><strong>Xarid oynasi: −{deal.percent}%</strong><p>Eng arzon narx o‘rtacha {deal.previous_average.toLocaleString('uz-UZ')} so‘mdan <b>{deal.price.toLocaleString('uz-UZ')} so‘m</b>ga tushdi. Bugun xarid uchun yaxshi kun.</p></div></section>}
        {trend.series.length >= 2 && <Sparkline series={trend.series} change={trend.change} />}
        {insight && <section className="panel insight-banner-deal"><div className="insight-deal-grid">
            <div><small>Eng arzon</small><strong>{money(insight.best.price)}<small> so‘m</small></strong><p>{insight.best.name}</p><span className="badge neutral">{insight.best.source_name}</span></div>
            <div><small>Eng qimmat</small><strong>{money(insight.worst.price)}<small> so‘m</small></strong><p>{insight.worst.name}</p></div>
            <div className="insight-deal-savings"><small>To‘g‘ri tanlov bilan tejaladi</small><strong>{money(insight.savings)}<small> so‘m</small></strong><span className="badge amber">−{insight.savings_percent}%</span></div>
        </div></section>}
        <div className="public-price-grid">{market.products.map(product => <article className="public-price-card" key={product.key ?? product.id}>
            <div className="public-product-image">{product.image ? <img src={product.image} alt={product.name} loading="lazy" referrerPolicy="no-referrer" /> : <Store size={35} />}<span className={`public-source source-${product.source_id}`}>{product.source_name}</span></div>
            <div className="public-product-body"><small>{product.category}</small><h3>{product.name}</h3><p className="public-unit">{product.unit_label}</p>
                <strong className="public-product-price">{money(product.price)}<small> so‘m</small></strong>
                <p className="public-expiry">Tekshirildi: {product.checked_at ? timestamp(product.checked_at) : 'ma’lumot yo‘q'}</p>
                <a className="decision-link" href={product.source_url} target="_blank" rel="noreferrer">Sotuvchida tekshirish <ArrowUpRight size={15} /></a></div>
        </article>)}</div>
        {!market.products.length && <section className="panel intelligence-empty"><h2>Bu mahsulot hali katalogda yo‘q.</h2><p>Qidiruv sahifasida boshqa nom bilan urinib ko‘ring.</p></section>}
        <section className="panel"><h2>Boshqa kuzatiladigan mahsulotlar</h2><div className="market-suggestions">{related.map(name => <Link key={name} href={`/market/product/${name}`}>{name}</Link>)}</div></section>
    </Layout>;
}
