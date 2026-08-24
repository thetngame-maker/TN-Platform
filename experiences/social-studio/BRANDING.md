# TN Social Studio branding

Goal: make BrightBean feel like a native TN Game operations product without creating a hard-to-update fork.

## Product name

- Product: **TN Social Studio**
- Parent: **The TN Game**
- Production URL: `studio.thetngame.com`

## Visual direction

Use the same restrained palette as the TN Game Explorer/Trips work:

```css
:root {
  --tn-green-950: #0b2f20;
  --tn-green-900: #124b31;
  --tn-green-800: #1d5e40;
  --tn-cream-50: #f7f6f1;
  --tn-surface: #ffffff;
  --tn-border: #dde4dc;
  --tn-text: #142018;
  --tn-muted: #758079;
  --tn-orange: #f06423;
  --tn-orange-soft: #fff1e9;
  --tn-success: #24764a;
}
```

Orange is an accent/action color only. Avoid orange page backgrounds or large orange panels.

## Layout

Preserve BrightBean's information architecture initially. Restyle rather than restructure the calendar/composer until the upstream app is running and tested.

Primary navigation target:

1. Dashboard
2. Calendar
3. Create
4. Ideas
5. Approvals
6. Media
7. Inbox
8. Analytics
9. Accounts
10. Settings

TN-only navigation will be added later:

- Discovery
- Permissions
- Creators
- Campaigns

## Calendar

The BrightBean calendar is the primary scheduling surface. TN customizations should add useful metadata without replacing the scheduler:

- platform icons
- approval state
- TN campaign/category
- source type (`original`, `creator-approved`, `TN Game place`, `event`, `trail`)
- creator credit indicator
- permission status where relevant

## Composer

Add TN-specific fields in a separate extension layer:

- source / inspiration reference
- creator record
- permission record
- required credit text
- TN Game place/trail/event relationship
- campaign
- reusable hashtag group

Publishing must be blocked for creator-derived media until its permission record is approved.

## Branding implementation order

1. Use BrightBean workspace white-label settings for logo/color where possible.
2. Add a small TN theme override for global color tokens and product naming.
3. Override individual templates only when required.
4. Keep TN-only screens in separate templates/apps.
5. Never scatter one-off edits across upstream templates when a global token/component override can solve the same problem.

## Product principle

TN Social Studio should look like a specialized TN Game operations app, not a reskinned generic dashboard. The distinction will come mainly from TN workflows (Discovery → Permission → Compose → Schedule → Publish → Analyze), not from replacing BrightBean's proven core UI all at once.
