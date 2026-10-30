import { useEffect, useState } from 'react';
type Option = { id: number; name: string; unit?: string; sku?: string };
export default function RemoteSelect({ name, label, endpoint, showUnit = false, initial, onChange }: { name: string; label: string; endpoint: string; showUnit?: boolean; initial?: Option; onChange?: (value: string) => void }) {
    const [query, setQuery] = useState(''); const [page, setPage] = useState(1); const [options, setOptions] = useState<Option[]>([]);
    const [selected, setSelected] = useState(initial ? String(initial.id) : ''); const [chosen, setChosen] = useState<Option | undefined>(initial);
    const [lastPage, setLastPage] = useState(1); const [loading, setLoading] = useState(false); const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setLoading(true); setError('');
            try {
                const response = await fetch(`${endpoint}?q=${encodeURIComponent(query)}&page=${page}`, { signal: controller.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) throw new Error();
                const data = await response.json(); setOptions(data.data); setLastPage(data.last_page);
            } catch { if (!controller.signal.aborted) setError('Qidiruvni yuklab bo‘lmadi. Qayta urinib ko‘ring.'); }
            finally { if (!controller.signal.aborted) setLoading(false); }
        }, 150);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [query, page, endpoint]);
    const visible = chosen && !options.some(option => option.id === chosen.id) ? [chosen, ...options] : options;
    return <div className="field"><label><span>{label} qidirish</span><input aria-label={`${label} qidirish`} value={query} onChange={event => { setQuery(event.target.value); setPage(1); }} /></label><label><span>{label}</span><select aria-label={label} name={name} value={selected} onChange={event => { setSelected(event.target.value); setChosen(visible.find(option => String(option.id) === event.target.value)); onChange?.(event.target.value); }}><option value="">Tanlang</option>{visible.map(option => <option key={option.id} value={option.id}>{option.name}{showUnit ? ` (${option.unit})` : ''}</option>)}</select></label><div className="mt-2 flex items-center gap-3 text-xs"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage(page - 1)}>Oldingi</button><span aria-live="polite">{loading ? 'Yuklanmoqda…' : `${page} / ${lastPage}`}</span><button type="button" disabled={page >= lastPage || loading} onClick={() => setPage(page + 1)}>Keyingi</button></div>{error && <p className="error" role="alert">{error}</p>}</div>;
}
