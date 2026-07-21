<?php
namespace TNG_OS\Modules\Settings;
use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
if (!defined('ABSPATH')) exit;

final class Settings implements Module_Interface {
    public const OPTION = 'tng_os_settings';
    private array $settings = [];

    public function id(): string { return 'settings'; }

    public function register(Container $container): void {
        $this->settings = wp_parse_args(get_option(self::OPTION, []), [
            'site_name'=>'TN Game',
            'google_places_key'=>(string)get_option('tng_food_google_places_api_key',''),
            'google_test_place_id'=>'',
            'mapbox_token'=>(string)get_option('tng_mapbox_access_token',''),
            'mapbox_style'=>(string)get_option('tng_mapbox_style_url','mapbox://styles/mapbox/outdoors-v12'),
            'default_checkin_xp'=>25,'default_photo_xp'=>10,'default_radius'=>150,
            'mileage_interval'=>0.5,'mileage_xp'=>10
        ]);
        $container->set('settings', $this);
        add_action('admin_post_tng_os_save_settings', [$this,'save']);
        add_action('wp_ajax_tng_os_test_google', [$this,'test_google']);
        add_action('wp_ajax_tng_os_test_mapbox', [$this,'test_mapbox']);
    }

    public function boot(Container $container): void {}
    public function all(): array { return $this->settings; }
    public function get(string $key,$default=null) { return $this->settings[$key] ?? $default; }

    public function save(): void {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('tng_os_save_settings');
        $input=(array)wp_unslash($_POST['settings']??[]);
        $clean=$this->settings;
        foreach(['site_name','google_places_key','google_test_place_id','mapbox_token','mapbox_style'] as $key) {
            $clean[$key]=sanitize_text_field($input[$key]??'');
        }
        foreach(['default_checkin_xp','default_photo_xp','default_radius','mileage_interval','mileage_xp'] as $key) {
            $clean[$key]=max(0,(float)($input[$key]??0));
        }
        update_option(self::OPTION,$clean,false);
        update_option('tng_food_google_places_api_key',$clean['google_places_key'],false);
        update_option('tng_mapbox_access_token',$clean['mapbox_token'],false);
        update_option('tng_mapbox_style_url',$clean['mapbox_style'],false);
        wp_safe_redirect(admin_url('admin.php?page=tng-os-settings&updated=1'));
        exit;
    }

    public function test_google(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('tng_os_test_google','nonce');
        $key=sanitize_text_field(wp_unslash($_POST['key']??''));
        $place=sanitize_text_field(wp_unslash($_POST['place_id']??''));
        if(!$key||!$place) wp_send_json_error(['message'=>'Enter both an API key and test Place ID.'],400);
        $response=wp_remote_get('https://places.googleapis.com/v1/places/'.rawurlencode($place),[
            'timeout'=>20,'headers'=>['X-Goog-Api-Key'=>$key,'X-Goog-FieldMask'=>'id,displayName,formattedAddress']
        ]);
        if(is_wp_error($response)) wp_send_json_error(['message'=>$response->get_error_message()],500);
        $code=wp_remote_retrieve_response_code($response);
        $body=json_decode(wp_remote_retrieve_body($response),true);
        if($code<200||$code>=300) wp_send_json_error(['message'=>$body['error']['message']??('Google returned HTTP '.$code)],$code?:400);
        wp_send_json_success(['message'=>'Connected to '.($body['displayName']['text']??'Google Places').'.']);
    }

    public function test_mapbox(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('tng_os_test_mapbox','nonce');
        $token=sanitize_text_field(wp_unslash($_POST['token']??''));
        if(!$token) wp_send_json_error(['message'=>'Enter a Mapbox token.'],400);
        $response=wp_remote_get('https://api.mapbox.com/styles/v1/mapbox/streets-v12?access_token='.rawurlencode($token),['timeout'=>20]);
        if(is_wp_error($response)) wp_send_json_error(['message'=>$response->get_error_message()],500);
        $code=wp_remote_retrieve_response_code($response);
        if($code<200||$code>=300) wp_send_json_error(['message'=>'Mapbox returned HTTP '.$code.'.'],$code?:400);
        wp_send_json_success(['message'=>'Mapbox token is working.']);
    }
}
