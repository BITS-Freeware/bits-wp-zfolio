<?php
/**
 * Plugin bootstrap.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

use Bristlecone\Zfolio\Admin\SettingsPage;

/**
 * Wires the plugin's parts to WordPress.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * The instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Hook everything up.
	 */
	public function boot(): void {
		// Runs on every site of a network, so network activation works lazily.
		add_action( 'plugins_loaded', array( Store::class, 'maybe_install' ) );

		Cron::register();
		Refs::register();
		Shortcode::register();
		Block::register();
		Rest::register();

		if ( is_admin() ) {
			SettingsPage::register();
		}
	}

	/**
	 * Activation: create the table on this site.
	 */
	public static function activate(): void {
		Store::maybe_install();
	}

	/**
	 * Deactivation: stop polling. Content and settings stay.
	 */
	public static function deactivate(): void {
		wp_unschedule_hook( Cron::HOOK );
	}
}
