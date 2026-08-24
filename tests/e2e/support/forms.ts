import { expect } from '@playwright/test'
import type { Page } from '@playwright/test'

/**
 * Fills an input and makes sure the value survives.
 *
 * Nuxt renders the form on the server, so the input exists and accepts typing before
 * Vue has hydrated. When hydration lands it patches the DOM from reactive state,
 * which is still empty — silently wiping whatever was typed a moment earlier. The
 * result is a form that submits with one field blank, and an error message
 * ("The email field is required") that looks like a backend bug.
 *
 * Waiting for a mount flag is not enough: the flag is set when mounting begins, and
 * the patch can land after it. Verifying the value and re-filling is what actually
 * closes the window.
 *
 * Real users hit the same window on a slow connection; that is a genuine (small) UX
 * cost of SSR, tracked as a hardening item rather than papered over here.
 */
export async function fillStable(page: Page, selector: string, value: string): Promise<void> {
  const field = page.locator(selector)

  await field.waitFor({ state: 'visible' })

  for (let attempt = 0; attempt < 3; attempt++) {
    await field.fill(value)

    // Give hydration a chance to overwrite before believing the value stuck.
    await page.waitForTimeout(150)

    if ((await field.inputValue()) === value) {
      await expect(field).toHaveValue(value)

      return
    }
  }

  throw new Error(`Could not keep a stable value in ${selector} after 3 attempts.`)
}

/**
 * Checks a checkbox and confirms it stayed checked, for the same reason as above.
 */
export async function checkStable(page: Page, selector: string): Promise<void> {
  const box = page.locator(selector)

  await box.waitFor({ state: 'visible' })

  for (let attempt = 0; attempt < 3; attempt++) {
    await box.check()
    await page.waitForTimeout(150)

    if (await box.isChecked()) {
      await expect(box).toBeChecked()

      return
    }
  }

  throw new Error(`Could not keep ${selector} checked after 3 attempts.`)
}
