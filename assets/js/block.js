( function ( blocks, element, i18n, components, blockEditor, serverSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;

	function yesNoDefaultControl( props ) {
		return el( components.SelectControl, {
			label: props.label,
			value: props.value,
			options: [
				{ label: __( 'Standard', 'pretix-eventlister' ), value: 'default' },
				{ label: __( 'Ja', 'pretix-eventlister' ), value: 'yes' },
				{ label: __( 'Nein', 'pretix-eventlister' ), value: 'no' },
			],
			onChange: props.onChange,
		} );
	}

	blocks.registerBlockType( 'pretix-eventlister/events', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var setAttrs = props.setAttributes;

			return el(
				element.Fragment,
				{},
				el(
					blockEditor.InspectorControls,
					{},
					el(
						components.PanelBody,
						{ title: __( 'Abfrage', 'pretix-eventlister' ), initialOpen: true },
						el( components.SelectControl, {
							label: __( 'Scope', 'pretix-eventlister' ),
							value: attrs.scope,
							options: [
								{ label: __( 'Ausgewaehlt', 'pretix-eventlister' ), value: 'selected' },
								{ label: __( 'Alle', 'pretix-eventlister' ), value: 'all' },
							],
							onChange: function ( value ) {
								setAttrs( { scope: value } );
							},
						} ),
						el( components.TextControl, {
							label: __( 'Veranstalter (Slugs)', 'pretix-eventlister' ),
							help: __( 'Kommagetrennt, z.B. hsp-events,partner-a', 'pretix-eventlister' ),
							value: attrs.organizers,
							onChange: function ( value ) {
								setAttrs( { organizers: value } );
							},
						} ),
						el( components.TextControl, {
							label: __( 'Limit', 'pretix-eventlister' ),
							help: __( 'Zahl oder \"all\"', 'pretix-eventlister' ),
							value: attrs.limit,
							onChange: function ( value ) {
								setAttrs( { limit: value } );
							},
						} )
					),
					el(
						components.PanelBody,
						{ title: __( 'Darstellung', 'pretix-eventlister' ), initialOpen: false },
						el( components.SelectControl, {
							label: __( 'Layout', 'pretix-eventlister' ),
							value: attrs.style,
							options: [
								{ label: __( 'Standard', 'pretix-eventlister' ), value: 'default' },
								{ label: __( 'Grid', 'pretix-eventlister' ), value: 'grid' },
								{ label: __( 'Liste', 'pretix-eventlister' ), value: 'list' },
								{ label: __( 'Kompakt', 'pretix-eventlister' ), value: 'compact' },
							],
							onChange: function ( value ) {
								setAttrs( { style: value } );
							},
						} ),
						yesNoDefaultControl( {
							label: __( 'Beschreibung', 'pretix-eventlister' ),
							value: attrs.show_description,
							onChange: function ( value ) { setAttrs( { show_description: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Veranstalter', 'pretix-eventlister' ),
							value: attrs.show_organizer,
							onChange: function ( value ) { setAttrs( { show_organizer: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Eventbild', 'pretix-eventlister' ),
							value: attrs.show_image,
							onChange: function ( value ) { setAttrs( { show_image: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Countdown', 'pretix-eventlister' ),
							value: attrs.show_countdown,
							onChange: function ( value ) { setAttrs( { show_countdown: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Ort', 'pretix-eventlister' ),
							value: attrs.show_location,
							onChange: function ( value ) { setAttrs( { show_location: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Uhrzeit', 'pretix-eventlister' ),
							value: attrs.show_time,
							onChange: function ( value ) { setAttrs( { show_time: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'HSP-Hinweis', 'pretix-eventlister' ),
							value: attrs.show_platform_notice,
							onChange: function ( value ) { setAttrs( { show_platform_notice: value } ); },
						} )
					),
					el(
						components.PanelBody,
						{ title: __( 'Features', 'pretix-eventlister' ), initialOpen: false },
						yesNoDefaultControl( {
							label: __( 'Frontend-Filter', 'pretix-eventlister' ),
							value: attrs.filters,
							onChange: function ( value ) { setAttrs( { filters: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Mehr laden', 'pretix-eventlister' ),
							value: attrs.load_more,
							onChange: function ( value ) { setAttrs( { load_more: value } ); },
						} ),
						el( components.TextControl, {
							label: __( 'Page-Size', 'pretix-eventlister' ),
							help: __( 'Nur relevant, wenn \"Mehr laden\" aktiv ist.', 'pretix-eventlister' ),
							value: attrs.page_size,
							onChange: function ( value ) {
								setAttrs( { page_size: value } );
							},
						} ),
						yesNoDefaultControl( {
							label: __( 'Badges', 'pretix-eventlister' ),
							value: attrs.badges,
							onChange: function ( value ) { setAttrs( { badges: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Verfuegbarkeit-Badges', 'pretix-eventlister' ),
							value: attrs.badges_availability,
							onChange: function ( value ) { setAttrs( { badges_availability: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Verfuegbare Tickets anzeigen', 'pretix-eventlister' ),
							value: attrs.show_available_tickets,
							onChange: function ( value ) { setAttrs( { show_available_tickets: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Kalender-Links', 'pretix-eventlister' ),
							value: attrs.calendar,
							onChange: function ( value ) { setAttrs( { calendar: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'schema.org Markup', 'pretix-eventlister' ),
							value: attrs.schema,
							onChange: function ( value ) { setAttrs( { schema: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( 'Modal-Details', 'pretix-eventlister' ),
							value: attrs.modal,
							onChange: function ( value ) { setAttrs( { modal: value } ); },
						} ),
						yesNoDefaultControl( {
							label: __( '3D-Tilt', 'pretix-eventlister' ),
							value: attrs.tilt,
							onChange: function ( value ) { setAttrs( { tilt: value } ); },
						} )
					)
				),
				el( components.Disabled, {},
					el( serverSideRender, {
						block: 'pretix-eventlister/events',
						attributes: attrs,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.i18n, window.wp.components, window.wp.blockEditor, window.wp.serverSideRender );
