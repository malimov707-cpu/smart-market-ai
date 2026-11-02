export default function Sparkline({ series, change }: { series: { date: string; price: number }[]; change: number | null }) {
    if (series.length < 2) { return null; }
    const min = Math.min(...series.map(p => p.price)); const max = Math.max(...series.map(p => p.price));
    const span = max - min || 1;
    const points = series.map((p, i) => `${(i / (series.length - 1)) * 100},${28 - ((p.price - min) / span) * 24}`).join(' ');
    const down = (change ?? 0) < 0;
    return <div className="trend-spark"><div className="trend-head"><small>{series.length} kun · eng arzon narx</small>{change !== null && <span className={`badge ${down ? 'green' : 'amber'}`}>{down ? '▼' : '▲'} {Math.abs(change)}%</span>}</div><svg viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"><polyline points={points} fill="none" stroke={down ? '#4bdfae' : '#e2bd63'} strokeWidth="1.5" /></svg><small className="trend-range">{series[0].date.slice(5)} — {series[series.length - 1].date.slice(5)}</small></div>;
}
