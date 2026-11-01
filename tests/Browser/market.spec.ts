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
    await expect(page).toHaveURL(/\/market\?q=olma/);
    await expect(page.getByText('Aniqlashtirish kerak', { exact: false })).toBeVisible();
    await expect(page.getByText('Korzinka katalogi yuklanishi kutilmoqda.', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: test.info().outputPath('photo-market.png'), fullPage: true });
});
