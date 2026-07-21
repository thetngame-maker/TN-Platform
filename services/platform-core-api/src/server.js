import express from "express";
import helmet from "helmet";
import pino from "pino";
import pinoHttp from "pino-http";
import { config } from "./config.js";
import { browserHealth } from "./core/browser-health.js";
import { getCached, setCached } from "./cache.js";
import { requireApiKey, validateTixrGroupUrl } from "./security.js";
import { syncTixrGroup, runTixrDiscovery } from "./providers/tixr.js";
import { withPage } from "./browser.js";
import { knowledgeRouter } from "./core/knowledge-routes.js";
import { stats as knowledgeStats } from "./core/knowledge-store.js";
import { platformRouter } from "./core/platform-routes.js";
import { requestContext, registerService, log, increment, observeGauge } from "./core/platform-core.js";

const logger = pino({ level: process.env.LOG_LEVEL || "info" });
const app = express();

app.use(helmet());
app.use(express.json({ limit: "100kb" }));
app.use(requestContext);
app.use(pinoHttp({ logger }));
app.use((req, res, next) => {
  log({ service: "platform-api", event: "HttpRequestStarted", message: `${req.method} ${req.path}`, correlation_id: req.correlationId });
  res.on("finish", () => log({ service: "platform-api", level: res.statusCode >= 500 ? "error" : res.statusCode >= 400 ? "warning" : "info", event: "HttpRequestCompleted", message: `${req.method} ${req.path} ${res.statusCode}`, correlation_id: req.correlationId, metadata: { status: res.statusCode } }));
  next();
});

app.get("/health", async (_req, res) => {
  const browser = await browserHealth();
  const providers = { tixr: { ok: true, adapter_version: "3.1.0", capabilities: ["discover","fetch","normalize"] } };
  const ok = browser.ok;
  res.status(ok ? 200 : 503).json({ ok, service: "tn-game-concert-intelligence", version: "3.1.0", browser, providers, knowledge: knowledgeStats(), timestamp: new Date().toISOString() });
});

app.use("/v1/platform", platformRouter);
app.use("/v1/knowledge", knowledgeRouter);

app.post("/v1/discovery/run", requireApiKey, async (req, res) => {
  const source = validateTixrGroupUrl(req.body?.source_url);
  if (!source) return res.status(400).json({ ok: false, error: "Invalid or disallowed Tixr group URL" });
  try {
    const data = await withPage(page => runTixrDiscovery(page, source.url, source.group));
    res.json({ ok: true, data });
  } catch (error) {
    req.log.error({ err: error }, "Discovery run failed");
    res.status(502).json({ ok: false, error: "Discovery run failed", detail: error.message });
  }
});

app.post("/v1/providers/tixr/sync", requireApiKey, async (req, res) => {
  const source = validateTixrGroupUrl(req.body?.source_url);
  if (!source) {
    return res.status(400).json({
      ok: false,
      error: "Invalid or disallowed Tixr group URL",
    });
  }

  const cacheKey = `tixr:${source.group}`;
  const cached = getCached(cacheKey);
  if (cached && req.body?.force !== true) {
    return res.json({ ok: true, cached: true, data: cached });
  }

  try {
    const data = await syncTixrGroup(source.url, source.group);
    setCached(cacheKey, data, config.CACHE_TTL_SECONDS);
    res.json({ ok: true, cached: false, data });
  } catch (error) {
    req.log.error({ err: error }, "Tixr sync failed");
    res.status(502).json({
      ok: false,
      error: "Provider sync failed",
      detail: error.message,
    });
  }
});

app.use((error, req, res, _next) => {
  req.log.error({ err: error }, "Unhandled error");
  res.status(500).json({ ok: false, error: "Internal server error" });
});

registerService({ id: "platform-core", name: "Platform Core", version: "3.1.0", description: "Configuration, registry, health, logging, metrics, and correlation infrastructure.", capabilities: ["configuration","service-registry","health","structured-logging","metrics"], endpoints: ["/health","/v1/platform/config","/v1/platform/services","/v1/platform/health","/v1/platform/logs","/v1/platform/metrics"] });
registerService({ id: "knowledge-service", name: "Knowledge Service", version: "2.0.0", description: "Canonical entities, versions, provenance, and relationship registry.", capabilities: ["entities","relationships","versions","graph"], dependencies: ["platform-core"], endpoints: ["/v1/knowledge"] });
registerService({ id: "discovery-service", name: "Discovery Service", version: "2.0.0", description: "Provider acquisition, browser diagnostics, normalization, and discovery records.", capabilities: ["providers","browser-intelligence","normalization"], dependencies: ["platform-core"], endpoints: ["/v1/discovery/run","/v1/providers/tixr/sync"] });
observeGauge("registered_services", 3);
increment("platform_boot_total");

app.listen(config.PORT, () => {
  logger.info({ port: config.PORT }, "TN Platform Core API started");
  log({ service: "platform-core", event: "PlatformStarted", message: `TN Platform Core API listening on ${config.PORT}` });
});
