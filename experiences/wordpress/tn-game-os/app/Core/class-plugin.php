<?php
namespace TNG_OS\Core;
if (!defined('ABSPATH')) exit;

final class Plugin {
    private static ?self $instance = null;
    private Container $container;
    private array $modules = [];
    private bool $booted = false;

    public static function instance(): self { return self::$instance ??= new self(); }

    private function __construct() {
        $this->container = new Container();
        $this->container->set('version', TNG_OS_VERSION);
        $this->container->set('path', TNG_OS_PATH);
        $this->container->set('url', TNG_OS_URL);
    }

    public static function activate(): void {
        update_option('tng_os_version', TNG_OS_VERSION, false);
        update_option('tng_os_rewrite_flush_needed', 1, false);
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('tng_os_hourly');
        wp_clear_scheduled_hook('tng_os_daily');
    }

    public function boot(): void {
        if ($this->booted) return;
        $this->booted = true;

        foreach ([
            'app/Modules/Services/class-service-registry.php',
            'app/Modules/Settings/class-settings.php',
            'app/Modules/Assets/class-assets.php',
            'app/Modules/Developer/class-developer-manager.php',
            'app/Modules/Destinations/class-destinations.php',
            'app/Modules/Destinations/class-destination-platform.php',
            'app/Modules/Destinations/class-destination-editor.php',
            'app/Modules/Destinations/class-destination-relationships.php',
            'app/Modules/Entities/class-entity-bridge.php',
            'app/Modules/Entities/class-platform-health.php',
            'app/Modules/Entities/class-graph-explorer.php',
            'app/Modules/Entities/class-relationship-manager.php',
            'app/Modules/Entities/class-recommendation-engine.php',
            'app/Modules/Entities/class-recommendation-studio.php',
            'app/Modules/Sources/class-provider-interface.php',
            'app/Modules/Sources/class-provider-registry.php',
            'app/Modules/Sources/Providers/class-google-places-provider.php',
            'app/Modules/Sources/class-content-sources.php',
            'app/Modules/Frontend/class-food-service.php',
            'app/Modules/Frontend/class-recommendations.php',
            'app/Modules/Frontend/class-smart-recommendations.php',
            'app/Modules/Frontend/class-discovery-search.php',
            'app/Modules/Frontend/class-world-engine.php',
            'app/Modules/Frontend/class-quest-runtime.php',
            'app/Modules/Frontend/class-quest-finale.php',
            'app/Modules/Frontend/class-quest-memory.php',
            'app/Modules/Frontend/class-explorer-identity.php',
            'app/Modules/Frontend/class-explorer-journal.php',
            'app/Modules/Frontend/class-daily-missions.php',
            'app/Modules/Frontend/class-adventure-rewards.php',
            'app/Modules/Frontend/class-weekly-expedition.php',
            'app/Modules/Frontend/class-explorer-leaderboard.php',
            'app/Modules/Frontend/class-community-expedition.php',
            'app/Modules/Frontend/class-explorer-challenges.php',
            'app/Modules/Frontend/class-explorer-friends.php',
            'app/Modules/Concerts/class-concert-trip-pages.php',
            'app/Modules/Concerts/class-concert-intelligence.php',
            'app/Modules/Studio/class-review-studio.php',
            'app/Modules/Studio/class-tn-studio.php',
            'app/Modules/Admin/class-runtime-audit.php',
            'app/Modules/Admin/class-service-tag-manager.php',
            'app/Modules/Admin/class-explorer-test-lab.php',
            'app/Modules/Admin/class-test-player-friends.php',
            'app/Modules/Admin/class-gameplay-control-center.php',
            'app/Modules/Admin/class-admin.php',
        ] as $file) require_once TNG_OS_PATH . $file;

        $module_classes = [
            \TNG_OS\Modules\Services\Service_Registry::class,
            \TNG_OS\Modules\Settings\Settings::class,
            \TNG_OS\Modules\Assets\Assets::class,
            \TNG_OS\Modules\Developer\Developer_Manager::class,
            \TNG_OS\Modules\Destinations\Destinations::class,
            \TNG_OS\Modules\Destinations\Destination_Platform::class,
            \TNG_OS\Modules\Destinations\Destination_Editor::class,
            \TNG_OS\Modules\Destinations\Destination_Relationships::class,
            \TNG_OS\Modules\Entities\Entity_Bridge::class,
            \TNG_OS\Modules\Entities\Platform_Health::class,
            \TNG_OS\Modules\Entities\Graph_Explorer::class,
            \TNG_OS\Modules\Entities\Relationship_Manager::class,
            \TNG_OS\Modules\Entities\Recommendation_Engine::class,
            \TNG_OS\Modules\Entities\Recommendation_Studio::class,
            \TNG_OS\Modules\Sources\Content_Sources::class,
            \TNG_OS\Modules\Frontend\Food_Service::class,
            \TNG_OS\Modules\Frontend\Recommendations::class,
            \TNG_OS\Modules\Frontend\Smart_Recommendations::class,
            \TNG_OS\Modules\Frontend\Discovery_Search::class,
            \TNG_OS\Modules\Frontend\World_Engine::class,
            \TNG_OS\Modules\Frontend\Quest_Runtime::class,
            \TNG_OS\Modules\Frontend\Quest_Finale::class,
            \TNG_OS\Modules\Frontend\Quest_Memory::class,
            \TNG_OS\Modules\Frontend\Explorer_Identity::class,
            \TNG_OS\Modules\Frontend\Explorer_Journal::class,
            \TNG_OS\Modules\Frontend\Daily_Missions::class,
            \TNG_OS\Modules\Frontend\Adventure_Rewards::class,
            \TNG_OS\Modules\Frontend\Weekly_Expedition::class,
            \TNG_OS\Modules\Frontend\Explorer_Leaderboard::class,
            \TNG_OS\Modules\Frontend\Community_Expedition::class,
            \TNG_OS\Modules\Frontend\Explorer_Challenges::class,
            \TNG_OS\Modules\Frontend\Explorer_Friends::class,
            \TNG_OS\Modules\Concerts\Concert_Trip_Pages::class,
            \TNG_OS\Modules\Concerts\Concert_Intelligence::class,
            \TNG_OS\Modules\Studio\Review_Studio::class,
            \TNG_OS\Modules\Studio\TN_Studio::class,
            \TNG_OS\Modules\Admin\Runtime_Audit::class,
            \TNG_OS\Modules\Admin\Service_Tag_Manager::class,
            \TNG_OS\Modules\Admin\Explorer_Test_Lab::class,
            \TNG_OS\Modules\Admin\Test_Player_Friends::class,
            \TNG_OS\Modules\Admin\Gameplay_Control_Center::class,
            \TNG_OS\Modules\Admin\Admin::class,
        ];

        $feature_modules = [
            'quest_finale' => 'quest_finale',
            'quest_memory' => 'quest_memory',
            'explorer_journal' => 'explorer_journal',
            'daily_missions' => 'daily_missions',
            'adventure_rewards' => 'adventure_rewards',
            'weekly_expedition' => 'weekly_expedition',
            'explorer_leaderboard' => 'explorer_leaderboard',
            'community_expedition' => 'community_expedition',
            'explorer_challenges' => 'explorer_challenges',
            'explorer_friends' => 'explorer_friends',
        ];

        $seen_classes = [];
        foreach ($module_classes as $class) {
            if (isset($seen_classes[$class])) continue;
            $seen_classes[$class] = true;
            $module = new $class();
            $module_id = $module->id();
            if (isset($feature_modules[$module_id]) && !\TNG_OS\Modules\Admin\Gameplay_Control_Center::enabled($feature_modules[$module_id])) continue;
            if (isset($this->modules[$module_id])) continue;
            $this->modules[$module_id] = $module;
        }

        foreach ($this->modules as $module) $module->register($this->container);
        require_once TNG_OS_PATH . 'app/Modules/Compatibility/class-legacy-runtime.php';
        require_once TNG_OS_PATH . 'app/Modules/Compatibility/class-content-manager-legacy.php';
        $this->container->set('legacy_core', new \TN_Game_Core());
        $this->container->set('legacy_content_manager', new \TNG_Content_Manager());
        foreach ($this->modules as $module) $module->boot($this->container);
        add_action('init', static function (): void {
            if (get_option('tng_os_rewrite_flush_needed')) {
                flush_rewrite_rules(false);
                delete_option('tng_os_rewrite_flush_needed');
            }
        }, 99);
    }
}
