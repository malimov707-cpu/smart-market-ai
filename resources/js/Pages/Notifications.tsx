import { Form, Link } from '@inertiajs/react';
import Layout from '../Components/Layout';
import { Field } from '../Components/Forms';
import { timestamp } from '../Components/PriceFacts';

type Notification = { id: number; event_type: string; title: string; body: string | null; url: string | null; read_at: string | null; email_status: string; created_at: string };
type Pager = { data: Notification[]; current_page: number; last_page: number; total: number; links: { url: string | null; label: string; active: boolean }[] };

const emailStatuses: Record<string, string> = { not_requested: 'Email so‘ralmagan', sent: 'SMTP qabul qildi (delivery tasdiqlanmagan)', unavailable: 'Email sozlanmagan', failed: 'Email yuborilmadi' };

export default function Notifications({ notifications, unreadCount, filter }: { notifications: Pager; unreadCount: number; filter: string }) {
    return <Layout title="Bildirishnomalar" action={<Link href="/notifications/rules" className="button secondary">Ogohlantirish qoidalari</Link>}>
        <p className="page-description">Shaxsiy bildirishnomalar. O‘qilmaganlar: {unreadCount}. Email holati halol ko‘rsatiladi — SMTP sozlanmagan bo‘lsa "sozlanmagan" deb belgilanadi.</p>
        <div className="tab-row">
            <Link href="/notifications" className={filter === 'all' ? 'tab active' : 'tab'} preserveScroll>Barchasi</Link>
            <Link href="/notifications?unread=1" className={filter === 'unread' ? 'tab active' : 'tab'} preserveScroll>O‘qilmaganlar</Link>
        </div>
        {notifications.data.length === 0 && <p className="empty">Bildirishnomalar yo‘q. Ogohlantirish qoidalari mos voqea bo‘lganda shu yerda ko‘rinadi.</p>}
        <ul className="notification-list">
            {notifications.data.map(n => <li key={n.id} className={n.read_at ? 'notification read' : 'notification unread'}>
                <div>
                    <strong>{n.title}</strong>
                    {n.body && <p>{n.body}</p>}
                    <small>{timestamp(n.created_at)} · {emailStatuses[n.email_status] ?? n.email_status}</small>
                </div>
                <div className="notification-actions">
                    {n.url && <Link href={n.url} className="button secondary small">Ochish</Link>}
                    {!n.read_at && <Form action={`/notifications/${n.id}/read`} method="post">{({ processing }) => <button className="button secondary small" disabled={processing}>{processing ? '...' : 'O‘qilgan'}</button>}</Form>}
                </div>
            </li>)}
        </ul>
        {notifications.data.length > 0 && <Form action="/notifications/read-all" method="post">{({ processing }) => <button className="button secondary" disabled={processing}>{processing ? '...' : 'Barchasini o‘qilgan deb belgilash'}</button>}</Form>}
    </Layout>;
}
