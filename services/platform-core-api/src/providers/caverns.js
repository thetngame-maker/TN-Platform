import crypto from "node:crypto";
import { withPage } from "../browser.js";
import { config } from "../config.js";

const SHOWS_URL = "https://www.thecaverns.com/shows";
const MONTHS = new Map([
  ["jan", 0], ["january", 0], ["feb", 1], ["february", 1], ["mar", 2], ["march", 2],
  ["apr", 3], ["april", 3], ["may", 4], ["jun", 5], ["june", 5], ["jul", 6], ["july", 6],
  ["aug", 7], ["august", 7], ["sep", 8], ["sept", 8], ["september", 8], ["oct", 9], ["october", 9],
  ["nov", 10], ["november", 10], ["dec", 11], ["december", 11],
]);

function clean(value) {
  return String(value || "").replace(/\s+/g, " ").trim();
}

function externalId(url) {
  return crypto.createHash("sha256").update(url).digest("hex").slice(0, 24);
}

function inferYear(monthIndex, day) {
  const now = new Date();
  let year = now.getUTCFullYear();
  const candidate = Date.UTC(year, monthIndex, day, 12);
  if (candidate < now.getTime() - 90 * 86400000) year += 1;
  return year;
}

function centralOffset(monthIndex) {
  return monthIndex >= 2 && monthIndex <= 10 ? "-05:00" : "-06:00";
}

function parseDateTime(dateText, timeText) {
  const dateMatch = clean(dateText).match(/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2})(?:,?\s+(\d{4}))?/i);
  if (!dateMatch) return "";
  const monthIndex = MONTHS.get(dateMatch[1].toLowerCase());
  const day = Number(dateMatch[2]);
  const year = dateMatch[3] ? Number(dateMatch[3]) : inferYear(monthIndex, day);
  const timeMatch = clean(timeText).match(/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i);
  let hour = 12;
  let minute = 0;
  if (timeMatch) {
    hour = Number(timeMatch[1]) % 12;
    if (timeMatch[3].toLowerCase() === "pm") hour += 12;
    minute = Number(timeMatch[2] || 0);
  }
  const pad = value => String(value).padStart(2, "0");
  return `${year}-${pad(monthIndex + 1)}-${pad(day)}T${pad(hour)}:${pad(minute)}:00${centralOffset(monthIndex)}`;
}

function normalize(raw) {
  const title = clean(raw.title);
  const sourceUrl = clean(raw.sourceUrl);
  const ticketUrl = clean(raw.ticketUrl);
  const text = clean(raw.text);
  const doors = text.match(/Doors?\s*(?:open\s*)?(?:at\s*)?(\d{1,2}(?::\d{2})?\s*(?:am|pm))/i)?.[1] || "";
  const time = text.match(/\b(\d{1,2}(?::\d{2})?\s*(?:am|pm))\b/i)?.[1] || "";
  const date = text.match(/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:,?\s+\d{4})?/i)?.[0] || "";
  const artist = title.split(/\s+(?:in|at|with|presents)\s+/i)[0]?.trim();
  return {
    provider: "caverns-official",
    external_id: externalId(sourceUrl),
    url: ticketUrl || sourceUrl,
    source_url: sourceUrl,
    title,
    description: text,
    image: clean(raw.image),
    start: parseDateTime(date, time),
    end: "",
    doors: clean(doors),
    venue: "The Caverns",
    address: "555 Charlie Roberts Rd, Pelham, TN 37366",
    status: /cancelled|canceled/i.test(text) ? "cancelled" : /postponed/i.test(text) ? "postponed" : /sold out/i.test(text) ? "sold-out" : "scheduled",
    price: "",
    currency: "USD",
    age: /\b(18\+|21\+|all ages)\b/i.exec(text)?.[1] || "",
    artists: artist ? [artist] : [],
  };
}

export async function syncCavernsOfficial() {
  return withPage(async page => {
    const response = await page.goto(SHOWS_URL, { waitUntil: "domcontentloaded", timeout: config.BROWSER_TIMEOUT_MS });
    if (!response || response.status() >= 400) throw new Error(`Official shows page returned HTTP ${response?.status() || 0}`);
    await page.waitForTimeout(1800);
    for (let i = 0; i < 6; i += 1) {
      await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
      await page.waitForTimeout(300);
    }

    const cards = await page.evaluate(() => {
      const links = [...document.querySelectorAll('a[href*="/event/"]')];
      const byUrl = new Map();
      for (const link of links) {
        const sourceUrl = link.href;
        if (!/^https:\/\/www\.thecaverns\.com\/event\//i.test(sourceUrl)) continue;
        let node = link;
        let best = link.parentElement;
        for (let depth = 0; depth < 7 && node?.parentElement; depth += 1) {
          node = node.parentElement;
          const text = (node.innerText || "").replace(/\s+/g, " ").trim();
          if (text.length >= 30 && text.length <= 1800) best = node;
          if (/\bDoors?\b/i.test(text) && /\b(?:am|pm)\b/i.test(text)) break;
        }
        const text = (best?.innerText || link.innerText || "").replace(/\s+/g, " ").trim();
        const headings = [...(best?.querySelectorAll("h1,h2,h3,h4") || [])]
          .map(el => (el.textContent || "").replace(/\s+/g, " ").trim())
          .filter(Boolean);
        const title = headings.find(value => !/^(shows?|learn more|buy)$/i.test(value)) || (link.textContent || "").trim();
        const image = best?.querySelector("img")?.currentSrc || best?.querySelector("img")?.src || "";
        const ticket = [...(best?.querySelectorAll("a[href]") || [])].find(a => /tixr\.com/i.test(a.href));
        const candidate = { sourceUrl, ticketUrl: ticket?.href || "", title, text, image };
        const existing = byUrl.get(sourceUrl);
        if (!existing || candidate.text.length > existing.text.length) byUrl.set(sourceUrl, candidate);
      }
      return [...byUrl.values()];
    });

    const events = cards
      .map(normalize)
      .filter(event => event.title && event.source_url)
      .slice(0, config.MAX_EVENTS_PER_SYNC);

    return {
      provider: "caverns-official",
      source_url: SHOWS_URL,
      discovered: events.length,
      events,
      failures: [],
      fetched_at: new Date().toISOString(),
      diagnostics: { strategy: "single-page-listing", cards_found: cards.length },
    };
  });
}
