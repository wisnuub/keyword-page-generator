<?php
/**
 * Plugin Name:       Keyword Page Generator
 * Plugin URI:        https://github.com/wisnuub/keyword-page-generator
 * Description:       Create many pages from one template by swapping keywords — one page per city, service or product. Only visible text changes; images, links and layout stay intact. Works with the block editor, Classic Editor, Elementor, Divi and WPBakery, with optional AI rewriting.
 * Version:           3.0.0
 * Author:            Wisnu A. Kurniawan
 * Author URI:        https://github.com/wisnuub
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       keyword-page-generator
 * Requires at least: 5.9
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KPGEN_VERSION', '3.0.0' );
define( 'KPGEN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KPGEN_URL', plugin_dir_url( __FILE__ ) );
define( 'KPGEN_BASENAME', plugin_basename( __FILE__ ) );

require_once KPGEN_DIR . 'includes/class-kpgen-replacer.php';
require_once KPGEN_DIR . 'includes/class-kpgen-ai.php';
require_once KPGEN_DIR . 'includes/class-kpgen-generator.php';
require_once KPGEN_DIR . 'includes/class-kpgen-jobs.php';
require_once KPGEN_DIR . 'includes/class-kpgen-admin.php';

KPGen_Jobs::init();
if ( is_admin() ) {
    KPGen_Admin::init();
}

/**
 * Stop scheduled runs on deactivation; their data is kept for History.
 */
function kpgen_deactivate() {
    wp_unschedule_hook( KPGen_Jobs::CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'kpgen_deactivate' );
