<?php
/**
 * Bristlecone → zFolio admin screen.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio\Admin;

use Bristlecone\Zfolio\Cache;
use Bristlecone\Zfolio\Connections;
use Bristlecone\Zfolio\Cron;
use Bristlecone\Zfolio\Feed\Address;
use Bristlecone\Zfolio\Feed\Client;
use Bristlecone\Zfolio\Feed\Response;
use Bristlecone\Zfolio\Feed\Validator;
use Bristlecone\Zfolio\Store;
use Bristlecone\Zfolio\Sync;

/**
 * Connections list, add/edit form, and actions (Test connection, Refresh now,
 * Clear content, Delete). Every action is an admin-post request with a nonce
 * and a manage_options check.
 */
final class SettingsPage {

	public const PARENT_SLUG = 'bristlecone';
	public const PAGE_SLUG   = 'bristlecone-zfolio';
	private const ACTION     = 'bristlecone_zfolio';
	private const NOTICE     = 'bristlecone_zfolio_notice_';

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'global_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BRISTLECONE_ZFOLIO_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Register zFolio under the shared Bristlecone menu.
	 *
	 * Parent-guard: create slug `bristlecone` only when missing so this plugin
	 * can stand alone, or attach when another Bristlecone plugin already
	 * created the parent.
	 */
	public static function register_menu(): void {
		$parent_slug    = self::PARENT_SLUG;
		$created_parent = false;

		if ( empty( $GLOBALS['admin_page_hooks'][ $parent_slug ] ) ) {
			add_menu_page(
				__( 'Bristlecone', 'bristlecone-zfolio' ),
				__( 'Bristlecone', 'bristlecone-zfolio' ),
				'manage_options',
				$parent_slug,
				array( self::class, 'render_page' ),
				self::menu_icon(),
				58
			);
			$created_parent = true;
		}

		add_submenu_page(
			$parent_slug,
			__( 'zFolio for WordPress', 'bristlecone-zfolio' ),
			__( 'zFolio', 'bristlecone-zfolio' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);

		// WP copies the parent slug as the first submenu item. Remove that
		// duplicate “Bristlecone / Bristlecone” row when we created the parent.
		if ( $created_parent ) {
			remove_submenu_page( $parent_slug, $parent_slug );
		}
	}

	/**
	 * Bold letter “B” as a custom SVG menu icon (not a Dashicon).
	 * Same data URI as the other Bristlecone plugins. WordPress recolors
	 * fill="black" via admin menu CSS filters.
	 */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true"><path fill="black" fill-rule="evenodd" d="M3.75 2.25h7.4c2.72 0 4.7 1.52 4.7 3.92 0 1.42-.74 2.52-1.96 3.12 1.5.62 2.46 1.9 2.46 3.62 0 2.68-2.22 4.34-5.42 4.34H3.75V2.25Zm3.2 2.15v3.15h3.7c1.22 0 1.98-.64 1.98-1.6 0-.94-.76-1.55-1.98-1.55H6.95Zm0 5.3v3.9h4.15c1.42 0 2.28-.76 2.28-1.92 0-1.18-.86-1.98-2.28-1.98H6.95Z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SVG data URI.
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array<string> $links Links.
	 * @return array<string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'bristlecone-zfolio' ) . '</a>' );

		return $links;
	}

	/**
	 * Screen URL.
	 *
	 * @param array<string,string> $args Query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::PAGE_SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Render the screen.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switches.
		$edit = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'zFolio for WordPress', 'bristlecone-zfolio' ) . '</h1>';

		self::flash();

		if ( 'new' === $edit || Connections::valid_id( $edit ) ) {
			self::render_form( 'new' === $edit ? null : Connections::get( $edit ) );
		} elseif ( Connections::valid_id( $view ) && null !== Connections::get( $view ) ) {
			self::render_pages( $view );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * The connections table.
	 */
	private static function render_list(): void {
		$connections = Connections::all();

		echo '<p>' . esc_html__( 'Each connection reads one zFolio feed. In zFolio, choose Connect to WordPress on a document or folder, then paste its feed address here.', 'bristlecone-zfolio' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( self::url( array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Add Connection', 'bristlecone-zfolio' ) . '</a></p>';

		if ( ! $connections ) {
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Connection', 'bristlecone-zfolio' ), __( 'Status', 'bristlecone-zfolio' ), __( 'Pages', 'bristlecone-zfolio' ), __( 'Last Checked', 'bristlecone-zfolio' ), __( 'Shortcode', 'bristlecone-zfolio' ), __( 'Actions', 'bristlecone-zfolio' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $connections as $id => $connection ) {
			$state = Connections::state( $id );
			$label = '' !== $connection['label'] ? $connection['label'] : ( '' !== $state['title'] ? $state['title'] : $id );

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( self::url( array( 'view' => $id ) ) ) . '">' . esc_html( $label ) . '</a></strong><br><code>' . esc_html( Address::mask( (string) $connection['address'] ) ) . '</code></td>';
			echo '<td>' . esc_html( self::status_text( $state ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( Store::count( $id ) ) ) . '</td>';
			echo '<td>' . esc_html( self::when( (int) $state['last_checked'] ) ) . '</td>';
			echo '<td><code>[zfolio connection="' . esc_html( $id ) . '" page="…"]</code></td>';
			echo '<td>';
			self::action_button( 'refresh', $id, __( 'Refresh Now', 'bristlecone-zfolio' ), 'button-primary' );
			self::action_button( 'test', $id, __( 'Test Connection', 'bristlecone-zfolio' ) );
			echo ' <a class="button" href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Edit', 'bristlecone-zfolio' ) . '</a>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Clear All Content', 'bristlecone-zfolio' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes every stored page and fetches each feed again on its next update. Connections stay.', 'bristlecone-zfolio' ) . '</p>';
		self::action_button( 'clear_all', '', __( 'Clear All', 'bristlecone-zfolio' ), '', __( 'Delete all stored zFolio content and fetch it again?', 'bristlecone-zfolio' ) );
	}

	/**
	 * A connection's pages, with per-page Refresh.
	 *
	 * @param string $id Connection id.
	 */
	private static function render_pages( string $id ): void {
		$connection = (array) Connections::get( $id );
		$state      = Connections::state( $id );

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All connections', 'bristlecone-zfolio' ) . '</a></p>';
		echo '<h2>' . esc_html( '' !== $connection['label'] ? $connection['label'] : $state['title'] ) . '</h2>';
		echo '<p>' . esc_html( self::status_text( $state ) ) . '</p>';

		$rows = Store::index( $id );

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No pages yet. Choose Refresh Now on the connections list.', 'bristlecone-zfolio' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'bristlecone-zfolio' ) . '</th><th>' . esc_html__( 'Shortcode', 'bristlecone-zfolio' ) . '</th><th>' . esc_html__( 'Fetched', 'bristlecone-zfolio' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$fetched = '0000-00-00 00:00:00' === $row['fetched_at'] ? 0 : (int) strtotime( $row['fetched_at'] . ' UTC' );

			echo '<tr><td>' . esc_html( str_repeat( '— ', count( $row['parents'] ) ) . $row['title'] ) . '<br><code>' . esc_html( $row['slug'] ) . '</code></td>';
			echo '<td><code>[zfolio connection="' . esc_html( $id ) . '" page="' . esc_html( $row['page_id'] ) . '"]</code></td>';
			echo '<td>' . esc_html( self::when( $fetched ) ) . '</td><td>';
			self::action_button( 'refresh_page', $id, __( 'Refresh', 'bristlecone-zfolio' ), '', '', array( 'page_id' => (string) $row['page_id'] ) );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Add or edit form.
	 *
	 * @param array<string,mixed>|null $connection Connection, or null for new.
	 */
	private static function render_form( ?array $connection ): void {
		$is_new     = null === $connection;
		$connection = $connection ?? array(
			'id'       => '',
			'label'    => '',
			'address'  => '',
			'interval' => 15,
			'takedown' => 'hide',
			'math'     => 'mathml',
		);

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'All connections', 'bristlecone-zfolio' ) . '</a></p>';
		echo '<h2>' . esc_html( $is_new ? __( 'Add Connection', 'bristlecone-zfolio' ) : __( 'Edit Connection', 'bristlecone-zfolio' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="save">';
		echo '<input type="hidden" name="connection" value="' . esc_attr( (string) $connection['id'] ) . '">';
		wp_nonce_field( self::ACTION . '_save_' . $connection['id'] );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="zfolio-label">' . esc_html__( 'Name', 'bristlecone-zfolio' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="zfolio-label" name="label" value="' . esc_attr( (string) $connection['label'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Shown to editors when they pick a page. Leave empty to use the feed’s title.', 'bristlecone-zfolio' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="zfolio-address">' . esc_html__( 'Feed Address', 'bristlecone-zfolio' ) . '</label></th><td>';
		if ( ! $is_new ) {
			echo '<p><code>' . esc_html( Address::mask( (string) $connection['address'] ) ) . '</code></p>';
		}
		echo '<input type="password" class="large-text code" id="zfolio-address" name="address" value="" autocomplete="off" spellcheck="false"' . ( $is_new ? ' required' : '' ) . '>';
		echo '<p class="description">' . esc_html( $is_new ? __( 'Paste the address from Connect to WordPress in zFolio. It works like a password, so it is hidden once saved.', 'bristlecone-zfolio' ) : __( 'Leave empty to keep the current address. Paste a new one if the owner rotated it.', 'bristlecone-zfolio' ) ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="zfolio-interval">' . esc_html__( 'Check for Updates', 'bristlecone-zfolio' ) . '</label></th><td><select id="zfolio-interval" name="interval">';
		foreach ( Connections::INTERVALS as $minutes ) {
			/* translators: %d: minutes. */
			echo '<option value="' . esc_attr( (string) $minutes ) . '"' . selected( (int) $connection['interval'], $minutes, false ) . '>' . esc_html( sprintf( _n( 'Every %d minute', 'Every %d minutes', $minutes, 'bristlecone-zfolio' ), $minutes ) ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'When the Feed Is Paused or Turned Off', 'bristlecone-zfolio' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="takedown" value="hide"' . checked( $connection['takedown'], 'hide', false ) . '> ' . esc_html__( 'Hide its content', 'bristlecone-zfolio' ) . '</label><br>';
		echo '<label><input type="radio" name="takedown" value="keep"' . checked( $connection['takedown'], 'keep', false ) . '> ' . esc_html__( 'Keep showing the last content, and notify admins', 'bristlecone-zfolio' ) . '</label>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Math', 'bristlecone-zfolio' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="math" value="mathml"' . checked( $connection['math'], 'mathml', false ) . '> ' . esc_html__( 'Show formulas (MathML)', 'bristlecone-zfolio' ) . '</label><br>';
		echo '<label><input type="radio" name="math" value="tex"' . checked( $connection['math'], 'tex', false ) . '> ' . esc_html__( 'Show the TeX source instead', 'bristlecone-zfolio' ) . '</label>';
		echo '</fieldset></td></tr>';

		echo '</tbody></table>';
		submit_button( $is_new ? __( 'Add Connection', 'bristlecone-zfolio' ) : __( 'Save Changes', 'bristlecone-zfolio' ) );
		echo '</form>';

		if ( ! $is_new ) {
			echo '<hr><h2>' . esc_html__( 'Remove', 'bristlecone-zfolio' ) . '</h2>';
			self::action_button( 'clear', (string) $connection['id'], __( 'Clear Content', 'bristlecone-zfolio' ), '', __( 'Delete this connection’s stored pages and fetch them again?', 'bristlecone-zfolio' ) );
			self::action_button( 'delete', (string) $connection['id'], __( 'Delete Connection', 'bristlecone-zfolio' ), 'button-link-delete', __( 'Delete this connection and its stored pages? Blocks and shortcodes that use it will show nothing.', 'bristlecone-zfolio' ) );
		}
	}

	/**
	 * A one-button form for an action.
	 *
	 * @param string               $action  Action.
	 * @param string               $id      Connection id.
	 * @param string               $label   Button text.
	 * @param string               $class   Extra button class.
	 * @param string               $confirm Confirmation question.
	 * @param array<string,string> $extra   Extra hidden fields.
	 */
	private static function action_button( string $action, string $id, string $label, string $class = '', string $confirm = '', array $extra = array() ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 4px 4px 0"';
		if ( '' !== $confirm ) {
			echo ' onsubmit="return window.confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ');"';
		}
		echo '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="connection" value="' . esc_attr( $id ) . '">';
		foreach ( $extra as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		wp_nonce_field( self::ACTION . '_' . $action . '_' . $id );
		echo '<button type="submit" class="button ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Handle a POST.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'bristlecone-zfolio' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, per action and connection.
		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id     = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		// phpcs:enable

		check_admin_referer( self::ACTION . '_' . $action . '_' . $id );

		if ( '' !== $id && ( ! Connections::valid_id( $id ) || null === Connections::get( $id ) ) ) {
			self::redirect( __( 'That connection no longer exists.', 'bristlecone-zfolio' ), false );
		}

		switch ( $action ) {
			case 'save':
				self::save( $id );
				break;

			case 'test':
				$result = self::test( (string) Connections::get( $id )['address'] );
				self::redirect( $result['message'], $result['ok'] );
				break;

			case 'refresh':
				$result = ( new Sync() )->run( $id, true );
				Cron::schedule( $id );
				self::redirect( $result['message'], $result['ok'] );
				break;

			case 'refresh_page':
				$page_id = isset( $_POST['page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['page_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
				if ( preg_match( Validator::PAGE_ID, $page_id ) ) {
					self::refetch_page( $id, $page_id );
				}
				$result = ( new Sync() )->run( $id, true );
				self::redirect( $result['message'], $result['ok'], array( 'view' => $id ) );
				break;

			case 'clear':
				Store::clear( $id );
				Connections::update_state( $id, array( 'etag' => '', 'revision' => -1, 'feed_id' => '' ) );
				Cache::purge_connection( $id );
				Cron::schedule( $id, 5 );
				self::redirect( __( 'Content cleared. It will be fetched again in a moment.', 'bristlecone-zfolio' ), true );
				break;

			case 'clear_all':
				foreach ( array_keys( Connections::all() ) as $each ) {
					Store::clear( $each );
					Connections::update_state( $each, array( 'etag' => '', 'revision' => -1, 'feed_id' => '' ) );
					Cache::purge_connection( $each );
					Cron::schedule( $each, 5 );
				}
				self::redirect( __( 'All content cleared. It will be fetched again in a moment.', 'bristlecone-zfolio' ), true );
				break;

			case 'delete':
				Cache::purge_connection( $id );
				Store::clear( $id );
				Cron::unschedule( $id );
				Connections::delete( $id );
				self::redirect( __( 'Connection deleted.', 'bristlecone-zfolio' ), true );
				break;
		}

		self::redirect( __( 'Nothing to do.', 'bristlecone-zfolio' ), false );
	}

	/**
	 * Make the next sync refetch one page, by forgetting its hash.
	 *
	 * @param string $id      Connection id.
	 * @param string $page_id Page id.
	 */
	private static function refetch_page( string $id, string $page_id ): void {
		global $wpdb;

		$wpdb->update( Store::table(), array( 'hash' => '' ), array( 'connection_id' => $id, 'page_id' => $page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Save the add/edit form.
	 *
	 * @param string $id Connection id, '' for new.
	 */
	private static function save( string $id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle().
		$raw_address = isset( $_POST['address'] ) ? trim( (string) wp_unslash( $_POST['address'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Address::normalize().
		$label       = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$interval    = isset( $_POST['interval'] ) ? absint( $_POST['interval'] ) : 15;
		$takedown    = isset( $_POST['takedown'] ) ? sanitize_key( wp_unslash( $_POST['takedown'] ) ) : 'hide';
		$math        = isset( $_POST['math'] ) ? sanitize_key( wp_unslash( $_POST['math'] ) ) : 'mathml';
		// phpcs:enable

		$existing = '' !== $id ? Connections::get( $id ) : null;
		$address  = (string) ( $existing['address'] ?? '' );
		$changed  = false;

		if ( '' !== $raw_address ) {
			$normalized = Address::normalize( $raw_address );

			if ( null === $normalized ) {
				self::redirect( __( 'That doesn’t look like a zFolio feed address. It should start with https:// and contain /feed/v1/.', 'bristlecone-zfolio' ), false, array( 'edit' => '' !== $id ? $id : 'new' ) );
			}

			$changed = $normalized !== $address;
			$address = $normalized;
		}

		if ( '' === $address ) {
			self::redirect( __( 'A feed address is required.', 'bristlecone-zfolio' ), false, array( 'edit' => 'new' ) );
		}

		$new = '' === $id;
		$id  = $new ? Connections::new_id() : $id;

		Connections::save(
			$id,
			array(
				'label'    => $label,
				'address'  => $address,
				'interval' => $interval,
				'takedown' => $takedown,
				'math'     => $math,
			)
		);

		if ( $changed ) {
			// A rotated address serves the same feed; keep content, recheck at once.
			Connections::update_state( $id, array( 'etag' => '', 'last_error' => '' ) );
		}

		Cache::purge_connection( $id );
		Cron::schedule( $id, $new || $changed ? 5 : null );

		self::redirect( $new ? __( 'Connection added. Its pages will be fetched in a moment.', 'bristlecone-zfolio' ) : __( 'Connection saved.', 'bristlecone-zfolio' ), true );
	}

	/**
	 * Test an address without storing anything.
	 *
	 * @param string $address Address.
	 * @return array{ok:bool,message:string}
	 */
	private static function test( string $address ): array {
		$response = ( new Client() )->manifest( $address );

		if ( Response::OK === $response->outcome ) {
			$manifest = Validator::manifest( (array) $response->data );

			if ( null === $manifest ) {
				return array( 'ok' => false, 'message' => __( 'zFolio answered, but the plugin can’t read the feed.', 'bristlecone-zfolio' ) );
			}

			return array(
				'ok'      => true,
				/* translators: 1: feed title, 2: number of pages. */
				'message' => sprintf( _n( 'Connected to “%1$s”, %2$d page.', 'Connected to “%1$s”, %2$d pages.', count( $manifest['pages'] ), 'bristlecone-zfolio' ), $manifest['title'], count( $manifest['pages'] ) ),
			);
		}

		return array(
			'ok'      => false,
			'message' => match ( $response->outcome ) {
				Response::PAUSED       => __( 'The feed is paused by its owner in zFolio.', 'bristlecone-zfolio' ),
				Response::REVOKED      => __( 'The feed was turned off in zFolio.', 'bristlecone-zfolio' ),
				Response::NOT_FOUND    => __( 'zFolio doesn’t recognize this feed address.', 'bristlecone-zfolio' ),
				Response::RATE_LIMITED => __( 'zFolio asked the site to slow down. Try again in a minute.', 'bristlecone-zfolio' ),
				Response::GONE         => __( 'zFolio no longer serves this version of the feed. Update the plugin.', 'bristlecone-zfolio' ),
				/* translators: %s: short technical reason. */
				default                => sprintf( __( 'zFolio couldn’t be reached (%s).', 'bristlecone-zfolio' ), $response->message ),
			},
		);
	}

	/**
	 * Redirect back to the screen with a one-time notice.
	 *
	 * @param string               $message Notice.
	 * @param bool                 $ok      Success or error.
	 * @param array<string,string> $args    Query args.
	 */
	private static function redirect( string $message, bool $ok, array $args = array() ): never {
		set_transient( self::NOTICE . get_current_user_id(), array( 'message' => $message, 'ok' => $ok ), 60 );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Print and clear the one-time notice.
	 */
	private static function flash(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( ! empty( $notice['ok'] ) ? 'success' : 'error' ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Notices on every admin screen: retired or deprecated versions, and
	 * feeds that are paused or turned off.
	 */
	public static function global_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$lines = array();

		foreach ( Connections::all() as $id => $connection ) {
			$state = Connections::state( $id );
			$name  = '' !== $connection['label'] ? $connection['label'] : ( '' !== $state['title'] ? $state['title'] : $id );

			if ( (int) $state['gone_current'] > 0 || 'version retired' === $state['last_error'] ) {
				/* translators: %s: connection name. */
				$lines[] = sprintf( __( '%s: zFolio retired this version of its feed. Update zFolio for WordPress to keep the content current.', 'bristlecone-zfolio' ), $name );
			} elseif ( '' !== $state['sunset'] || '' !== $state['deprecation'] ) {
				/* translators: %s: connection name. */
				$lines[] = sprintf( __( '%s: zFolio will retire this version of its feed. Update zFolio for WordPress before then.', 'bristlecone-zfolio' ), $name );
			}

			if ( 'paused' === $state['status'] || 'revoked' === $state['status'] ) {
				$lines[] = 'paused' === $state['status']
					/* translators: %s: connection name. */
					? sprintf( __( '%s: the feed is paused by its owner in zFolio.', 'bristlecone-zfolio' ), $name )
					/* translators: %s: connection name. */
					: sprintf( __( '%s: the feed was turned off in zFolio.', 'bristlecone-zfolio' ), $name );
			}
		}

		if ( ! $lines ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'zFolio for WordPress', 'bristlecone-zfolio' ) . '</strong></p><ul style="list-style:disc;padding-left:2em">';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open zFolio settings', 'bristlecone-zfolio' ) . '</a></p></div>';
	}

	/**
	 * Status for people.
	 *
	 * @param array<string,mixed> $state State.
	 */
	private static function status_text( array $state ): string {
		$text = match ( $state['status'] ) {
			'active'  => __( 'Connected', 'bristlecone-zfolio' ),
			'paused'  => __( 'Paused in zFolio', 'bristlecone-zfolio' ),
			'revoked' => __( 'Turned off in zFolio', 'bristlecone-zfolio' ),
			default   => __( 'Waiting for the first update', 'bristlecone-zfolio' ),
		};

		if ( ! empty( $state['pending'] ) ) {
			$text .= ' — ' . __( 'still fetching pages', 'bristlecone-zfolio' );
		}

		if ( '' !== (string) $state['last_error'] ) {
			/* translators: %s: short technical reason. */
			$text .= ' — ' . sprintf( __( 'last problem: %s', 'bristlecone-zfolio' ), (string) $state['last_error'] );
		}

		return $text;
	}

	/**
	 * "5 minutes ago", or "Never".
	 *
	 * @param int $timestamp Unix time.
	 */
	private static function when( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return __( 'Never', 'bristlecone-zfolio' );
		}

		/* translators: %s: human time difference. */
		return sprintf( __( '%s ago', 'bristlecone-zfolio' ), human_time_diff( $timestamp ) );
	}
}
