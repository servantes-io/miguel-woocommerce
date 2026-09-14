/**
 * Registers the Miguel payment method with the block checkout so that it is never offered.
 *
 * The Miguel gateway only names the orders Miguel creates itself. Registering it here keeps the
 * Checkout block editor from listing it as incompatible; canMakePayment() keeps it off the checkout.
 */
( function () {
	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var createElement = window.wp.element.createElement;

	var data = ( getSetting( 'paymentMethodData', {} ) || {} ).miguel || {};
	var title = data.title || 'Miguel';

	registerPaymentMethod( {
		name: 'miguel',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( 'span', null ),
		edit: createElement( 'span', null ),
		canMakePayment: function () {
			return false;
		},
		supports: {
			features: data.supports || [ 'products' ],
		},
	} );
} )();
