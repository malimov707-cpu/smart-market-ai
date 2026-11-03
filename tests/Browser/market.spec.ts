import { expect, test } from '@playwright/test';
import { dirname, join } from 'node:path';
import { browserEnvironment } from '../../playwright.config';

test('consumer gets one market home with retailer catalogs grouped in the navigation', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`consumer-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();

    await expect(page).toHaveURL(/\/market$/);
    await expect(page.getByRole('link', { name: 'Bozor bosh sahifasi' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Hisob va xavfsizlik' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Narxlar katalogi' })).toHaveCount(0);
    await expect(page.locator('.market-source-grid .source-filter')).toHaveCount(6);
    for (const retailer of ['Korzinka', 'Milliy statistika', 'TezQur', 'KSLux', 'Alstroy']) {
        await expect(page.locator('.market-source-grid .source-filter').filter({ hasText: retailer })).toBeVisible();
    }
    const asaxiy = page.locator('.market-source-grid .source-filter').filter({ hasText: 'Asaxiy' });
    await expect(asaxiy).toContainText('Ruxsat kutilmoqda');
    expect(await asaxiy.getAttribute('href')).toBeNull();

    await page.getByRole('link', { name: /Alstroy/ }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('source') === 'alstroy');
    await expect(page.locator('.source-selected')).toContainText('Alstroy');
    await page.getByRole('navigation', { name: 'Mahsulot yo‘nalishi' }).getByRole('link', { name: 'Elektronika, uy-ro‘zg‘or va boshqalar', exact: true }).click();
    await expect(page.locator('.market-source-grid .source-filter')).toHaveCount(1);
    await expect(page.locator('.market-source-grid .source-filter')).toContainText('Manba shartlari tasdiqlanmagani uchun katalog hozircha o‘chirilgan.');
});

test('market navigation exposes and dismisses its mobile menu', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`consumer-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();

    await expect(page.locator('.workspace')).toHaveAttribute('data-experience', 'personal');
    await expect(page.getByRole('navigation', { name: 'Bozor navigatsiyasi' })).toBeVisible();
    const menuButton = page.getByRole('button', { name: 'Menyuni ochish' });
    const viewport = page.viewportSize();
    if (viewport && viewport.width < 900) {
        await menuButton.click();
        await expect(page.getByRole('button', { name: 'Menyuni yopish' }).first()).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('.sidebar-scrim')).toBeVisible();
        await page.locator('.sidebar-scrim').click({ position: { x: viewport.width - 12, y: viewport.height / 2 } });
        await expect(page.getByRole('button', { name: 'Menyuni ochish' })).toHaveAttribute('aria-expanded', 'false');
    } else {
        await expect(menuButton).toBeHidden();
    }
});

