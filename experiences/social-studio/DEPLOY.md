# TN Social Studio deployment runbook

Target: `https://studio.thetngame.com`

TN Social Studio runs BrightBean Studio as an independent Django service. WordPress/TN Game OS links to it; it is not loaded inside the WordPress PHP process.

## 1. Infrastructure

Use a host that supports long-running Docker services (or BrightBean's supported Render/Railway deployment). Production needs:

- Django web service
- BrightBean background worker
- PostgreSQL
- persistent S3-compatible object storage for media
- HTTPS on `studio.thetngame.com`

The current TN Platform WordPress server does not need to be changed to run the social scheduler.

## 2. DNS

Create a DNS record for `studio.thetngame.com` pointing to the selected Social Studio host. Keep DNS/SSL changes separate from the WordPress application deployment.

## 3. Bootstrap the pinned upstream

From the deployment machine:

```bash
chmod +x experiences/social-studio/scripts/bootstrap-brightbean.sh
./experiences/social-studio/scripts/bootstrap-brightbean.sh /opt/tn-social-studio
cd /opt/tn-social-studio
```

The script checks out the exact upstream revision recorded in `UPSTREAM.lock` rather than silently taking future upstream changes.

## 4. Environment

Use `experiences/social-studio/.env.tn.example` as the TN production checklist and BrightBean's own `.env.example` as the authoritative variable reference for the pinned revision.

Minimum production settings include:

- strong `SECRET_KEY`
- strong `ENCRYPTION_KEY_SALT`
- PostgreSQL `DATABASE_URL`
- `ALLOWED_HOSTS=studio.thetngame.com`
- `APP_URL=https://studio.thetngame.com`
- S3-compatible persistent media configuration

Never commit credentials.

## 5. First boot

For Docker-based hosting:

```bash
docker compose up -d --build
docker compose exec app python manage.py createsuperuser
docker compose ps
```

BrightBean's migration service runs migrations before the app/worker become available.

## 6. Initial TN workspace

After login:

1. Create the primary organization/workspace as **The TN Game**.
2. Set workspace branding to TN Game colors/logo where BrightBean's white-label settings allow it.
3. Confirm Calendar, Composer, Media Library, Approvals, Inbox and Analytics load before connecting social accounts.
4. Create one non-production test post/draft before enabling scheduled publishing.

## 7. Platform connection order

Connect accounts incrementally instead of adding every network at once:

1. Facebook Page
2. Instagram professional account
3. Threads
4. Google Business Profile
5. YouTube
6. TikTok / Pinterest / LinkedIn as needed

For each platform, use the official developer credentials and minimum permissions required by the BrightBean integration.

## 8. TN Game integration

WordPress should expose **Social Studio** from Content Studio as an authenticated launcher/link to `studio.thetngame.com`. Do not share database credentials between WordPress and BrightBean.

Later integration should use explicit APIs for objects such as:

- content ideas
- Local Discovery places
- trails / Top Sights
- events
- approved creator assets
- attribution metadata

## 9. Branding approach

Prefer BrightBean's built-in white-label/workspace branding first. Keep deeper TN visual changes in a small override/patch layer rather than editing unrelated upstream files. This minimizes conflicts when BrightBean is updated.

TN palette currently used across the web app:

- deep green: `#124B31` / closely related existing TN green
- warm cream/off-white page surface
- orange accent for actions, not large page backgrounds

## 10. AGPL compliance

BrightBean Studio is AGPL-3.0. Preserve upstream notices and make the corresponding source for the network-accessible modified BrightBean service available as required by the license.

Keep TN components that do not need to derive from BrightBean behind explicit service/API boundaries where practical.

## Acceptance checklist

The v0.1 deployment is ready when:

- `studio.thetngame.com` resolves over HTTPS
- admin login works
- The TN Game workspace exists
- TN branding is visible
- Calendar loads
- Composer loads
- Media Library loads and uploads persist after a redeploy/restart
- worker is running
- a scheduled test job survives restart
- no production platform credentials are committed to Git
