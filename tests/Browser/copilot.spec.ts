import { expect, test } from '@playwright/test';

test('copilot shows configuration state or handles successful and failed chat requests', async ({ page }, info) => {
    const platform = info.project.name.startsWith('desktop') ? 'desktop' : 'mobile';
    await page.goto('/login');
    const login = page.getByRole('form', { name: 'Hisobga kirish' });
    await login.getByLabel('Email', { exact: true }).fill(`manager-${platform}@example.test`);
    await login.getByLabel('Parol', { exact: true }).fill('BrowserPassword123456');
    await login.getByRole('button', { name: 'Kirish', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/ai');
    const chat = page.getByRole('region', { name: 'OpenRouter AI yordamchi' });
    const question = chat.getByLabel('AI yordamchiga savol');
    if (await question.isDisabled()) {
        await expect(chat.getByText('Kalit kerak', { exact: true })).toBeVisible();
        await expect(chat.getByRole('button', { name: 'Yuborish', exact: true })).toBeDisabled();
        return;
    }
    let call = 0;
    await page.route('**/ai/chat', async route => {
        if (route.request().method() === 'DELETE') {
            await route.fulfill({ json: { cleared: true } });
            return;
        }
        call++;
        if (call === 1) {
            expect(route.request().postDataJSON().message).toBe('Takliflar haqida ayting');
            await route.fulfill({ json: { answer: 'Bu TEST javobi. <script>window.pwned=true</script>', model: 'test/browser-model', tokens: 42, truncated: false, sources: [] } });
        } else {
            await route.fulfill({ status: 502, json: { message: 'TEST: OpenRouter vaqtincha javob bermayapti.' } });
        }
    });
    await question.fill('Takliflar haqida ayting');
    await chat.getByRole('button', { name: 'Yuborish', exact: true }).click();
    await expect(chat.getByText('Bu TEST javobi.', { exact: false })).toBeVisible();
    expect(await page.evaluate(() => 'pwned' in window)).toBeFalsy();
    await question.fill('Yana tushuntiring');
    await chat.getByRole('button', { name: 'Yuborish', exact: true }).click();
    await expect(chat.getByRole('alert')).toHaveText('TEST: OpenRouter vaqtincha javob bermayapti.');
    await expect(question).toHaveValue('Yana tushuntiring');
    await chat.getByRole('button', { name: 'Suhbatni tozalash', exact: true }).click();
    await expect(chat.getByText('Xaridingiz haqida savol bering.', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
});
