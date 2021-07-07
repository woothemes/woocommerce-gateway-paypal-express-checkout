const { esLintConfig: baseConfig } = require( '@woocommerce/e2e-environment' );

module.exports = {
	...baseConfig,
	root: true,
	parser: 'babel-eslint',
	extends: [
		...baseConfig.extends,
		'wpcalypso/react',
		'plugin:jsx-a11y/recommended',
	],
	plugins: [
		...baseConfig.plugins,
		'jsx-a11y',
	],
	env: {
		...baseConfig.env,
		browser: true,
		node: true,
	},
	globals: {
		...baseConfig.globals,
		wp: true,
		wpApiSettings: true,
		wcSettings: true,
	},
};