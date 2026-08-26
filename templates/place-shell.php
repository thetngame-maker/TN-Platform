<?php
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="tng-app" class="tng-router-shell tng-place-shell">
    <?php echo TNG_Place_UI::render(); ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
