/* global window */
( function () {
	const registry = window.wc && window.wc.wcBlocksRegistry;
	const settingsApi = window.wc && window.wc.wcSettings;
	const element = window.wp && window.wp.element;
	if ( ! registry || ! settingsApi || ! element ) {
		return;
	}

	const { registerPaymentMethod } = registry;
	const { createElement } = element;
	const settings = settingsApi.getSetting( 'payram_data', {} );
	const label = settings.title || 'Pay with Crypto (PayRam)';

	const Content = function () {
		return createElement( 'div', null, settings.description || '' );
	};

	registerPaymentMethod( {
		name: 'payram',
		label: createElement( 'span', null, label ),
		ariaLabel: label,
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: ( settings.supports && settings.supports.length ) ? settings.supports : [ 'products' ],
		},
	} );
} )();
