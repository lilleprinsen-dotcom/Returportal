<?php
/**
 * Admin frontend template for LP Cargonizer Return Portal.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$returns = LP_Cargonizer_Returns::instance();
$returns->enqueue_assets();

$title = get_option( LP_Cargonizer_Returns::OPT_ADMIN_PAGE_TITLE, 'Returadmin' );

get_header();

echo '<div class="lp-wrap">';
echo '<h1 class="lp-admin-title" style="margin-bottom:16px">' . esc_html( $title ) . '</h1>';
echo do_shortcode( '[cargonizer_admin_login]' );
echo '</div>';

get_footer();
