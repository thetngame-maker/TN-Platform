import crypto from "node:crypto";
import { chromium } from "playwright";
import { config } from "../config.js";

const EVENT_URL_RE = /https?:\\?\/\\?\/(?:www\\.)?tixr\\?\.com\\?\/groups\\?\/([^/"'\\s?#<]+)\\?\/events\\?\/([^/"'\\s?#<]+-\d+)/gi;
const PREVIEW_LIMIT = 12000;
const RESPONSE_PREVIEW_LIMIT = 4000;

function now() { return new Date().toISOString(); }
function cleanUrl(value) { return String(value || "").replaceAll("\\/", "/").replace(/\/$/, ""); }
function pushStep(timeline, stage, status, detail = "") { timeline.push({ at: now(), stage, status, detail }); }
function truncate(value, limit) { const text = String(value || ""); return text.length > limit ? `${text.slice(0, limit)}\n… [truncated ${text.length - limit} characters]` : text; }
function contentKind(contentType = "", resourceType = "") {
  const type = String(contentType).toLowerCase();
  if (type.includes("json")) return "json";
  if (resourceType === "xhr" || resourceType === "fetch") return resourceType;
  return resourceType || "other";
}
function findUrls(value, group, target) {
  const text = typeof value === "string" ? value : JSON.stringify(value);
  if (!text) return;
  EVENT_URL_RE.lastIndex = 0;
  for (const match of text.matchAll(EVENT_URL_RE)) {
    if (match[1].toLowerCase() !== group.toLowerCase()) continue;
    target.add(cleanUrl(match[0]));
  }
}
function challengeAnalysis({ title = "", html = "", status = 0 }) {
  const haystack = `${title}\n${html.slice(0, 20000)}`.toLowerCase();
  const signals = [];
  const patterns = [
    ["cloudflare", /cloudflare|cf-chl|challenge-platform/],
    ["bot challenge", /just a moment|checking your browser|verify you are human|security check/],
    ["access denied", /access denied|request blocked|forbidden/],
    ["captcha", /captcha|turnstile|hcaptcha|recaptcha/],
  ];
  for (const [label, pattern] of patterns) if (pattern.test(haystack)) signals.push(label);
  if ([401, 403, 429].includes(Number(status))) signals.push(`HTTP ${status}`);
  return {
    detected: signals.length > 0,
    signals: [...new Set(signals)],
    classification: signals.length ? "challenge_or_block_page" : "no_known_challenge_detected",
    recommendation: signals.length
      ? "The provider returned a challenge or restricted page. Review the screenshot, HTML preview, redirects, and response headers before changing parsers."
      : "No common challenge signature was detected. Inspect JavaScript errors, failed requests, and page rendering timing.",
  };
}

export async function discoverTixr(page, groupUrl, group) {
  const runId = crypto.randomUUID();
  const startedAt = now();
  const timeline = [];
  const eventUrls = new Set();
  const network = [];
  const jsonEndpoints = [];
  const graphqlEndpoints = [];
  const responseTasks = [];
  const consoleMessages = [];
  const pageErrors = [];
  const requestFailures = [];
  const redirects = [];
  const screenshots = [];
  let mainResponse = null;

  page.on("console", message => {
    consoleMessages.push({ at: now(), type: message.type(), text: truncate(message.text(), 2000), location: message.location() });
  });
  page.on("pageerror", error => pageErrors.push({ at: now(), message: truncate(error.message, 3000), stack: truncate(error.stack, 5000) }));
  page.on("requestfailed", request => requestFailures.push({ at: now(), method: request.method(), resource_type: request.resourceType(), url: request.url(), error: request.failure()?.errorText || "Request failed" }));
  page.on("request", request => {
    const from = request.redirectedFrom();
    if (from) redirects.push({ from: from.url(), to: request.url(), method: request.method() });
  });

  pushStep(timeline, "browser", "running", "Chromium page ready");

  page.on("response", response => {
    const task = (async () => {
      const request = response.request();
      const resourceType = request.resourceType();
      const headers = response.headers();
      const contentType = headers["content-type"] || "";
      const kind = contentKind(contentType, resourceType);
      const url = response.url();
      const item = {
        at: now(), method: request.method(), url, status: response.status(), status_text: response.statusText(),
        type: kind, resource_type: resourceType, content_type: contentType, matched_events: 0,
        server: headers.server || "", cache_status: headers["cf-cache-status"] || headers["x-cache"] || "",
      };
      try {
        if (["xhr", "fetch", "document"].includes(resourceType) || kind === "json") {
          const body = await response.text();
          const before = eventUrls.size;
          findUrls(body, group, eventUrls);
          item.matched_events = eventUrls.size - before;
          item.bytes = Buffer.byteLength(body);
          if (kind === "json" || resourceType === "xhr" || resourceType === "fetch") item.preview = truncate(body, RESPONSE_PREVIEW_LIMIT);
          if (kind === "json") jsonEndpoints.push({ url, status: response.status(), bytes: item.bytes, matched_events: item.matched_events, preview: item.preview || "" });
          if (/graphql/i.test(url) || /graphql/i.test(body.slice(0, 1000))) graphqlEndpoints.push({ url, status: response.status(), bytes: item.bytes, matched_events: item.matched_events, preview: item.preview || "" });
        }
      } catch (error) {
        item.read_error = error.message;
      }
      network.push(item);
    })();
    responseTasks.push(task);
  });

  pushStep(timeline, "navigation", "running", groupUrl);
  mainResponse = await page.goto(groupUrl, { waitUntil: "domcontentloaded" });
  pushStep(timeline, "navigation", "complete", `DOM loaded${mainResponse ? ` · HTTP ${mainResponse.status()}` : ""}`);

  const initialShot = await page.screenshot({ type: "jpeg", quality: 55, fullPage: false }).catch(() => null);
  if (initialShot) screenshots.push({ stage: "initial", label: "After DOM load", captured_at: now(), mime_type: "image/jpeg", base64: initialShot.toString("base64") });
  await page.waitForTimeout(2500);

  const cookieResult = await page.evaluate(() => {
    const labels = ["accept all", "accept cookies", "allow all", "agree", "got it"];
    const buttons = [...document.querySelectorAll('button,[role="button"]')];
    const button = buttons.find(el => labels.includes((el.textContent || "").trim().toLowerCase()));
    if (!button) return { found: false, clicked: false };
    button.click(); return { found: true, clicked: true, label: (button.textContent || "").trim() };
  }).catch(() => ({ found: false, clicked: false }));
  pushStep(timeline, "cookies", "complete", cookieResult.clicked ? `Clicked ${cookieResult.label}` : "No banner action needed");

  let previousHeight = 0;
  let stable = 0;
  let scrolls = 0;
  for (let i = 0; i < 12 && stable < 2; i++) {
    const height = await page.evaluate(() => document.documentElement.scrollHeight);
    if (height === previousHeight) stable++; else stable = 0;
    previousHeight = height;
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.waitForTimeout(500);
    scrolls++;
  }
  pushStep(timeline, "scroll", "complete", `${scrolls} passes`);

  const dom = await page.evaluate(() => {
    const hrefs = [...document.querySelectorAll('a[href*="/events/"]')].map(a => a.href).filter(Boolean);
    const jsonLd = [...document.querySelectorAll('script[type="application/ld+json"]')].map(s => s.textContent || "");
    const scripts = [...document.scripts].map(s => s.textContent || "").filter(Boolean);
    const metas = {};
    for (const meta of document.querySelectorAll("meta[name],meta[property]")) {
      const key = meta.getAttribute("name") || meta.getAttribute("property");
      if (key && meta.content) metas[key] = meta.content;
    }
    return {
      hrefs, jsonLd, scripts: scripts.filter(s => /\/events\//i.test(s)).slice(0, 50),
      title: document.title, html_bytes: new Blob([document.documentElement.outerHTML]).size,
      body_text: (document.body?.innerText || "").slice(0, 6000),
      metas, ready_state: document.readyState, scroll_height: document.documentElement.scrollHeight,
    };
  });
  for (const href of dom.hrefs) findUrls(href, group, eventUrls);
  for (const block of dom.jsonLd) findUrls(block, group, eventUrls);
  for (const script of dom.scripts) findUrls(script, group, eventUrls);
  const html = await page.content();
  findUrls(html, group, eventUrls);
  await Promise.allSettled(responseTasks);

  const finalShot = await page.screenshot({ type: "jpeg", quality: 58, fullPage: false }).catch(() => null);
  if (finalShot) screenshots.push({ stage: "final", label: "After cookies and scrolling", captured_at: now(), mime_type: "image/jpeg", base64: finalShot.toString("base64") });
  pushStep(timeline, "capture", "complete", `${dom.html_bytes} HTML bytes; ${screenshots.length} screenshot${screenshots.length === 1 ? "" : "s"} captured`);

  const browser = await page.context().browser();
  const runtime = await page.evaluate(() => ({
    user_agent: navigator.userAgent,
    platform: navigator.platform,
    language: navigator.language,
    languages: navigator.languages,
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    viewport: { width: window.innerWidth, height: window.innerHeight, device_pixel_ratio: window.devicePixelRatio },
  }));
  const mainHeaders = mainResponse ? mainResponse.headers() : {};
  const pageInfo = {
    requested_url: groupUrl,
    final_url: page.url(),
    title: dom.title,
    status: mainResponse?.status() || 0,
    status_text: mainResponse?.statusText() || "",
    content_type: mainHeaders["content-type"] || "",
    server: mainHeaders.server || "",
    html_bytes: dom.html_bytes,
    ready_state: dom.ready_state,
    scroll_height: dom.scroll_height,
    meta: dom.metas,
    html_preview: truncate(html, PREVIEW_LIMIT),
    body_text_preview: truncate(dom.body_text, 6000),
    response_headers: mainHeaders,
  };
  const challenge = challengeAnalysis({ title: dom.title, html, status: pageInfo.status });
  pushStep(timeline, "analysis", challenge.detected ? "warning" : "complete", challenge.detected ? `Possible block detected: ${challenge.signals.join(", ")}` : "No common challenge signature detected");

  const urls = [...eventUrls].slice(0, config.MAX_EVENTS_PER_SYNC);
  const sources = {
    network: network.reduce((sum, item) => sum + (item.matched_events || 0), 0),
    html_links: dom.hrefs.length,
    json_ld_blocks: dom.jsonLd.length,
    embedded_scripts: dom.scripts.length,
  };
  pushStep(timeline, "discovery", urls.length ? "complete" : "warning", `${urls.length} event URLs found`);

  return {
    run_id: runId,
    provider: "tixr",
    source_url: groupUrl,
    group,
    started_at: startedAt,
    finished_at: now(),
    timeline,
    summary: {
      event_urls: urls.length,
      network_requests: network.length,
      json_endpoints: jsonEndpoints.length,
      graphql_endpoints: graphqlEndpoints.length,
      json_ld_blocks: dom.jsonLd.length,
      html_links: dom.hrefs.length,
      scrolls,
      console_messages: consoleMessages.length,
      page_errors: pageErrors.length,
      failed_requests: requestFailures.length,
      redirects: redirects.length,
    },
    browser: {
      engine: "chromium",
      version: browser?.version() || "",
      playwright_version: process.env.npm_package_dependencies_playwright || "1.61.1",
      ...runtime,
    },
    page: pageInfo,
    challenge,
    redirects,
    screenshots,
    console: consoleMessages.slice(0, 250),
    page_errors: pageErrors.slice(0, 100),
    request_failures: requestFailures.slice(0, 250),
    sources,
    event_urls: urls,
    network: network.slice(0, 350),
    json_endpoints: jsonEndpoints.slice(0, 100),
    graphql_endpoints: graphqlEndpoints.slice(0, 50),
  };
}
