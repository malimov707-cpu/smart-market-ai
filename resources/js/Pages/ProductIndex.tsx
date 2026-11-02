import { Link } from '@inertiajs/react';
import { ArrowUpRight, TrendingDown, TrendingUp } from 'lucide-react';
import Layout from '../Components/Layout';

type Card = { slug: string; name: string; change: number | null; price: number | null };
export default function ProductIndex({ products }: { products: Card[] }) {
    return <Layout title="Narxlar katalogi" meta={{ description: 'Toshkent bozorlari va do‘konlari narxlari: oziq-ovqat va qurilish mollari kundalik kuzatuvda. Haqiqiy manbalar, o‘zgarish foizi bilan.' }}>
        <p className="page-description">Kundalik kuzatiladigan mahsulotlar. Har bir sahifada barcha manbalar, narx tarixi va farq tahlili.</p>
        <div className="product-index-grid">{products.map(product => <Link className="panel product-index-card" key={product.slug} href={`/market/product/${product.slug}`}>
            <small>KUNLIK KUZATUV</small><h2>{product.name}</h2>
            {product.price !== null && <strong>{product.price.toLocaleString('uz-UZ')}<small> so‘m</small></strong>}
            {product.price === null && <strong className="muted">Ma’lumot yig‘ilmoqda</strong>}
            {product.change !== null && <span className={`badge ${product.change < 0 ? 'green' : 'amber'}`}>{product.change < 0 ? <TrendingDown size={12} /> : <TrendingUp size={12} />} {Math.abs(product.change)}%</span>}
            <span className="decision-link">Narx tarixi <ArrowUpRight size={14} /></span>
        </Link>)}</div>
    </Layout>;
}
