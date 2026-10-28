const { test, expect } = require('@playwright/test');

// Installation check only: this does not certify any SnapSmack skin.
test('browser can load a page and operate a control', async ({ page }) => {
  await page.route('https://installation.test/', route => route.fulfill({
    contentType: 'text/html',
    body: '<!doctype html><html><head><title>Browser installation check</title></head><body><label><input type="checkbox"> Enable test option</label></body></html>',
  }));
  await page.goto('https://installation.test/');
  await expect(page).toHaveTitle('Browser installation check');
  const option = page.getByRole('checkbox', { name: 'Enable test option' });
  await expect(option).not.toBeChecked();
  await option.check();
  await expect(option).toBeChecked();
});
