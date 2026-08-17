/**
 * Build config: extends the @wordpress/scripts default (wp externals,
 * asset.php generation) with our entries/output.
 *
 * Entries:
 *  - admin:  the wp-admin React SPA (uses @wordpress packages via externals)
 *  - widget: the frontend web component — imports NO @wordpress packages,
 *            so it compiles self-contained even though externals are mapped.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'agentyllo/src-js/admin/index.tsx' ),
		widget: path.resolve( __dirname, 'agentyllo/src-js/widget/index.ts' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'agentyllo/assets/build' ),
	},
};
