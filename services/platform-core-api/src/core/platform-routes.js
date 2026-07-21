import { Router } from 'express';
import { requireApiKey } from '../security.js';
import { config } from '../config.js';
import { configurationSnapshot, listServices, listLogs, metrics, platformHealth } from './platform-core.js';

export const platformRouter = Router();
platformRouter.use(requireApiKey);
platformRouter.get('/config', (_req, res) => res.json({ ok: true, data: configurationSnapshot(config) }));
platformRouter.get('/services', (_req, res) => res.json({ ok: true, data: { items: listServices(), total: listServices().length } }));
platformRouter.get('/health', (_req, res) => res.json({ ok: true, data: platformHealth() }));
platformRouter.get('/logs', (req, res) => res.json({ ok: true, data: { items: listLogs(req.query) } }));
platformRouter.get('/metrics', (_req, res) => res.json({ ok: true, data: metrics() }));
