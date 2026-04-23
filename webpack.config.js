const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

// Bundle @wordpress/dataviews into our admin bundle. WP <= 6.x doesn't
// register it as a script handle, so externalising would 404 at runtime.
const BUNDLE = new Set( [ '@wordpress/dataviews' ] );

module.exports = {
	...defaultConfig,
	plugins: defaultConfig.plugins.map( ( plugin ) => {
		if ( plugin instanceof DependencyExtractionWebpackPlugin ) {
			return new DependencyExtractionWebpackPlugin( {
				requestToExternal( request ) {
					if ( BUNDLE.has( request ) ) {
						return undefined;
					}
				},
				requestToHandle( request ) {
					if ( BUNDLE.has( request ) ) {
						return undefined;
					}
				},
			} );
		}
		return plugin;
	} ),
};
