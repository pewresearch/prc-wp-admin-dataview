/**
 * WordPress webpack config for PRC Admin DataViews.
 *
 * The classic bundle stays the provider-script handle (`prc-wp-admin-dataview`).
 * Tiny ESM route files are copied into `build/routes/` so PHP can register
 * `@prc/wp-admin-dataview/list` as a script module. Do not bundle
 * `@wordpress/boot` here.
 *
 * @package
 */

const fs = require('fs');
const path = require('path');

const config = require('../../webpack.config');

function withCopyPlugin(webpackConfig) {
	return {
		...webpackConfig,
		plugins: [
			...(webpackConfig.plugins || []),
			{
				apply(compiler) {
					compiler.hooks.afterEmit.tap('CopyRoutesPlugin', () => {
						const dest = path.resolve(__dirname, 'build/routes');
						fs.mkdirSync(path.join(dest, 'list'), {
							recursive: true,
						});
						fs.copyFileSync(
							path.resolve(__dirname, 'src/routes/loader.js'),
							path.join(dest, 'loader.js')
						);
						fs.copyFileSync(
							path.resolve(
								__dirname,
								'src/routes/list/content.js'
							),
							path.join(dest, 'list/content.js')
						);
					});
				},
			},
		],
	};
}

module.exports = Array.isArray(config)
	? config.map((item, index) => (index === 0 ? withCopyPlugin(item) : item))
	: withCopyPlugin(config);
