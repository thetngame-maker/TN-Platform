# Local Discovery migration from MU plugin

The original production prototype lives at:

`wp-content/mu-plugins/tn-game-google-maps-discovery.php`

After deploying TN Game OS with the Local Discovery module enabled, rename the MU plugin so WordPress no longer loads duplicate hooks:

```bash
mv wp-content/mu-plugins/tn-game-google-maps-discovery.php \
   wp-content/mu-plugins/tn-game-google-maps-discovery.php.disabled
```

The module intentionally reuses the existing options and candidate post type/meta keys so existing discovery queue records and Apify configuration remain available after migration.
