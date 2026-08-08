<?php
/**
 * Plugin Name: TN Game Settings UI
 * Description: Native Explorer account and preference settings for The TN Game.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Settings_UI {
    public static function boot(): void {
        add_action('template_redirect', [self::class, 'save'], 5);
    }

    public static function save(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_user_logged_in()) return;
        $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        if ($path !== 'profile-settings') return;
        if (!isset($_POST['tng_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_profile_nonce'])), 'tng_save_profile')) return;

        $user_id = get_current_user_id();
        $display_name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        if ($display_name !== '') wp_update_user(['ID' => $user_id, 'display_name' => $display_name, 'description' => $description]);

        update_user_meta($user_id, 'tng_home_area', sanitize_text_field(wp_unslash($_POST['home_area'] ?? '')));
        update_user_meta($user_id, 'tng_profile_visibility', in_array($_POST['profile_visibility'] ?? '', ['public','friends','private'], true) ? $_POST['profile_visibility'] : 'public');
        update_user_meta($user_id, 'tng_email_updates', isset($_POST['email_updates']) ? 1 : 0);
        update_user_meta($user_id, 'tng_challenge_notifications', isset($_POST['challenge_notifications']) ? 1 : 0);
        update_user_meta($user_id, 'tng_location_suggestions', isset($_POST['location_suggestions']) ? 1 : 0);
        update_user_meta($user_id, 'tng_tv_games', isset($_POST['tv_games']) ? 1 : 0);

        wp_safe_redirect(add_query_arg('saved', '1', home_url('/profile-settings/')));
        exit;
    }

    public static function render(): string {
        if (!is_user_logged_in()) {
            return '<main class="tng-settings-screen tng-app-shell"><section class="tng-settings-empty"><span>👤</span><h1>Sign in to manage your Explorer profile.</h1><a class="tng-ui-button" href="' . esc_url(wp_login_url(home_url('/profile-settings/'))) . '">Sign in</a></section></main>';
        }

        $user = wp_get_current_user();
        $user_id = (int) $user->ID;
        $avatar = get_avatar_url($user_id, ['size' => 192]);
        $visibility = (string) get_user_meta($user_id, 'tng_profile_visibility', true) ?: 'public';
        $home_area = (string) get_user_meta($user_id, 'tng_home_area', true);
        $enabled = static fn(string $key, int $default = 1): bool => get_user_meta($user_id, $key, true) === '' ? (bool) $default : (bool) get_user_meta($user_id, $key, true);
        $saved = isset($_GET['saved']);
        ob_start(); ?>
        <main class="tng-settings-screen tng-app-shell">
            <section class="tng-settings-hero">
                <div class="tng-settings-avatar" style="background-image:url('<?php echo esc_url($avatar); ?>')"></div>
                <div><span class="tng-eyebrow">Explorer account</span><h1>Profile settings</h1><p>Control how you appear, what you receive, and how The TN Game personalizes your adventures.</p></div>
                <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/profile/')); ?>">View profile</a>
            </section>

            <?php if ($saved): ?><div class="tng-settings-notice">✓ Your Explorer settings were saved.</div><?php endif; ?>

            <form class="tng-settings-form" method="post" action="<?php echo esc_url(home_url('/profile-settings/')); ?>">
                <?php wp_nonce_field('tng_save_profile', 'tng_profile_nonce'); ?>
                <section class="tng-settings-panel">
                    <div class="tng-settings-heading"><span class="tng-eyebrow">Public profile</span><h2>Explorer identity</h2><p>This information appears on leaderboards, friends, challenges, and your profile.</p></div>
                    <div class="tng-settings-fields">
                        <label><span>Display name</span><input type="text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>" maxlength="60" required></label>
                        <label><span>Home area</span><input type="text" name="home_area" value="<?php echo esc_attr($home_area); ?>" placeholder="Tracy City, Tennessee"></label>
                        <label class="is-wide"><span>Explorer bio</span><textarea name="description" rows="4" maxlength="280" placeholder="Tell other explorers what you enjoy discovering."><?php echo esc_textarea($user->description); ?></textarea></label>
                        <label><span>Profile visibility</span><select name="profile_visibility"><option value="public" <?php selected($visibility,'public'); ?>>Public</option><option value="friends" <?php selected($visibility,'friends'); ?>>Friends only</option><option value="private" <?php selected($visibility,'private'); ?>>Private</option></select></label>
                        <div class="tng-settings-avatar-note"><strong>Profile photo</strong><p>Your avatar currently comes from your WordPress/Gravatar account. Native photo uploads will connect here in a later build.</p></div>
                    </div>
                </section>

                <section class="tng-settings-panel">
                    <div class="tng-settings-heading"><span class="tng-eyebrow">Preferences</span><h2>Notifications and play</h2><p>Choose how the platform helps you plan, compete, and keep exploring.</p></div>
                    <div class="tng-settings-toggles">
                        <?php
                        $toggles = [
                            ['email_updates','Trip and event updates','Receive useful reminders about saved trips and upcoming events.'],
                            ['challenge_notifications','Challenge notifications','Hear when another Explorer challenges you or your group.'],
                            ['location_suggestions','Nearby suggestions','Use your location to surface nearby trails, games, food, and Top Sights.'],
                            ['tv_games','TV and Roku games','Show connected TV game options and account pairing tools.'],
                        ];
                        foreach ($toggles as [$key,$title,$copy]): ?>
                            <label class="tng-settings-toggle"><span><strong><?php echo esc_html($title); ?></strong><small><?php echo esc_html($copy); ?></small></span><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($enabled('tng_'.$key)); ?>><i></i></label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="tng-settings-panel tng-settings-security">
                    <div><span class="tng-eyebrow">Account security</span><h2>Email and password</h2><p><?php echo esc_html($user->user_email); ?></p></div>
                    <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(wp_lostpassword_url(home_url('/profile-settings/'))); ?>">Change password</a>
                </section>

                <div class="tng-settings-save"><a href="<?php echo esc_url(home_url('/profile/')); ?>">Cancel</a><button class="tng-ui-button" type="submit">Save settings</button></div>
            </form>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Settings_UI::boot();
