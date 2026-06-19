import {
	__experimentalConnectorItem as ConnectorItem,
	__experimentalRegisterConnector as registerConnector,
} from '@wordpress/connectors';

const { createElement } = window.wp.element;
const {
	Button,
	__experimentalText: Text,
	__experimentalVStack: VStack,
} = window.wp.components;

function getModuleData() {
	const element = document.getElementById(
		'wp-script-module-data-koneek-connectors-admin'
	);

	if ( ! element ) {
		return {};
	}

	try {
		return JSON.parse( element.textContent || '{}' );
	} catch {
		return {};
	}
}

const data = getModuleData();

function KoneekConnector( { name, description, logo } ) {
	const connections = Array.isArray( data.connections )
		? data.connections
		: [];
	const buttonLabel = connections.length
		? data.manageLabel
		: data.setupLabel;

	const actionArea = createElement(
		Button,
		{
			variant: 'secondary',
			size: 'compact',
			href: data.settingsUrl,
		},
		buttonLabel
	);

	const summary = connections.length
		? createElement(
				VStack,
				{ spacing: 2, className: 'koneek-connector-summary' },
				createElement(
					Text,
					{ size: 12, weight: 500 },
					data.savedLabel
				),
				createElement(
					'ul',
					{ className: 'koneek-connector-list' },
					...connections.map( ( connection ) =>
						createElement(
							'li',
							{ key: connection.id },
							connection.name
						)
					)
				)
		  )
		: createElement(
				Text,
				{ variant: 'muted', size: 12 },
				data.emptyLabel
		  );

	return createElement(
		ConnectorItem,
		{
			className: 'connector-item--koneek',
			logo,
			name,
			description,
			actionArea,
		},
		summary
	);
}

registerConnector( 'koneek', { render: KoneekConnector } );
