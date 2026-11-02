import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, ArrowUpRight, BarChart3, Bell, Boxes, CircleHelp, GitCompareArrows, LayoutDashboard, LogOut, Menu, Settings2, Sparkles, Upload, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type { Shared } from '../types';
import CommandMenu from './CommandMenu';
import ThemeToggle from './ThemeToggle';

const navigation = [
    { href: '/market', label: 'Suratdan narx topish', icon: Sparkles },
    { href: '/dashboard', label: 'Umumiy ko‘rinish', icon: LayoutDashboard },
    { href: '/products', label: 'Mahsulotlar', icon: Boxes },
    { href: '/offers', label: 'Bozor narxlari', icon: Activity },
    { href: '/suppliers', label: 'Yetkazib beruvchilar', icon: Boxes },
    { href: '/comparison', label: 'Narxlarni solishtirish', icon: GitCompareArrows },
    { href: '/import-center', label: 'Import markazi', icon: Upload },
];

export default function Layout({ title, children, action, meta }: { title: string; children: ReactNode; action?: ReactNode; meta?: { description?: string } }) {
    const { auth, flash } = usePage<Shared>().props;
    const [open, setOpen] = useState(false);
    const current = usePage().url.split('?')[0];
    const isPublicMarket = current === '/market' || current.startsWith('/market/');

    return <div className="workspace">
        <Head title={title}>{meta?.description ? [{ name: 'description', content: meta.description } as never] : []}</Head>
        <button className="mobile-menu" onClick={() => setOpen(!open)} aria-label="Menyuni ochish">{open ? <X /> : <Menu />}</button>
        <aside className={`sidebar ${open ? 'is-open' : ''}`}>
            <Link href={auth.is_platform_admin ? '/dashboard' : '/market'} className="brand"><span className="brand-icon"><Activity size={24} /></span><span>smartmarket<span className="brand-ai">AI</span><small>{isPublicMarket ? 'HAQIQIY NARXLAR. OQILONA XARID.' : 'BOZORNI ANGLANG. OQILONA XARID QILING.'}</small></span></Link>
            {isPublicMarket ? <>
                <p className="nav-caption">BOZOR VA KATALOG</p>
                <nav>
                    <Link href="/market" className={`nav-item ${current === '/market' || current.startsWith('/market/product/') ? 'active' : ''}`}><Sparkles size={19} />Bozor bosh sahifasi</Link>
                    <Link href="/account" className="nav-item"><Settings2 size={19} />Hisob va xavfsizlik</Link>
                    {auth.is_platform_admin && <Link href="/admin" className="nav-item"><LayoutDashboard size={19} />Admin panel</Link>}
                </nav>
                <div className="sidebar-bottom">
                    <div className="help-card"><CircleHelp size={20} /><strong>Narxni qanday topaman?</strong><p>Mahsulotni suratga oling yoki nomini qidiring. Narx, o‘lchov birligi va do‘kon manbasini tekshiring.</p></div>
                    <Profile auth={auth} />
                </div>
            </> : <>
                <p className="nav-caption">TIZIM BO‘LIMLARI</p>
                <nav>{navigation.map(item => <Link key={item.href} href={item.href} className={`nav-item ${current === item.href || (item.href === '/import-center' && current.startsWith('/import-center/')) ? 'active' : ''}`} onClick={() => setOpen(false)}><item.icon size={19} />{item.label}</Link>)}</nav>
                <p className="nav-caption">INTELLEKT VA TAHLIL</p>
                {auth.is_platform_admin && <Link href="/admin" className="nav-item"><Settings2 size={19} />Admin panel</Link>}
                <Link href="/ai" className={`nav-item ${current === '/ai' ? 'active' : ''}`}><Sparkles size={19} />Xarid intellekti</Link>
                <Link href="/analytics" className={`nav-item ${current === '/analytics' ? 'active' : ''}`}><BarChart3 size={19} />Tahlil va hisobotlar</Link>
                <Link href="/notifications" className={`nav-item ${current === '/notifications' ? 'active' : ''}`}><Bell size={19} />Bildirishnomalar</Link>
                <div className="sidebar-bottom">
                    <div className="help-card"><CircleHelp size={20} /><strong>Ishni nimadan boshlash kerak?</strong><p>Mahsulot qo‘shing va haqiqiy takliflarni import qiling.</p><Link href="/import-center">Import markaziga o‘tish <ArrowUpRight size={15} /></Link></div>
                    <Profile auth={auth} />
                </div>
            </>}
        </aside>
        <div className="main-shell">
            <header className="topbar"><span>{isPublicMarket ? 'Foydalanuvchi paneli' : 'Tizim'} <span className="breadcrumb">/</span> <strong>{title}</strong></span><div className="topbar-actions"><ThemeToggle /><CommandMenu admin={auth.is_platform_admin} hasWorkspace={auth.is_platform_admin} /></div></header>
            <main>
                <div className="page-heading"><div><p className="eyebrow">{isPublicMarket ? 'TOSHKENT · SMART MARKET AI' : 'SMART MARKET INTELLIGENCE'}</p><h1>{title}</h1></div>{action}</div>
                {flash.success && <div className="success" role="status">{flash.success}</div>}
                {children}
                <footer>Smart Market AI <span>{isPublicMarket ? 'Narxlar manbasi va tekshirilgan vaqti bilan' : 'Ma’lumotga asoslangan xaridlar · Toshkent'}</span></footer>
            </main>
        </div>
    </div>;
}

function Profile({ auth }: { auth: Shared['auth'] }) {
    return <div className="profile">
        <span className="profile-avatar">{auth.name.slice(0, 1)}</span>
        <span><Link href="/account"><strong>{auth.name}</strong></Link><small>{auth.is_platform_admin ? 'Admin' : 'Foydalanuvchi'}</small></span>
        <Link href="/logout" method="post" as="button" aria-label="Chiqish"><LogOut size={18} /></Link>
    </div>;
}
