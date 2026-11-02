import { Form, Link, usePage } from '@inertiajs/react';
import { Activity, ArrowLeft, LogOut, Search, ShieldCheck, Users } from 'lucide-react';
import AdminShell from '../Components/AdminShell';
import { Pager } from '../Components/Forms';
import { date, type Pagination, type Shared } from '../types';

type AdminUser = { id: number; name: string; email: string; is_active: boolean; is_suspended: boolean; is_platform_admin: boolean; email_verified_at: string | null };
type Props = {
    assistant: { configured: boolean; model: string; provider: string };
    stats: { users: number; products: number; offers: number; suspended: number };
    filters: { q: string; status: string; tab: string };
    growth: { day: string; users: number }[];
    operations: { database?: string; pending_jobs?: number; failed_jobs?: number; pending_schema_changes?: number; storage_directory?: string; worker?: string };
    imports: { id: number; file_name: string; status: string; row_count: number; error_count: number }[];
    users: Pagination<AdminUser>;
    events: { id: number; user_id: number; action: string; created_at: string }[];
};

export default function Admin({ stats, filters, users, events, growth, operations, imports, assistant }: Props) {
    const { auth } = usePage<Shared>().props;
    const overview = filters.tab === 'overview';
    const maxGrowth = Math.max(1, ...growth.map(day => day.users));

    return <AdminShell tab={filters.tab}>
        {overview && <section className="admin-welcome">
            <div>
                <span className="engine-label"><span className="status-dot" /> PLATFORM CONTROL CENTER</span>
                <h2>Butun tizim.<br /><em>Bitta boshqaruv markazi.</em></h2>
                <p>{auth.name}, foydalanuvchilar va tizim holatini<br />shu paneldan boshqaring.</p>
                <div><Link className="button primary" href="/admin?tab=users">Foydalanuvchilarni boshqarish <ArrowLeft size={15} className="rotate-180" /></Link><Link className="button secondary" href="/admin?tab=operations">Tizim holati</Link></div>
            </div>
            <div className="control-visual" aria-hidden="true">
                <div className="control-orbit orbit-one" /><div className="control-orbit orbit-two" /><div className="control-orbit orbit-three" />
                <div className="control-logo"><ShieldCheck size={45} strokeWidth={1} /><strong>SM<span>AI</span></strong><small>CONTROL CORE</small></div>
                <span className="orbit-node node-one"><Users size={18} /></span><span className="orbit-node node-three"><Activity size={18} /></span>
            </div>
        </section>}
        {overview && <div className="stats-grid">{[
            ['Foydalanuvchilar', stats.users],
            ['Mahsulotlar', stats.products],
            ['Narx takliflari', stats.offers],
            ['Bloklangan userlar', stats.suspended],
        ].map(([label, value]) => <section className="stat-card" key={label}><div>{label}<Activity size={17} /></div><strong>{value.toLocaleString('uz-UZ')}</strong><small>Butun tizim bo‘yicha</small></section>)}</div>}
        {overview && <div className="admin-overview-grid">
            <section className="panel"><div className="section-header"><div><p className="eyebrow">PLATFORMA DINAMIKASI</p><h2>Yangi foydalanuvchilar</h2></div><span className="badge neutral">14 kun · UTC</span></div><div className="activity-chart">{growth.map(day => <div key={day.day} title={`${day.day}: ${day.users}`}><span>{day.users}</span><i style={{ height: `${Math.max(2, day.users / maxGrowth * 100)}%` }} /><small>{day.day.slice(8)}</small></div>)}</div></section>
            <section className="panel attention-panel">
                <p className="eyebrow">E’TIBOR MARKAZI</p><h2>Harakat talab qiladigan holatlar</h2>
                <Link href="/admin?tab=users&status=suspended"><span className="attention-icon"><ShieldCheck size={18} /></span><span><strong>Bloklangan hisoblar</strong><small>Kirish nazorati</small></span><b>{stats.suspended}</b></Link>
                <Link href="/admin?tab=operations"><span className="attention-icon gold"><Activity size={18} /></span><span><strong>Xato bilan tugagan vazifalar</strong><small>Import navbati tekshiruvi</small></span><b>{operations.failed_jobs ?? '—'}</b></Link>
                <Link href="/admin?tab=users"><span className="attention-icon"><Users size={18} /></span><span><strong>Foydalanuvchilar</strong><small>Hisoblarni ko‘rish va boshqarish</small></span><b>{stats.users}</b></Link>
            </section>
        </div>}
        {filters.tab === 'users' && <section>
            <div className="section-header"><div><h2><Users size={18} className="inline" /> Foydalanuvchilar</h2><p>{stats.suspended} ta bloklangan hisob</p></div></div>
            <Form action="/admin" method="get" className="search-form admin-search">
                <input type="hidden" name="tab" value={filters.tab} />
                <select name="status" aria-label="Hisob holati" defaultValue={filters.status}><option value="all">Barcha hisoblar</option><option value="active">Faol</option><option value="suspended">Bloklangan</option><option value="unverified">Email tasdiqlanmagan</option><option value="admins">Administratorlar</option></select>
                <input name="q" aria-label="Foydalanuvchi qidirish" placeholder="Ism yoki email bo‘yicha qidirish" defaultValue={filters.q} />
                <button className="button secondary"><Search size={16} />Qidirish</button>
                {filters.q && <Link className="button secondary" href="/admin">Tozalash</Link>}
            </Form>
            <div className="table-panel"><div className="table-scroll"><table><thead><tr><th>Foydalanuvchi</th><th>Holat</th><th>Rol</th><th>Boshqaruv</th></tr></thead><tbody>{users.data.map(user => <tr key={user.id}>
                <td><strong>{user.name}</strong><small>{user.email}</small></td>
                <td><span className={`badge ${user.is_suspended || !user.is_active ? 'amber' : 'green'}`}>{user.is_suspended ? 'Bloklangan' : user.is_active ? 'Faol' : 'Faolsiz'}</span><small>{user.email_verified_at ? 'Email tasdiqlangan' : 'Email tasdiqlanmagan'}</small></td>
                <td><span className="badge neutral">{user.is_platform_admin ? 'Admin' : 'User'}</span></td>
                <td>{user.is_platform_admin ? <small>Himoyalangan admin hisobi</small> : <div className="admin-actions"><AdminAction id={user.id} action={user.is_suspended ? 'restore' : 'suspend'} label={user.is_suspended ? 'Blokdan chiqarish' : 'Bloklash'} /><AdminAction id={user.id} action="revoke_sessions" label="Sessiyalarni uzish" /></div>}</td>
            </tr>)}</tbody></table></div>{!users.data.length && <p className="empty">Qidiruv bo‘yicha foydalanuvchi topilmadi.</p>}<Pager page={users} /></div>
        </section>}
        {filters.tab === 'operations' && <>
            <section className="panel"><p className="eyebrow">OPENROUTER · AI YORDAMCHI</p><h2>{assistant.configured ? 'API kaliti sozlangan' : 'API kaliti kutilmoqda'}</h2><p>Model: {assistant.model}. Kalit faqat serverda saqlanadi. Sozlangan holat haqiqiy API so‘rovi muvaffaqiyatini tasdiqlamaydi.</p><p>Kalit serverdagi OPENROUTER_API_KEY orqali, model OPENROUTER_MODEL orqali boshqariladi.</p><Link className="button primary" href="/ai">AI yordamchini ochish</Link></section>
            <section className="panel"><p className="eyebrow">OPERATSIYALAR</p><h2>Tizimning haqiqiy holati</h2><div className="system-grid">{[['Ma’lumotlar bazasi', operations.database === 'reachable' ? 'Ulangan' : 'Tekshirish kerak'], ['Navbatdagi vazifalar', operations.pending_jobs ?? '—'], ['Xato vazifalar', operations.failed_jobs ?? '—'], ['Kutilayotgan migratsiyalar', operations.pending_schema_changes ?? '—'], ['Fayllar ombori', operations.storage_directory === 'accessible' ? 'Mavjud' : 'Tekshirish kerak']].map(([label, value]) => <div key={label}><small>{label}</small><strong>{value}</strong></div>)}</div><p className="chart-note">Bu ichki ulanish tekshiruvi. Tashqi AI va email xizmatlarining ishlashini tasdiqlamaydi.</p></section>
            <section className="table-panel"><div className="section-header import-heading"><h2>Oxirgi importlar</h2></div><div className="table-scroll"><table><thead><tr><th>Fayl</th><th>Holat</th><th>Satrlar / xatolar</th></tr></thead><tbody>{imports.map(batch => <tr key={batch.id}><td><strong>{batch.file_name}</strong><small>Import #{batch.id}</small></td><td><span className="badge neutral">{batch.status}</span></td><td>{batch.row_count} / {batch.error_count}</td></tr>)}</tbody></table></div>{!imports.length && <p className="empty">Hozircha importlar yo‘q.</p>}</section>
        </>}
        {(overview || filters.tab === 'security') && <section className="panel"><h2><ShieldCheck size={18} className="inline" /> Xavfsizlik jurnali</h2><p>Oxirgi 20 ta hodisa. Admin amallari bajaruvchi va foydalanuvchi ID si bilan saqlanadi.</p>{events.map(event => <div className="activity" key={event.id}><span>#{event.user_id}</span><code className="admin-event">{event.action}</code><small>{date(event.created_at)}</small></div>)}{!events.length && <p>Hozircha hodisalar yo‘q.</p>}</section>}
    </AdminShell>;
}

function AdminAction({ id, action, label }: { id: number; action: string; label: string }) {
    return <Form action={`/admin/users/${id}`} method="post" onBefore={() => window.confirm(`${label}: amalni tasdiqlaysizmi?`)}>{({ processing, errors }) => <><input type="hidden" name="action" value={action} /><button disabled={processing} className="button secondary small">{action === 'revoke_sessions' && <LogOut size={12} />}{processing ? 'Kutilmoqda…' : label}</button>{errors.action && <p className="error" role="alert">{errors.action}</p>}</>}</Form>;
}
