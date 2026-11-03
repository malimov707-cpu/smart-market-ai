import { expect, test } from '@playwright/test';

test('social sign-in choices appear only on the registration page', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('.auth-social-grid')).toHaveCount(0);

    await page.goto('/register');
    const socialChoices = page.locator('.auth-social-grid');
    await expect(socialChoices).toBeVisible();
    await expect(socialChoices.locator('> *')).toHaveCount(2);
});

test('admin overview and user management stay in one organized control panel', async ({ page }) => {
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill('admin-desktop@example.test');
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();

    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('.admin-overview-grid')).toBeVisible();
    await expect(page.locator('.admin-workspace .stats-grid .stat-card')).toHaveCount(14);
    await expect(page.getByRole('heading', { name: 'Yangi foydalanuvchilar', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Yangi tashkilotlar', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Yangi mahsulotlar', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Manbalar holati', exact: true })).toBeVisible();
    const viewport = page.viewportSize();
    if (viewport && viewport.width < 700) {
        await page.getByRole('button', { name: 'Admin menyuni ochish' }).click();
    }
    await page.getByRole('link', { name: 'Foydalanuvchilar', exact: true }).click();
    await expect(page).toHaveURL(/tab=users/);
    await expect(page.locator('.admin-workspace .stats-grid')).toHaveCount(0);
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Bozor katalogi', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Mahsulotlar ish maydoni', exact: true })).toBeVisible();
});

test('light theme preference carries from authentication into the workspace', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';

    await page.goto('/register');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('.auth-form')).toHaveCSS('background-color', 'rgb(7, 14, 24)');
    await page.getByRole('button', { name: 'Yorug‘ mavzuga o‘tish' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('.auth-form')).toHaveCSS('background-color', 'rgb(248, 250, 255)');

    await page.goto('/login');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await expect(page.locator('.auth-story h1')).toHaveCSS('color', 'rgb(38, 63, 89)');
    await expect(page.locator('.auth-feature-list')).toHaveCSS('color', 'rgb(82, 109, 137)');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    const email = login.getByLabel('Email', { exact: true });
    await expect(email).toHaveCSS('background-color', 'rgb(255, 255, 255)');
    await expect(email).toHaveCSS('color', 'rgb(41, 56, 78)');
    await page.getByRole('button', { name: 'Qorong‘i mavzuga o‘tish' }).click();
    await expect(page.locator('.auth-story h1')).toHaveCSS('color', 'rgb(230, 242, 250)');
    await expect(email).toHaveCSS('background-color', 'rgb(9, 22, 33)');
    await page.getByRole('button', { name: 'Yorug‘ mavzuga o‘tish' }).click();

    await email.fill(`${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await page.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('.workspace')).toHaveCSS('background-color', 'rgb(244, 243, 239)');
    await expect(page.locator('.sidebar')).toHaveCSS('background-color', 'rgb(255, 254, 251)');
    await expect(page.getByRole('button', { name: 'Qorong‘i mavzuga o‘tish' })).toBeVisible();
    await page.getByRole('button', { name: 'Qorong‘i mavzuga o‘tish' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('.workspace')).toHaveCSS('background-color', 'rgb(5, 10, 17)');

    await page.goto('/market');
    await expect(page.getByRole('button', { name: 'Yorug‘ mavzuga o‘tish' })).toBeVisible();
    await page.getByRole('button', { name: 'Yorug‘ mavzuga o‘tish' }).click();
    await expect(page.locator('.workspace')).toHaveCSS('background-color', 'rgb(244, 243, 239)');
});

test('platform user completes catalog, offer comparison and account workflow', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`${platform}@example.test`);
    await page.getByRole('form', { name: 'Hisobga kirish' }).getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await page.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/products');
    await page.getByRole('button', { name: 'Yangi yozuv' }).click();
    await page.getByLabel('Mahsulot nomi', { exact: true }).fill('AUTOMATED TEST Flour');
    await page.getByLabel('SKU', { exact: true }).fill(`BROWSER-${platform}`);
    await page.getByRole('button', { name: 'Saqlash', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Mahsulot qo‘shildi');
    await page.goto('/suppliers');
    await page.getByRole('button', { name: 'Yangi yozuv' }).click();
    await page.getByLabel('Nomi', { exact: true }).fill('AUTOMATED TEST Supplier');
    await page.getByRole('button', { name: 'Saqlash', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Yetkazib beruvchi qo‘shildi');
    await page.goto('/offers');
    await page.getByRole('button', { name: 'Yangi yozuv' }).click();
    await page.getByRole('combobox', { name: 'Mahsulot', exact: true }).selectOption({ label: 'AUTOMATED TEST Flour' });
    await page.getByRole('combobox', { name: 'Yetkazib beruvchi', exact: true }).selectOption({ label: 'AUTOMATED TEST Supplier' });
    await page.getByLabel('Qadoq narxi', { exact: true }).fill('100.25');
    await page.getByLabel('Qadoq miqdori', { exact: true }).fill('2');
    await page.getByLabel('Yetkazish narxi (noma’lum bo‘lsa bo‘sh) (ixtiyoriy)', { exact: true }).fill('5.50');
    await page.getByLabel('Kuzatuv vaqti (+05:00 bilan kiriting)', { exact: true }).fill(new Date(Date.now() - 60000).toISOString());
    await page.getByLabel('Manba / hujjat raqami', { exact: true }).fill('AUTOMATED TEST evidence');
    await page.getByRole('button', { name: 'Saqlash', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Taklif saqlandi');
    await page.goto('/comparison');
    await page.getByRole('combobox', { name: 'Mahsulot', exact: true }).selectOption({ label: 'AUTOMATED TEST Flour (kg)' });
    await page.getByRole('button', { name: 'Takliflarni solishtirish' }).click();
    await expect(page.getByText('1 008 UZS', { exact: true })).toBeVisible();
    await expect(page.getByText('Yetkazib beruvchi tasdiqlanmagan', { exact: true })).toBeVisible();
    await page.goto('/account');
    await expect(page.getByRole('heading', { name: 'Hisob va xavfsizlik' })).toBeVisible();
    await expect(page.getByText('Joriy sessiya', { exact: true })).toBeVisible();
});

test('new registrations open public prices while AI image search remains email protected', async ({ page }, info) => {
    await page.goto('/register');
    await page.getByLabel('Ismingiz', { exact: true }).fill('Browser Registration Test');
    await page.getByLabel('Email', { exact: true }).fill(`register-${info.project.name}@example.test`);
    await page.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await page.getByLabel('Parolni tasdiqlang', { exact: true }).fill('BrowserPassword123456');
    await page.getByRole('button', { name: 'Hisob yaratish', exact: true }).click();
    await expect(page).toHaveURL(/\/market$/);
    await expect(page.getByRole('heading', { name: 'Toshkent narxlari', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Narxlar panelingiz tayyor', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'AI bilan mahsulotni topish', exact: true })).toBeDisabled();
    await page.getByLabel('Bozor mahsulotini qidirish').fill('sement');
    await page.getByRole('button', { name: 'Narxlarni topish', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/market' && url.searchParams.get('q') === 'sement');
    await page.goto('/products');
    await expect(page).toHaveURL(/verify-email/);
    await expect(page.getByRole('button', { name: 'Tasdiqlash xatini qayta yuborish' })).toBeVisible();
    await page.getByRole('link', { name: 'Narxlar panelini ochish', exact: true }).click();
    await expect(page).toHaveURL(/\/market$/);
});
