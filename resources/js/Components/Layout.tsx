import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, ArrowUpRight, Boxes, Building2, ChevronDown, CircleHelp, GitCompareArrows, LayoutDashboard, LogOut, Menu, Settings2, Sparkles, Upload, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type { Shared } from '../types';

const navigation = [
    { href: '/dashboard', label: 'Umumiy ko‘rinish', icon: LayoutDashboard },
    { href: '/products', label: 'Mahsulotlar', icon: Boxes },
    { href: '/offers', label: 'Bozor narxlari', icon: Activity },
    { href: '/suppliers', label: 'Yetkazib beruvchilar', icon: Building2 },
    { href: '/comparison', label: 'Narxlarni solishtirish', icon: GitCompareArrows },
    { href: '/imports', label: 'Import markazi', icon: Upload },
];
export default function Layout({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
    const { auth, organization, organizations, flash } = usePage<Shared>().props;
    const [open, setOpen] = useState(false);
    const current = usePage().url.split('?')[0];
    return <div className="workspace">
        <Head title={title} />
        <button className="mobile-menu" onClick={() => setOpen(!open)} aria-label="Menyuni ochish">{open ? <X /> : <Menu />}</button>
        <aside className={`sidebar ${open ? 'is-open' : ''}`}>
            <Link href="/dashboard" className="brand"><span className="brand-icon"><Activity size={24} /></span><span>smartmarket<span className="brand-ai">AI</span><small>BOZORNI ANGLANG. OQILONA XARID QILING.</small></span></Link>
            <label className="organization-picker"><span className="org-avatar">{organization.name.slice(0, 1)}</span><span className="org-select"><small>ISH MAYDONI</small><select aria-label="Tashkilotni tanlash" value={organization.id} onChange={event => router.post('/organization', { organization_id: event.target.value })}>{organizations.map(org => <option key={org.id} value={org.id}>{org.name}</option>)}</select></span><ChevronDown size={15} /></label>
            <p className="nav-caption">BOSHQARUV PANELI</p>
            <nav>{navigation.map(item => <Link key={item.href} href={item.href} className={`nav-item ${current === item.href ? 'active' : ''}`} onClick={() => setOpen(false)}><item.icon size={19} />{item.label}</Link>)}</nav>
            <p className="nav-caption">INTELLEKT VA TIZIM</p>
            <Link href="/ai" className={`nav-item ${current === '/ai' ? 'active' : ''}`}><Sparkles size={19} />AI boshqaruv markazi</Link>
            <Link href="/settings" className={`nav-item ${current === '/settings' ? 'active' : ''}`}><Settings2 size={19} />Tashkilot sozlamalari</Link>
            <div className="sidebar-bottom"><div className="help-card"><CircleHelp size={20} /><strong>Ishni nimadan boshlash kerak?</strong><p>Mahsulot qo‘shing va haqiqiy takliflarni import qiling.</p><Link href="/imports">Import markaziga o‘tish <ArrowUpRight size={15} /></Link></div>
            <div className="profile"><span className="profile-avatar">{auth.name.slice(0, 1)}</span><span><Link href="/account"><strong>{auth.name}</strong></Link><small>{organization.pivot.role}</small></span><Link href="/logout" method="post" as="button" aria-label="Chiqish"><LogOut size={18} /></Link></div></div>
        </aside>
        <div className="main-shell"><header className="topbar"><span>Ish maydoni <span className="breadcrumb">/</span> <strong>{title}</strong></span><span className="location"><span className="status-dot" />{organization.city} · UZS</span></header>
            <main><div className="page-heading"><div><p className="eyebrow">SMART MARKET INTELLIGENCE</p><h1>{title}</h1></div>{action}</div>
            {flash.success && <div className="success" role="status">{flash.success}</div>}{children}
            <footer>Smart Market AI <span>Ma’lumotga asoslangan xaridlar · Toshkent</span></footer></main>
        </div>
    </div>;
}
