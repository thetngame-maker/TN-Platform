# TN Game OS 5.106.0

## AI Admin / Content Manager v1

- Adds a natural-language administration workspace under TN Game OS.
- Converts requests into a review plan grounded in current TN Game content records.
- Finds drafts, missing featured images, missing excerpts, and Traveler/demo wording.
- Creates original Content Studio briefs as drafts only.
- Requires separate human approval for every proposed content change.
- Prevents automatic publishing, permanent deletion, and batch execution.
- Logs approved changes and provides a one-click undo path.
- Adds an optional OpenAI Responses API connection using strict structured outputs.
- Sends only a limited inventory summary to the model and excludes credentials and private user data.
- Falls back to an on-site natural-language planner when no API key is configured or the model is unavailable.
- Adds responsive administration styling for narrow screens.
