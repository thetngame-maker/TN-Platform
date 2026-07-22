import { z } from "zod";

const envSchema = z.object({
  PORT: z.coerce.number().int().positive().default(3000),
  API_KEY: z.string().min(16, "API_KEY must be at least 16 characters"),
  CACHE_TTL_SECONDS: z.coerce.number().int().nonnegative().default(900),
  ALLOWED_TIXR_GROUPS: z.string().default("thecaverns"),
  BROWSER_TIMEOUT_MS: z.coerce.number().int().positive().default(45000),
  LOG_LEVEL: z.string().default("info"),
});

const parsed = envSchema.safeParse(process.env);

if (!parsed.success) {
  const details = parsed.error.issues
    .map(issue => `${issue.path.join(".") || "environment"}: ${issue.message}`)
    .join("; ");
  throw new Error(`Invalid Platform Core API configuration: ${details}`);
}

const env = parsed.data;

export const config = Object.freeze({
  PORT: env.PORT,
  API_KEY: env.API_KEY,
  CACHE_TTL_SECONDS: env.CACHE_TTL_SECONDS,
  BROWSER_TIMEOUT_MS: env.BROWSER_TIMEOUT_MS,
  LOG_LEVEL: env.LOG_LEVEL,
  allowedGroups: env.ALLOWED_TIXR_GROUPS
    .split(",")
    .map(value => value.trim().toLowerCase())
    .filter(Boolean),
});
