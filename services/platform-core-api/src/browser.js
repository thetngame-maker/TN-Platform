import { chromium } from "playwright";
import { config } from "./config.js";

const launchOptions = Object.freeze({
  headless: true,
  args: ["--disable-dev-shm-usage", "--no-sandbox"],
});

export async function withBrowser(callback) {
  const browser = await chromium.launch(launchOptions);
  try {
    return await callback(browser);
  } finally {
    await browser.close().catch(() => {});
  }
}

export async function withPage(callback) {
  return withBrowser(async browser => {
    const context = await browser.newContext({
      userAgent:
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) " +
        "AppleWebKit/537.36 (KHTML, like Gecko) " +
        "Chrome/126.0.0.0 Safari/537.36",
      locale: "en-US",
      timezoneId: "America/Chicago",
      viewport: { width: 1440, height: 1200 },
      ignoreHTTPSErrors: false,
    });

    const page = await context.newPage();
    page.setDefaultTimeout(config.BROWSER_TIMEOUT_MS);
    page.setDefaultNavigationTimeout(config.BROWSER_TIMEOUT_MS);

    try {
      return await callback(page, context);
    } finally {
      await context.close().catch(() => {});
    }
  });
}
