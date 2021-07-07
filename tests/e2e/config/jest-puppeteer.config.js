const { useE2EJestPuppeteerConfig } = require( '@woocommerce/e2e-environment' );

const puppeteerConfig = useE2EJestPuppeteerConfig( {
	launch: {
		headless: false,
		devtools: false,
		ignoreHTTPSErrors: true,
		args: [ '--window-size=1920,1080', '--user-agent=chrome', '--disable-features=site-per-process' ],
		defaultViewport: {
			width: 1920,
			height: 1080,
		},
	}
} );

module.exports = puppeteerConfig;
