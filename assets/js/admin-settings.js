( function () {
	var config = window.simpleX402Settings;
	if ( ! config ) {
		return;
	}
	var field    = document.getElementById( 'sx402-category' );
	var selector = 'input[name="' + config.option + '[paywall_mode]"]';
	var radios   = document.querySelectorAll( selector );
	if ( ! field || ! radios.length ) {
		return;
	}
	function sync() {
		var selected   = document.querySelector( selector + ':checked' );
		field.disabled = ! selected || selected.value !== config.modeCategory;
	}
	radios.forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
	sync();
} )();
