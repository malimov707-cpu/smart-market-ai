import { expect, test } from '@playwright/test';

test('platform administrator searches suspends and restores an account', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    await page.getByRole('form', { name: 'Hisobga kirish' }).getByLabel('Email', { exact: true }).fill(`admin-${platform}@example.test`);
    await page.getByRole('form', { name: 'Hisobga kirish' }).getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await page.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByRole('heading', { name: 'Admin panel', exact: true })).toBeVisible();
    await page.goto('/market');
    await expect(page.getByLabel('Hisob tasdig‘i')).toHaveCount(0);
    await page.goto('/admin');
    await page.getByLabel('Foydalanuvchi qidirish').fill(`admin-target-${platform}@example.test`);
    await page.getByRole('button', { name: 'Qidirish', exact: true }).click();
    await expect(page).toHaveURL(new RegExp('q=admin-target-' + platform));
    await expect(page.getByRole('button', { name: 'Bloklash', exact: true })).toHaveCount(1);
    page.on('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Bloklash', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Foydalanuvchi holati yangilandi');
    await expect(page.getByRole('cell').getByText('Bloklangan', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Blokdan chiqarish', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Bloklash', exact: true })).toBeVisible();
    await page.screenshot({ path: test.info().outputPath('admin-panel.png'), fullPage: true });
});

test('administrator views platform operations and navigates to market analysis with keyboard search', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const form = page.getByRole('form', { name: 'Hisobga kirish' });
    await form.getByLabel('Email', { exact: true }).fill(`admin-${platform}@example.test`);
    await form.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await form.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin?tab=operations');
    await expect(page.getByText('Tizimning haqiqiy holati', { exact: true })).toBeVisible();
    await expect(page.getByText('OPENROUTER · AI YORDAMCHI', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Tezkor navigatsiyani ochish' }).click();
    const dialog = page.getByRole('dialog', { name: 'Tezkor navigatsiya' });
    await dialog.getByLabel('Sahifalarni qidirish').fill('Xarid intellekti');
    await dialog.getByLabel('Sahifalarni qidirish').press('Enter');
    await expect(page.getByRole('heading', { name: 'Xarid intellekti', exact: true })).toBeVisible();
    await expect(page).toHaveURL(/\/ai$/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: test.info().outputPath('decision-desk.png'), fullPage: true });
});