test('market cards label price freshness and keep the status badge readable', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`consumer-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/market$/);
    await page.route('**/market?*', async route => {
        const response = await route.fetch();
        const body = await response.json();
        const common = {
            currency: 'UZS',
            unit_label: '1 dona',
            category: 'Sinov',
            image: null,
            card_required: false,
            conditions: '',
            source_url: 'https://example.test/product',
        };
        const products = [
            {
                ...common,
                id: 'stale-fixture',
                key: 'stale-fixture',
                source_id: 'korzinka',
                source_name: 'Korzinka',
                checked_at: '2026-10-20T10:00:00.000000Z',
                freshness: 'stale',
                availability: 'unknown',
                name: 'Stale product',
                price: '10000.00',
                valid_until: '2026-12-01T10:00:00.000000Z',
            },
            {
                ...common,
                id: 'expired-fixture',
                key: 'expired-fixture',
                source_id: 'korzinka',
                source_name: 'Korzinka',
                checked_at: '2026-10-31T10:00:00.000000Z',
                freshness: 'expired',
                availability: 'unknown',
                name: 'Expired product',
                price: '12000.00',
                valid_until: '2026-10-31T10:00:00.000000Z',
            },
            {
                ...common,
                id: 'unknown-fixture',
                key: 'unknown-fixture',
                source_id: 'catalog-fixture',
                source_name: 'Sinov katalogi',
                checked_at: null,
                freshness: 'unknown',
                availability: 'unknown',
                name: 'Unknown product',
                price: '13000.00',
                valid_until: null,
            },
            {
                ...common,
                id: 'historical-fixture',
                key: 'historical-fixture',
                source_id: 'statuz',
                source_name: 'Milliy statistika',
                checked_at: '2026-10-01T10:00:00.000000Z',
                freshness: 'historical',
                availability: 'unknown',
                name: 'Historical product',
                price: '14000.00',
                valid_until: null,
                data_period: '2026-M09',
            },
        ];

        await route.fulfill({
            response,
            json: {
                ...body,
                props: {
                    ...body.props,
                    trend: { series: [], change: 5 },
                    watch: { watching: true, threshold: '10', channels: ['app', 'email'] },
                    market: { ...body.props.market, total: products.length, products },
                },
            },
        });
    });

    await page.getByLabel('Bozor mahsulotini qidirish').fill('freshness test');
    await page.getByRole('button', { name: 'Narxlarni topish' }).click();
    const staleBadge = page.getByText('Eskirgan narx · qayta tekshiring', { exact: true });
    await expect(staleBadge).toBeVisible();
    await expect(page.getByText('Taklif muddati tugagan', { exact: true })).toBeVisible();
    await expect(page.getByText('Kuzatuv vaqti noma’lum', { exact: true })).toBeVisible();
    await expect(page.getByText('Rasmiy o‘rtacha · sentabr 2026', { exact: true })).toBeVisible();
    await expect(page.locator('[aria-label="Narx kuzatuvi"]')).toHaveCount(1);
    await expect(page.getByLabel('Narx o‘zgarishi chegarasi')).toHaveValue('10');
    await expect(page.getByRole('button', { name: 'Chegarani saqlash' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Kuzatuvni bekor qilish' })).toBeVisible();
    await expect(page.getByLabel('Narx kuzatuvi emaili')).toHaveValue('1');
    await expect(page.locator('.market-watch')).toContainText('SMTP qabul qilishi inboxga yetkazilganini tasdiqlamaydi.');
    await page.evaluate(() => { document.documentElement.dataset.theme = 'light'; });
    await expect(staleBadge).toHaveCSS('align-self', 'flex-start');
    await expect(staleBadge).toHaveCSS('color', 'rgb(148, 99, 11)');
});

test('photo recognition opens an honest catalogue search with official averages labeled', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`consumer-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/market$/);
    await expect(page.getByRole('link', { name: 'Admin panel', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Tashkilotni tanlash')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Toshkent narxlari', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Kamera bilan olish' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Fayldan yuklash' })).toBeVisible();
    await page.getByLabel('Rasm faylini yuklash').setInputFiles(join(dirname(browserEnvironment.DB_DATABASE), 'fixture.png'));
    const button = page.getByRole('button', { name: 'AI bilan mahsulotni topish', exact: true });
    if (await button.isDisabled()) {
        await expect(page.getByRole('status')).toContainText('serverda OPENROUTER_API_KEY sozlanmagan');
        return;
    }
    await page.route('**/market?*', async route => {
        const response = await route.fetch();
        const body = await response.json();
        await route.fulfill({
            response,
            json: { ...body, props: { ...body.props, market: { ...body.props.market, products: [], total: 0 } } },
        });
    });
    await page.route('**/market/scan', route => route.fulfill({ json: {
        recognition: { name: 'Olma', brand: null, package: null, uncertain: true, search_terms: ['olma', 'яблоки', 'apple'] },
        market: { products: [], total: 0, checked_at: null, stale: true },
    } }));
    await button.click();
    await expect(page).toHaveURL(url => url.pathname === '/market'
        && url.searchParams.get('q') === 'Olma'
        && url.searchParams.get('department') === 'all'
        && url.searchParams.get('source') === 'all'
        && [...url.searchParams.entries()].some(([key, value]) => key.startsWith('terms') && value === 'apple'));
    await expect(page.getByText('AI taxmini · tekshirib ko‘ring', { exact: true })).toBeVisible();
    await expect(page.getByText('AI rasmga qarab taxmin qildi. Mahsulot nomi yoki turi noto‘g‘ri bo‘lishi mumkin; kerak bo‘lsa, qidiruv maydonida nomini o‘zgartiring.', { exact: true })).toBeVisible();
    const review = page.getByRole('region', { name: 'Mahsulot mosligini tasdiqlash' });
    await expect(review).toContainText('Katalog natijalarida matn bo‘yicha mos nomzod topilmadi.');
    await expect(page.getByRole('region', { name: 'Savatcha yig‘indisi' })).toHaveCount(0);
    await expect(page.locator('.market-source-grid .source-filter').filter({ hasText: 'Milliy statistika' })).toBeVisible();
    await expect(page.locator('.market-disclaimer')).toContainText('rasmiy o‘rtachalar kuzatuv davri bilan ko‘rsatiladi');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: test.info().outputPath('photo-market.png'), fullPage: true });
});

