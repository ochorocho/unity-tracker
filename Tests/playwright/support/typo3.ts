import { expect, type Page } from '@playwright/test';

export const ADMIN = { username: 'admin', password: 'Password.1' };

export async function login(page: Page, username = ADMIN.username, password = ADMIN.password): Promise<void> {
  await page.goto('/typo3/');
  await page.locator('#t3-username').fill(username);
  await page.locator('#t3-password').fill(password);
  await page.locator('#t3-login-submit').click();
}

export async function loginAsAdmin(page: Page): Promise<void> {
  await login(page);
  await expect(page.locator('.scaffold, typo3-backend-module-router').first()).toBeVisible();
}
