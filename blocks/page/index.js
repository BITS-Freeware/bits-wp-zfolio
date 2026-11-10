/**
 * zFolio Page block editor. Plain wp.* JavaScript, no build step.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, useEffect, useState, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, Placeholder, Spinner } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const apiFetch = wp.apiFetch;
	const { __ } = wp.i18n;
	const { addQueryArgs } = wp.url;

	function usePath( path ) {
		const [ state, setState ] = useState( { loading: !! path, items: [] } );

		useEffect( () => {
			if ( ! path ) {
				setState( { loading: false, items: [] } );
				return;
			}

			let live = true;
			setState( { loading: true, items: [] } );

			apiFetch( { path } )
				.then( ( items ) => live && setState( { loading: false, items } ) )
				.catch( () => live && setState( { loading: false, items: [] } ) );

			return () => {
				live = false;
			};
		}, [ path ] );

		return state;
	}

	function pageLabel( page ) {
		const depth = ( page.parents || [] ).length;
		return '— '.repeat( depth ) + ( page.title || page.slug );
	}

	function Edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();
		const connections = usePath( '/bristlecone-zfolio/v1/connections' );
		const pages = usePath(
			attributes.connection
				? addQueryArgs( '/bristlecone-zfolio/v1/pages', { connection: attributes.connection } )
				: ''
		);

		const connectionOptions = [ { value: '', label: __( 'Choose a connection', 'bristlecone-zfolio' ) } ].concat(
			connections.items.map( ( item ) => ( { value: item.id, label: item.label } ) )
		);
		const pageOptions = [ { value: '', label: __( 'Choose a page', 'bristlecone-zfolio' ) } ].concat(
			pages.items.map( ( item ) => ( { value: item.id, label: pageLabel( item ) } ) )
		);

		const controls = el(
			Fragment,
			null,
			el( SelectControl, {
				label: __( 'Connection', 'bristlecone-zfolio' ),
				value: attributes.connection,
				options: connectionOptions,
				onChange: ( connection ) => setAttributes( { connection, page: '' } ),
				__nextHasNoMarginBottom: true,
			} ),
			attributes.connection &&
				( pages.loading
					? el( Spinner )
					: el( SelectControl, {
							label: __( 'Page', 'bristlecone-zfolio' ),
							value: attributes.page,
							options: pageOptions,
							onChange: ( page ) => setAttributes( { page } ),
							__nextHasNoMarginBottom: true,
					  } ) )
		);

		const inspector = el(
			InspectorControls,
			null,
			el( PanelBody, { title: __( 'zFolio Page', 'bristlecone-zfolio' ) }, controls )
		);

		if ( ! attributes.connection || ! attributes.page ) {
			let body = controls;

			if ( connections.loading ) {
				body = el( Spinner );
			} else if ( ! connections.items.length ) {
				body = el(
					'p',
					null,
					__( 'Add a zFolio connection under Bristlecone → zFolio first.', 'bristlecone-zfolio' )
				);
			}

			return el(
				'div',
				blockProps,
				inspector,
				el( Placeholder, { icon: 'media-document', label: __( 'zFolio Page', 'bristlecone-zfolio' ) }, body )
			);
		}

		return el(
			'div',
			blockProps,
			inspector,
			el( ServerSideRender, { block: 'bristlecone-zfolio/page', attributes } )
		);
	}

	registerBlockType( 'bristlecone-zfolio/page', {
		edit: Edit,
		save: () => null,
	} );
} )( window.wp );
