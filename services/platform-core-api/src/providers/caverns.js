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
  const ninetyDaysAgo = now.getTime() - 90 * 86400000;
  if (candidate < ninetyDaysAgo) year += 1;
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

function parsePrice(bodyText) {
  const values = [...String(bodyText || "").matchAll(/\$([0-9]+(?:\.[0-9]{2})?)/g)].map(match => Number(match[1])).filter(Number.isFinite);
  return values.length ? String(Math.min(...values)) : "";
}

async function collectEventLinks(page) {
  await page.goto(SHOWS_URL, { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(1800);
  for (let i = 0; i < 8; i += 1) {
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.waitForTimeout(450);
  }
  return page.evaluate(() => [...new Set(
    [...document.querySelectorAll('a[href*="/event/"]')]
      .map(anchor => anchor.href)
      .filter(url => /^https:\/\/www\.thecaverns\.com\/event\//i.test(url))
  )]);
}

async function extractEvent(page, url) {
  const response = await page.goto(url, { waitUntil: "domcontentloaded" });
  if (!response || response.status() >= 400) throw new Error(`Official event page returned HTTP ${response?.status() || 0}`);
  await page.waitForTimeout(600);
  const raw = await page.evaluate(() => {
    const text = selector => (document.querySelector(selector)?.textContent || "").replace(/\s+/g, " ").trim();
    const bodyText = document.body?.innerText || "";
    const headings = [...document.querySelectorAll("h1,h2")].map(node => (node.textContent || "").replace(/\s+/g, " ").trim()).filter(Boolean);
    const dateCandidate = [...document.querySelectorAll("time,h1,h2,h3,p,li,div")]
      .map(node => (node.textContent || "").replace(/\s+/g, " ").trim())
      .find(value => /\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2}\b/i.test(value) && value.length < 80) || "";
    const timeCandidate = bodyText.match(/\b\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/i)?.[0] || "";
    const doors = bodyText.match(/Doors?\s+(?:open\s+)?(?:at\s+)?(\d{1,2}(?::\d{2})?\s*(?:am|pm))/i)?.[1] || "";
    const buy = [...document.querySelectorAll("a[href]")].find(anchor => /tixr\.com\/groups\/thecaverns/i.test(anchor.href));
    const image = document.querySelector('meta[property="og:image"]')?.content || document.querySelector("main img, article img, img")?.src || "";
    const status = /cancelled|canceled/i.test(bodyText) ? "cancelled" : /postponed/i.test(bodyText) ? "postponed" : /sold out/i.test(bodyText) ? "sold-out" : "scheduled";
    const title = headings.find(value => !/^shows?$/i.test(value) && !/^location$/i.test(value)) || text("title");
    return { title, dateCandidate, timeCandidate, doors, ticketUrl: buy?.href || "", image, bodyText, status };
  });

  const title = clean(raw.title);
  if (!title) throw new Error("Official event page did not provide a title");
  const start = parseDateTime(raw.dateCandidate, raw.timeCandidate);
  const artist = title.split(/\s+(?:in|at|with|presents)\s+/i)[0]?.trim();

  return {
    provider: "caverns-official",
    external_id: externalId(url),
    url: raw.ticketUrl || url,
    source_url: url,
    title,
    description: clean(raw.bodyText),
    image: raw.image,
    start,
    end: "",
    doors: clean(raw.doors),
    venue: "The Caverns",
    address: "555 Charlie Roberts Rd, Pelham, TN 37366",
    status: raw.status,
    price: parsePrice(raw.bodyText),
    currency: "USD",
    age: /\b(18\+|21\+|all ages)\b/i.exec(raw.bodyText)?.[1] || "",
    artists: artist ? [artist] : [],
  };
}

export async function syncCavernsOfficial() {
  return withPage(async page => {
    const links = (await collectEventLinks(page)).slice(0, config.MAX_EVENTS_PER_SYNC);
    const events = [];
    const failures = [];
    for (const url of links) {
      try {
        events.push(await extractEvent(page, url));
      } catch (error) {
        failures.push({ url, error: error.message });
      }
    }
    return {
      provider: "caverns-official",
      source_url: SHOWS_URL,
      discovered: links.length,
      events,
      failures,
      fetched_at: new Date().toISOString(),
    };
  });
}
