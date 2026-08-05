<?php
if (!defined('ABSPATH')) exit;
$id = get_queried_object_id();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="tng-app" class="tng-router-shell tng-router-shell--trail">
    <?php echo class_exists('TNG_Trail_UI') ? TNG_Trail_UI::render($id) : ''; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
