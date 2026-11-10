<?php
/**
 * Plugin Name: zFolio for WordPress
 * Plugin URI: https://bristleconeit.com/bristlecone-zfolio
 * Description: Show zFolio documents and folders on your WordPress site, in your theme. Content is cached in WordPress and refreshed when you update it in zFolio.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.3
 * Author: Bristlecone IT Services
 * Author URI: https://bristleconeit.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bristlecone-zfolio
 * Domain Path: /languages
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BRISTLECONE_ZFOLIO_VERSION', '0.1.0' );
define( 'BRISTLECONE_ZFOLIO_FILE', __FILE__ );
define( 'BRISTLECONE_ZFOLIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRISTLECONE_ZFOLIO_URL', plugin_dir_url( __FILE__ ) );

require_once BRISTLECONE_ZFOLIO_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( \Bristlecone\Zfolio\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Bristlecone\Zfolio\Plugin::class, 'deactivate' ) );

\Bristlecone\Zfolio\Plugin::instance()->boot();
