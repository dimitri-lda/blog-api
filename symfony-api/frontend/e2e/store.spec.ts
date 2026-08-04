import { expect, type Page, test } from '@playwright/test';

async function addProduct(page: Page) {
  await page.goto('/shop/cloudswift-4');
  await expect(page.getByRole('heading', { name: 'Cloudswift 4' })).toBeVisible();
  await page.getByRole('button', { name: /Add to bag/ }).click();
  await expect(page.getByRole('button', { name: /Added/ })).toBeVisible();
}

async function checkout(page: Page, email: string) {
  await page.goto('/checkout');
  await page.getByPlaceholder('Email address').fill(email);
  await page.getByPlaceholder('Phone number').fill('+48 123 456 789');
  await page.getByPlaceholder('First name').fill('E2E');
  await page.getByPlaceholder('Last name').fill('Shopper');
  await page.getByPlaceholder('Address', { exact: true }).fill('Testowa 12');
  await page.getByPlaceholder('City').fill('Warsaw');
  await page.getByPlaceholder('Postal code').fill('00-001');
  await page.getByRole('button', { name: /Place order/ }).click();
  await expect(page.getByRole('heading', { name: 'Thank you.' })).toBeVisible();
  const confirmation = await page.getByText(/Your order SP-/).textContent();
  return confirmation?.match(/SP-[A-F0-9]+/)?.[0] ?? '';
}

test.describe.serial('store smoke flows', () => {
  let userOrder = '';

  test('guest can buy a product', async ({ page }) => {
    await addProduct(page);
    const order = await checkout(page, 'guest-e2e@example.com');
    expect(order).toMatch(/^SP-/);
  });

  test('signed-in user can buy a product', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('test@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('heading', { name: /Hello, Test User/ })).toBeVisible();
    await addProduct(page);
    userOrder = await checkout(page, 'test@example.com');
    expect(userOrder).toMatch(/^SP-/);
  });

  test('password reset email reaches Mailpit', async ({ page, request }) => {
    await request.delete('http://mailpit:8025/api/v1/messages');
    await page.goto('/forgot-password');
    await page.getByPlaceholder('Email address').fill('test@example.com');
    await page.getByRole('button', { name: 'Email reset link' }).click();
    await expect(page.getByText(/Check your inbox/)).toBeVisible();
    await expect.poll(async () => {
      const response = await request.get('http://mailpit:8025/api/v1/messages');
      const body = await response.json();
      return body.messages?.some((message: { Subject: string }) => message.Subject === 'Reset your daoSport password');
    }).toBe(true);
  });

  test('administrator can process the user order', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('admin@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('heading', { name: /Hello, Store Admin/ })).toBeVisible();
    await page.goto('/admin/orders');
    await page.getByPlaceholder('Order or email…').fill(userOrder);
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.getByRole('link', { name: userOrder }).click();
    await page.getByRole('combobox').selectOption('paid');
    await expect(page.locator('p.text-xl').filter({ hasText: 'Paid' })).toBeVisible();
  });
});
