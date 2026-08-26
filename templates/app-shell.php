<?php
if (!defined('ABSPATH')) exit;
$route = \TNG_OS\Platform\App_Router::current_route();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="tng-app" class="tng-router-shell" data-route="<?php echo esc_attr($route); ?>">
    <?php echo \TNG_OS\Platform\App_Router::render_screen(); ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
