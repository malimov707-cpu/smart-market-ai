import { Link } from '@inertiajs/react';
import { ArrowUpRight, Boxes, Building2, Database, GitCompareArrows, ShieldCheck, Sparkles } from 'lucide-react';

export default function IntelligenceCore({ stats }: { stats: { products: number; suppliers: number; offers: number; fresh: number } }) {
    const streams = [
        { label: 'Mahsulot katalogi', value: stats.products, icon: Boxes, href: '/products' },
        { label: 'Hamkorlar tarmog‘i', value: stats.suppliers, icon: Building2, href: '/suppliers' },
        { label: 'Narx kuzatuvlari', value: stats.offers, icon: Database, href: '/offers' },
    ];
    return <section className="intelligence-stage" aria-label="Smart Market ma’lumot markazi">
        <div className="data-streams"><p className="eyebrow">KIRUVCHI MA’LUMOTLAR</p>{streams.map(stream => <Link href={stream.href} className="stream-card" key={stream.href}><stream.icon size={23} /><span><small>{stream.label}</small><strong>{stream.value.toLocaleString('uz-UZ')}</strong></span><ArrowUpRight size={14} /></Link>)}</div>
        <div className="neural-core"><div className="core-caption"><span className="eyebrow">SMART MARKET CORE</span><h2>Ma’lumotdan — oqilona qarorga.</h2><p>Xarid intellekti, yagona markazda.</p></div>
            <svg className="neural-wires" viewBox="0 0 700 330" fill="none" aria-hidden="true"><defs><linearGradient id="wire" x1="0" x2="700" gradientUnits="userSpaceOnUse"><stop stopColor="#00d4ff" /><stop offset=".5" stopColor="#38bdf8" /><stop offset="1" stopColor="#f3c65d" /></linearGradient></defs>{Array.from({ length: 13 }, (_, i) => <g key={i}><path d={`M0 ${15 + i * 25} C170 ${15 + i * 25} 200 ${135 + i * 5} 350 165 S530 ${15 + i * 25} 700 ${15 + i * 25}`} stroke="url(#wire)" strokeWidth={i % 3 === 0 ? 2 : 1} opacity={0.2 + i % 3 * 0.2} /><circle r="2.5" fill={i % 2 ? '#f3c65d' : '#00d4ff'}><animateMotion dur={`${4 + i % 4}s`} repeatCount="indefinite" path={`M0 ${15 + i * 25} C170 ${15 + i * 25} 200 ${135 + i * 5} 350 165 S530 ${15 + i * 25} 700 ${15 + i * 25}`} /></circle></g>)}</svg>
            <div className="core-chip"><div className="chip-inner"><Sparkles size={36} strokeWidth={1.2} /><strong>SM<span>AI</span></strong><small>MARKET INTELLIGENCE</small></div></div><span className="core-footnote"><span className="status-dot" /> Katalog · narxlar · solishtirish</span>
        </div>
        <div className="core-results"><p className="eyebrow">QAROR QABUL QILISH</p><Link href="/comparison" className="result-tile"><GitCompareArrows size={23} /><strong>Eng maqbul taklif</strong><small>Xarajatlarni solishtiring</small><ArrowUpRight size={15} /></Link><Link href="/analytics" className="result-tile"><ShieldCheck size={23} /><strong>{stats.fresh} dolzarb taklif</strong><small>Hisobot va narx tahlili</small><ArrowUpRight size={15} /></Link><Link href="/ai" className="core-ai-note"><Sparkles size={16} /><span>AI integratsiyasi kutilmoqda</span></Link></div>
    </section>;
}
