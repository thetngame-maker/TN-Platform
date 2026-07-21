<?php
namespace TNG_OS\Modules\Assets;
use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
if (!defined('ABSPATH')) exit;
final class Assets implements Module_Interface {
    public function id(): string { return 'assets'; }
    public function register(Container $container): void { $container->set('assets',$this); add_action('init',[$this,'register_types'],15); }
    public function boot(Container $container): void {}
    public function register_types(): void {
        if(!post_type_exists('tng_asset')) register_post_type('tng_asset',[
            'labels'=>['name'=>'TN Game Assets','singular_name'=>'TN Game Asset','add_new_item'=>'Add Reusable Asset'],
            'public'=>false,'show_ui'=>true,'show_in_menu'=>false,'supports'=>['title','editor','thumbnail'],'show_in_rest'=>true
        ]);
        if(!taxonomy_exists('tng_asset_type')) register_taxonomy('tng_asset_type','tng_asset',[
            'labels'=>['name'=>'Asset Types','singular_name'=>'Asset Type'],'public'=>false,'show_ui'=>true,'hierarchical'=>true,'show_admin_column'=>true
        ]);
    }
}
