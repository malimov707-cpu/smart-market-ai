import { expect, test } from '@playwright/test';
import { dirname, join } from 'node:path';
import { browserEnvironment } from '../../playwright.config';

test('photo recognition opens an honest catalogue search on desktop and mobile', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`manager-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/market');
    await expect(page.getByRole('heading', { name: 'Toshkent narxlari', exact: true })).toBeVisible();
    await page.getByLabel('Mahsulot rasmini tanlash').setInputFiles(join(dirname(browserEnvironment.DB_DATABASE), 'fixture.png'));
    const button = page.getByRole('button', { name: 'Suratdan topish', exact: true });
    if (await button.isDisabled()) {
        await expect(page.getByText('Suratni tanish uchun OpenRouter kaliti sozlanishi kerak.')).toBeVisible();
        return;
    }
    await page.route('**/market/scan', route => route.fulfill({ json: {
        recognition: { name: 'Olma', brand: null, package: null, uncertain: true, search_terms: ['olma', 'яблоки'] },
        market: { products: [], total: 0, checked_at: null, stale: true },
    } }));
    await button.click();
    await expect(page).toHaveURL(url => url.pathname === '/market' && url.searchParams.get('q') === 'olma');
    await expect(page.getByText('Aniqlashtirish kerak', { exact: false })).toBeVisible();
    await expect(page.getByText('Tanlangan manbaning katalogi yuklanishi kutilmoqda.', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: test.info().outputPath('photo-market.png'), fullPage: true });
});


test('construction department and supplier filters survive a product search', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`manager-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/market');
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
