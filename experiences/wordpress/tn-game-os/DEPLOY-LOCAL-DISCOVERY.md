# Deploy Local Discovery from a known commit

This repository module replaces the production MU-plugin prototype.

Recommended production procedure:

1. Back up the active TN Game OS plugin and MU prototype.
2. Fetch the repository.
3. Deploy a specific tested commit from this branch/merged PR.
4. Run PHP syntax checks.
5. Disable the old MU prototype to avoid duplicate hooks.
6. Flush WordPress caches.
7. Verify Content Studio → Local Discovery and create one test draft.

Do not edit the production Local Discovery PHP file piecemeal after migration; make changes in GitHub and deploy known commits.
