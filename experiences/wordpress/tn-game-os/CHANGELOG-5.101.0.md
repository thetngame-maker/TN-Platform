# TN Game OS 5.101.0 — Adventure AI v1

## Added

- Native `/adventure-ai/` app route with the shared TN Game shell and Trips navigation state.
- Natural-language itinerary generation using published TN Game places, destination AI profiles, and recommendation relationships.
- Prompt interpretation for duration, start time, pace, family needs, accessibility, weather, budget, food, and interests.
- Mobile-first itinerary timeline with example prompts, interpreted preference tags, visit times, planning buffers, place links, and sharing.
- One-tap itinerary saving that merges generated stops into the signed-in Explorer's Trips without removing existing stops.
- Last-generated Adventure AI plan metadata for future Adventure Recaps integration.

## Changed

- Trips now promotes Adventure AI as the primary smart-planning milestone.
- TN Game OS version advanced to 5.101.0.

## Safety

- Generation uses published TN Game objects only and requires no external AI API key.
- Generated itineraries include a reminder to confirm hours, tickets, trail conditions, and driving time.
- Saving is nonce-protected, requires sign-in, validates every post, and preserves the existing trip.
