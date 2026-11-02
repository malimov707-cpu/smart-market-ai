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
    await page.route('**/market/scan', route => route.fulfill({ json: {
        recognition: { name: 'Olma', brand: null, package: null, uncertain: true, search_terms: ['olma', 'яблоки'] },
        market: { products: [], total: 0, checked_at: null, stale: true },
    } }));
    await button.click();
    await expect(page).toHaveURL(url => url.pathname === '/market' && url.searchParams.get('q') === 'olma');
    await expect(page.getByText('AI taxmini · tekshirib ko‘ring', { exact: true })).toBeVisible();
    await expect(page.getByText('AI rasmga qarab taxmin qildi. Mahsulot nomi yoki turi noto‘g‘ri bo‘lishi mumkin; kerak bo‘lsa, qidiruv maydonida nomini o‘zgartiring.', { exact: true })).toBeVisible();
    await expect(page.locator('.market-source-grid .source-filter').filter({ hasText: 'Milliy statistika' })).toBeVisible();
    await expect(page.locator('.market-disclaimer')).toContainText('rasmiy o‘rtachalar kuzatuv davri bilan ko‘rsatiladi');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: test.info().outputPath('photo-market.png'), fullPage: true });
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
