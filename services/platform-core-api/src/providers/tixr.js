import { withPage } from "../browser.js";
import { config } from "../config.js";
import { discoverTixr } from "../core/discovery-engine.js";

const EVENT_RE = /^https:\/\/www\.tixr\.com\/groups\/([^/]+)\/events\/([^?#]+-\d+)\/?$/i;

function cleanText(value) {
  return String(value || "").replace(/\s+/g, " ").trim();
}

function first(value) {
  if (Array.isArray(value)) return value[0] || "";
  if (value && typeof value === "object") return value.url || "";
  return value || "";
}

function idFromUrl(url) {
  const match = String(url).match(/-(\d+)(?:\/)?$/);
  return match ? match[1] : Buffer.from(url).toString("base64url").slice(-24);
}

function normalizeJsonLd(object, url) {
  const type = Array.isArray(object?.["@type"]) ? object["@type"].join(" ") : object?.["@type"];
  if (!String(type || "").toLowerCase().includes("event")) return null;

  const location = object.location || {};
  const addressObj = location.address || {};
  const address = typeof addressObj === "string"
    ? addressObj
    : [addressObj.streetAddress, addressObj.addressLocality, addressObj.addressRegion, addressObj.postalCode]
        .filter(Boolean).join(", ");

  let offer = object.offers || {};
  if (Array.isArray(offer)) offer = offer[0] || {};

  const eventStatus = String(object.eventStatus || "");
  return {
    provider: "tixr",
    external_id: idFromUrl(url),
    url,
    title: cleanText(object.name),
    description: String(object.description || ""),
    image: first(object.image),
    start: object.startDate || "",
    end: object.endDate || "",
    doors: "",
    venue: cleanText(location.name),
    address: cleanText(address),
    status: /cancel/i.test(eventStatus) ? "cancelled" : /postpon/i.test(eventStatus) ? "postponed" : "scheduled",
    price: cleanText(offer.lowPrice ?? offer.price ?? ""),
    currency: cleanText(offer.priceCurrency || ""),
    age: "",
    artists: [],
  };
}

async function extractEmbeddedEvent(page, url) {
  return page.evaluate(({ url }) => {
    const clean = v => String(v || "").replace(/\s+/g, " ").trim();
    const first = value => Array.isArray(value) ? (value[0] || "") : (value && typeof value === "object" ? value.url || "" : value || "");
    const idMatch = url.match(/-(\d+)(?:\/)?$/);

    const scripts = [...document.querySelectorAll('script[type="application/ld+json"]')];
    const candidates = [];
    for (const script of scripts) {
      try {
        const parsed = JSON.parse(script.textContent || "{}");
        if (Array.isArray(parsed)) candidates.push(...parsed);
        else if (Array.isArray(parsed?.["@graph"])) candidates.push(...parsed["@graph"]);
        else candidates.push(parsed);
      } catch {}
    }

    for (const obj of candidates) {
      const type = Array.isArray(obj?.["@type"]) ? obj["@type"].join(" ") : obj?.["@type"];
      if (!String(type || "").toLowerCase().includes("event")) continue;
      const location = obj.location || {};
      const addressObj = location.address || {};
      const address = typeof addressObj === "string"
        ? addressObj
        : [addressObj.streetAddress,addressObj.addressLocality,addressObj.addressRegion,addressObj.postalCode].filter(Boolean).join(", ");
      let offer = obj.offers || {};
      if (Array.isArray(offer)) offer = offer[0] || {};
      return {
        provider: "tixr",
        external_id: idMatch ? idMatch[1] : "",
        url,
        title: clean(obj.name),
        description: String(obj.description || ""),
        image: first(obj.image),
        start: obj.startDate || "",
        end: obj.endDate || "",
        doors: "",
        venue: clean(location.name),
        address: clean(address),
        status: /cancel/i.test(String(obj.eventStatus || "")) ? "cancelled" : "scheduled",
        price: clean(offer.lowPrice ?? offer.price ?? ""),
        currency: clean(offer.priceCurrency || ""),
        age: "",
        artists: [],
      };
    }

    const meta = name => document.querySelector(`meta[property="${name}"],meta[name="${name}"]`)?.content || "";
    const bodyText = document.body?.innerText || "";
    const doors = bodyText.match(/Doors?\s*(?:open)?\s*(?:at)?\s*([0-9]{1,2}(?::[0-9]{2})?\s*[AP]M)/i)?.[1] || "";
    const age = bodyText.match(/\b(All Ages|[0-9]{1,2}\+)\b/i)?.[1] || "";

    return {
      provider: "tixr",
      external_id: idMatch ? idMatch[1] : "",
      url,
      title: clean(meta("og:title") || document.title),
      description: meta("og:description") || meta("description"),
      image: meta("og:image") || meta("twitter:image"),
      start: "",
      end: "",
      doors,
      venue: "",
      address: "",
      status: /cancelled/i.test(bodyText) ? "cancelled" : /sold out/i.test(bodyText) ? "sold-out" : "scheduled",
      price: "",
      currency: "",
      age,
      artists: [],
    };
  }, { url });
}

export async function runTixrDiscovery(page, groupUrl, group) {
  return discoverTixr(page, groupUrl, group);
}

export async function syncTixrGroup(groupUrl, group) {
  return withPage(async page => {
    const discovery = await runTixrDiscovery(page, groupUrl, group);
    const links = discovery.event_urls;
    const events = [];
    const failures = [];

    for (const url of links) {
      try {
        await page.goto(url, { waitUntil: "domcontentloaded" });
        await page.waitForTimeout(1200);
        const event = await extractEmbeddedEvent(page, url);
        if (!event?.title) throw new Error("No event title discovered");
        if (!event.artists?.length) {
          const artist = event.title.split(/\s+(?:at|in|with|w\/|feat\.?)\s+/i)[0]?.trim();
          if (artist && artist.length < 120) event.artists = [artist];
        }
        events.push(event);
      } catch (error) {
        failures.push({ url, error: error.message });
      }
    }

    return {
      provider: "tixr",
      source_url: groupUrl,
      discovered: links.length,
      events,
      failures,
      fetched_at: new Date().toISOString(),
      discovery: { run_id: discovery.run_id, timeline: discovery.timeline, summary: discovery.summary, sources: discovery.sources, network: discovery.network, json_endpoints: discovery.json_endpoints, graphql_endpoints: discovery.graphql_endpoints },
    };
  });
}
