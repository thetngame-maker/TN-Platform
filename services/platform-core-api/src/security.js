import crypto from "node:crypto";
import { config } from "./config.js";

function safeEqual(a, b) {
  const left = Buffer.from(String(a || ""));
  const right = Buffer.from(String(b || ""));
  if (left.length !== right.length) return false;
  return crypto.timingSafeEqual(left, right);
}

export function requireApiKey(req, res, next) {
  const supplied = req.get("x-api-key") || "";
  if (!safeEqual(supplied, config.API_KEY)) {
    return res.status(401).json({ ok: false, error: "Unauthorized" });
  }
  next();
}

export function validateTixrGroupUrl(value) {
  let url;
  try { url = new URL(value); } catch { return null; }
  if (!["tixr.com", "www.tixr.com"].includes(url.hostname.toLowerCase())) return null;
  const match = url.pathname.match(/^\/groups\/([^/]+)\/?$/i);
  if (!match) return null;
  const group = match[1].toLowerCase();
  if (!config.allowedGroups.includes("*") && !config.allowedGroups.includes(group)) return null;
  return { url: `https://www.tixr.com/groups/${group}`, group };
}
