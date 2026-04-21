( function () {
	var config = window.simpleX402Settings;
	if ( ! config ) {
		return;
	}
	var wrap     = document.getElementById( 'sx402-category-wrap' );
	var selector = 'input[name="' + config.option + '[paywall_mode]"]';
	var radios   = document.querySelectorAll( selector );
	if ( ! wrap || ! radios.length ) {
		return;
	}
	function sync() {
		var selected  = document.querySelector( selector + ':checked' );
		wrap.disabled = ! selected || selected.value !== config.modeCategory;
	}
	radios.forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
	sync();
} )();
