import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, ArrowUpRight, BarChart3, ChevronRight, CircleHelp, LayoutDashboard, LogOut, Menu, Radio, ShieldCheck, Users, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import type { Shared } from '../types';
import CommandMenu from './CommandMenu';
import ThemeToggle from './ThemeToggle';

const sections = [
    { id: 'overview', name: 'Umumiy ko‘rinish', icon: LayoutDashboard },
    { id: 'users', name: 'Foydalanuvchilar', icon: Users },
    { id: 'operations', name: 'Tizim operatsiyalari', icon: Radio },
    { id: 'security', name: 'Xavfsizlik jurnali', icon: ShieldCheck },
];

export default function AdminShell({ tab, children }: { tab: string; children: ReactNode }) {
    const { auth, flash } = usePage<Shared>().props;
    const [open, setOpen] = useState(false);

    return <div className="workspace admin-workspace">
        <Head title="Admin panel" />
        <button className="mobile-menu" aria-label="Admin menyuni ochish" onClick={() => setOpen(value => !value)}>{open ? <X /> : <Menu />}</button>
        {open && <button className="sidebar-scrim" aria-label="Menyuni yopish" onClick={() => setOpen(false)} />}
        <aside className={`sidebar ${open ? 'is-open' : ''}`}>
            <Link href="/admin" className="brand"><span className="brand-icon"><Activity /></span><span>smartmarket<span className="brand-ai">AI</span><small>PLATFORM CONTROL CENTER</small></span></Link>
            <div className="admin-role"><span><ShieldCheck size={19} /></span><div><strong>Platforma administratori</strong><small>Butun tizim boshqaruvi</small></div></div>
            <p className="nav-caption">ADMIN BOSHQARUVI</p>
            <nav>{sections.map(section => <Link key={section.id} href={`/admin?tab=${section.id}`} className={`nav-item ${tab === section.id ? 'active' : ''}`} onClick={() => setOpen(false)}><section.icon size={18} />{section.name}{tab === section.id && <ChevronRight size={14} className="nav-chevron" />}</Link>)}</nav>
            <p className="nav-caption">BOZOR ISH MAYDONI</p>
            <Link href="/dashboard" className="nav-item"><LayoutDashboard size={18} />Mahsulotlar ish maydoni</Link>
            <Link href="/market" className="nav-item"><Activity size={18} />Bozor katalogi</Link>
            <Link href="/analytics" className="nav-item"><BarChart3 size={18} />Tahlil va hisobotlar</Link>
            <div className="sidebar-bottom">
                <div className="help-card"><CircleHelp size={20} /><strong>Tizim nazorati</strong><p>Foydalanuvchilar, faoliyat va tizim holatini bitta paneldan boshqaring.</p></div>
                <div className="profile"><span className="profile-avatar">{auth.name.slice(0, 1)}</span><span><Link href="/account"><strong>{auth.name}</strong></Link><small>Administrator</small></span><Link method="post" href="/logout" as="button" aria-label="Chiqish"><LogOut size={17} /></Link></div>
            </div>
        </aside>
        <div className="main-shell">
            <header className="topbar"><div className="admin-breadcrumb">Platforma <ChevronRight size={13} /><strong>{sections.find(section => section.id === tab)?.name}</strong></div><div className="topbar-actions"><ThemeToggle /><CommandMenu admin hasWorkspace /></div></header>
            <main>
                <div className="page-heading"><div><p className="eyebrow">SMART MARKET AI · CONTROL CENTER</p><h1>Admin panel</h1></div><Link href="/market" className="button primary"><ArrowUpRight size={16} />Foydalanuvchi paneli</Link></div>
                {flash.success && <p className="success" role="status">{flash.success}</p>}
                <div className="admin-content">{children}</div>
                <footer>Smart Market AI<span>Platforma boshqaruvi · dalillarga asoslangan qarorlar</span></footer>
            </main>
        </div>
    </div>;
}