test('photo price matches require confirmation and expose package differences', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`consumer-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/market$/);
    await page.getByLabel('Rasm faylini yuklash').setInputFiles(join(dirname(browserEnvironment.DB_DATABASE), 'fixture.png'));
    const scanButton = page.getByRole('button', { name: 'AI bilan mahsulotni topish', exact: true });
    if (await scanButton.isDisabled()) {
        await expect(page.getByRole('status')).toContainText('serverda OPENROUTER_API_KEY sozlanmagan');
        return;
    }

    await page.route('**/market?*', async route => {
        const response = await route.fetch();
        const body = await response.json();
        const products = [
            {
                id: 'fixture-128', key: 'fixture-128', source_id: 'catalog', source_name: 'Sinov katalogi',
                checked_at: null, availability: 'unknown', name: 'Apple iPhone 15 128 GB', brand: 'Apple', model: 'iPhone 15',
                price: '8900000.00', currency: 'UZS', unit_label: '1 dona', category: 'Smartphone', image: null,
                valid_until: null, card_required: false, conditions: '', source_url: 'https://example.test/iphone-128',
                package_label: '128 GB', alias_match: true, variant_match: true, barcode_match: true,
            },
            {
                id: 'fixture-256', key: 'fixture-256', source_id: 'catalog', source_name: 'Sinov katalogi',
                checked_at: null, availability: 'unknown', name: 'Apple iPhone 15 256 GB', brand: 'Apple', model: 'iPhone 15',
                price: '9500000.00', currency: 'UZS', unit_label: '1 dona', category: 'Smartphone', image: null,
                valid_until: null, card_required: false, conditions: '', source_url: 'https://example.test/iphone-256',
                package_label: '256 GB',
            },
        ];

        await route.fulfill({
            response,
            json: {
                ...body,
                props: {
                    ...body.props,
                    market: { ...body.props.market, total: products.length, products },
                },
            },
        });
    });
    await page.route('**/market/scan', route => route.fulfill({ json: {
        recognition: { name: 'iPhone 15', brand: 'Apple', package: '128 GB', uncertain: false, search_terms: ['iPhone 15', 'Apple', 'telefon'] },
        insight: {
            query: 'iPhone 15', best: { name: 'Apple iPhone 15 128 GB', price: '8900000.00', currency: 'UZS', source_name: 'Sinov katalogi', source_url: 'https://example.test/iphone-128', unit_label: '1 dona' },
            worst: { name: 'Apple iPhone 15 256 GB', price: '9500000.00' }, savings: '600000.00', savings_percent: 6,
            offers: 2, sources: ['Sinov katalogi'],
        },
        market: { products: [], total: 0, checked_at: null, stale: true },
    } }));

    await scanButton.click();
    await expect(page).toHaveURL(url => url.pathname === '/market' && url.searchParams.get('q') === 'iPhone 15');
    const review = page.getByRole('region', { name: 'Mahsulot mosligini tasdiqlash' });
    const candidateSelect = review.getByLabel('Katalog mosligi: iPhone 15');
    await expect(candidateSelect.locator('option')).toHaveCount(3);
    await expect(page.getByRole('region', { name: 'Savatcha yig‘indisi' })).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Narx xulosasi' })).toHaveCount(0);

    await candidateSelect.selectOption('fixture-256');
    await expect(review.getByText('Qadoq farq qiladi', { exact: true })).toBeVisible();
    await candidateSelect.selectOption('fixture-128');
    await expect(review.getByText('Qadoq mos', { exact: true })).toBeVisible();
    await expect(review.getByText('Katalog aliasi mos', { exact: true })).toBeVisible();
    await expect(review.getByText('Variant ma’lumoti mos', { exact: true })).toBeVisible();
    await expect(review.getByText('Barcode mos', { exact: true })).toBeVisible();
    await review.getByRole('button', { name: 'Mahsulotni tasdiqlash' }).click();

    await expect(review.getByRole('status')).toContainText('Tasdiqlandi: Apple iPhone 15 128 GB');
    await expect(page.getByRole('region', { name: 'Savatcha yig‘indisi' })).toContainText('128 GB');
    await expect(page.getByRole('region', { name: 'Savatcha yig‘indisi' })).toContainText('8\u00a0900\u00a0000 so‘m');
    await expect(page.getByRole('region', { name: 'Narx xulosasi' })).toBeVisible();
});

test('native camera picker is offered when direct camera access is unavailable', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`consumer-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/market$/);
    await page.addInitScript(() => {
        Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined });
    });
    await page.reload();

    await page.getByRole('button', { name: 'Kamera bilan olish' }).click();
    await expect(page.getByRole('alert')).toContainText('Brauzer bevosita kamera oqimini bermadi');
    const chooser = page.waitForEvent('filechooser');
    await page.getByText('Qurilma kamerasi orqali olish', { exact: true }).click();
    await (await chooser).setFiles(join(dirname(browserEnvironment.DB_DATABASE), 'fixture.png'));
    await expect(page.getByAltText('Tanlangan mahsulot surati')).toBeVisible();
});


test('construction department and supplier filters survive a product search', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`consumer-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/market$/);
    await expect(page.getByRole('link', { name: 'Admin panel', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Tashkilotni tanlash')).toHaveCount(0);
    await page.getByRole('navigation', { name: 'Mahsulot yo‘nalishi' }).getByRole('link', { name: 'Qurilish mollari', exact: true }).click();
    await expect(page.locator('.market-source-grid .source-filter')).toHaveCount(3);
    await page.getByRole('link', { name: /TezQur/ }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('source') === 'tezqur');
    await expect(page.locator('.source-selected')).toContainText('TezQur');
    await page.getByLabel('Bozor mahsulotini qidirish').fill('sement');
    await page.getByRole('button', { name: 'Narxlarni topish', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('q') === 'sement' && url.searchParams.get('department') === 'construction' && url.searchParams.get('source') === 'tezqur');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
});
