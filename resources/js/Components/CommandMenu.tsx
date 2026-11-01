import { router } from '@inertiajs/react';
import { ArrowUpRight, Command, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const pages = [
    { name: 'Suratdan narx topish', description: 'Toshkent · Korzinka katalogi', href: '/market' },
    { name: 'Umumiy ko‘rinish', description: 'Katalog va bozor statistikasi', href: '/dashboard' },
    { name: 'Mahsulotlar', description: 'Katalog, SKU va kategoriyalar', href: '/products' },
    { name: 'Yetkazib beruvchilar', description: 'Hamkorlar va ishonchlilik', href: '/suppliers' },
    { name: 'Bozor narxlari', description: 'Takliflar, manbalar va dalillar', href: '/offers' },
    { name: 'Xarid intellekti', description: 'Ssenariy va xarid imkoniyatlari', href: '/ai' },
    { name: 'Narxlarni solishtirish', description: 'Qadoq, miqdor va yetkazish', href: '/comparison' },
    { name: 'Import markazi', description: 'CSV, Excel va hujjatlar', href: '/import-center' },
    { name: 'Tahlil va hisobotlar', description: 'Narx harakati va CSV eksport', href: '/analytics' },
    { name: 'Bildirishnomalar', description: 'Muhim bozor hodisalari', href: '/notifications' },
    { name: 'Tashkilot sozlamalari', description: 'A’zolar va ruxsatlar', href: '/settings' },
    { name: 'Hisob va xavfsizlik', description: 'Profil, parol va sessiyalar', href: '/account' },
];
export default function CommandMenu({ admin = false, hasWorkspace = true }: { admin?: boolean; hasWorkspace?: boolean }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(0);
    const dialog = useRef<HTMLDialogElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const items = [...(admin ? [{ name: 'Admin panel', description: 'Platformani boshqarish', href: '/admin' }] : []), ...pages.filter(page => hasWorkspace || page.href === '/account')].filter(item => `${item.name} ${item.description}`.toLocaleLowerCase().includes(query.toLocaleLowerCase()));
    useEffect(() => {
        const key = (event: KeyboardEvent) => { if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); setOpen(value => !value); } };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, []);
    useEffect(() => { if (open) { setQuery(''); setSelected(0); dialog.current?.showModal(); } else { dialog.current?.close(); } }, [open]);
    function visit(href: string) { setOpen(false); router.visit(href); }
    return <><button ref={trigger} className="command-trigger" onClick={() => setOpen(true)} aria-label="Tezkor navigatsiyani ochish"><Search size={15} /><span>Qayerga o‘tamiz?</span><kbd>⌘ K</kbd></button><dialog ref={dialog} className="command-dialog" aria-label="Tezkor navigatsiya" onCancel={() => setOpen(false)} onClose={() => { setOpen(false); trigger.current?.focus(); }} onClick={event => { if (event.target === dialog.current) setOpen(false); }}><div className="command-search"><Command size={20} /><input autoFocus aria-label="Sahifalarni qidirish" placeholder="Sahifa yoki amalni qidiring…" value={query} onChange={event => { setQuery(event.target.value); setSelected(0); }} onKeyDown={event => { if (event.key === 'ArrowDown') { event.preventDefault(); setSelected(index => Math.min(index + 1, items.length - 1)); } if (event.key === 'ArrowUp') { event.preventDefault(); setSelected(index => Math.max(index - 1, 0)); } if (event.key === 'Enter' && items[selected]) { event.preventDefault(); visit(items[selected].href); } }} /><button aria-label="Qidiruvni yopish" onClick={() => setOpen(false)}><X size={18} /></button></div><p className="eyebrow">TEZKOR NAVIGATSIYA</p><div className="command-results">{items.map((item, index) => <button key={item.href} className={selected === index ? 'selected' : ''} onMouseEnter={() => setSelected(index)} onClick={() => visit(item.href)}><span><strong>{item.name}</strong><small>{item.description}</small></span><ArrowUpRight size={17} /></button>)}{!items.length && <p className="empty">Sahifa topilmadi. Boshqa so‘z bilan qidiring.</p>}</div><footer><span>↑ ↓ tanlash · Enter ochish</span><span>Esc yopish</span></footer></dialog></>;
}
