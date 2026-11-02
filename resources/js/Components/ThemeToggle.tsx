import { Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function ThemeToggle() {
    const [lightMode, setLightMode] = useState(false);

    useEffect(() => {
        setLightMode(document.documentElement.dataset.theme === 'light');
    }, []);

    function toggleTheme() {
        const nextTheme = lightMode ? 'dark' : 'light';
        document.documentElement.dataset.theme = nextTheme;
        window.localStorage.setItem('smart-market-theme', nextTheme);
        setLightMode(nextTheme === 'light');
    }

    return <button
        className="theme-toggle"
        type="button"
        onClick={toggleTheme}
        aria-label={lightMode ? 'Qorong‘i mavzuga o‘tish' : 'Yorug‘ mavzuga o‘tish'}
        aria-pressed={lightMode}
        title={lightMode ? 'Qorong‘i mavzuga o‘tish' : 'Yorug‘ mavzuga o‘tish'}
    >
        {lightMode ? <Moon size={16} /> : <Sun size={16} />}
        <span>{lightMode ? 'Qorong‘i' : 'Yorug‘'}</span>
    </button>;
}
