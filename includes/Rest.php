<?php
/**
 * REST routes for the block editor.
 *
 * @package BristleconeZfolio
 */

declare(strict_types=1);

namespace Bristlecone\Zfolio;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Editors can list connections (labels only, never addresses) and the pages
 * of one connection, to pick a page in the block.
 */
final class Rest {

	public const NAMESPACE = 'bristlecone-zfolio/v1';

	/**
	 * Hook up.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/connections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'connections' ),
				'permission_callback' => array( self::class, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'pages' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => array(
					'connection' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => static fn ( $value ): bool => is_string( $value ) && Connections::valid_id( $value ),
					),
				),
			)
		);
	}

	/**
	 * Editors only.
	 */
	public static function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Connection labels.
	 */
	public static function connections(): WP_REST_Response {
		$out = array();

		foreach ( Connections::all() as $id => $connection ) {
			$state = Connections::state( $id );
			$out[] = array(
				'id'    => $id,
				'label' => '' !== $connection['label'] ? $connection['label'] : ( '' !== $state['title'] ? $state['title'] : $id ),
			);
		}

		return new WP_REST_Response( $out );
	}

	/**
	 * A connection's pages.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function pages( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'connection' );

		if ( null === Connections::get( $id ) ) {
			return new WP_REST_Response( array(), 404 );
		}

		$out = array();

		foreach ( Store::index( $id ) as $row ) {
			$out[] = array(
				'id'      => $row['page_id'],
				'slug'    => $row['slug'],
				'title'   => $row['title'],
				'parents' => $row['parents'],
			);
		}

		return new WP_REST_Response( $out );
	}
}
