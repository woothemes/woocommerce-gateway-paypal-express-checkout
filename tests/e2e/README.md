# PayPal Checkout End-to-end tests

The PayPal Checkout e2e tests use the @woocommerce/e2e-environment package which is a reusable and extendable E2E testing environment for WooCommerce extensions. Find more information and docs here: https://github.com/woocommerce/woocommerce/tree/master/tests/e2e/env

## Setup

1. Install NodeJS `brew install node`
2. Install Docker ([Mac](https://docs.docker.com/docker-for-mac/install/)|[Windows](https://docs.docker.com/docker-for-windows/install/)) and make sure to [check docker is running](https://d.pr/i/lT7J7E).

## Running the tests

### Before running the tests
Before running the tests you need to first

1. Install all the test packages: `npm install`
2. Install Jest: `npm install jest --global`
3. Boot up the test environment: `npm run docker:up`

## How to run tests in headless mode

```
npm run test:e2e
```

### How to run tests in non-headless mode
To observe the browser while it's running tests you can run tests in a non-headless (dev) mode:

```
npm run test:e2e-dev
```

### How to run an individual test

```
npm run test:e2e ./tests/e2e/specs/example.test.js
```

## Writing tests

We use the following tools to write e2e tests:

- [Puppeteer](https://github.com/GoogleChrome/puppeteer) – a Node library which provides a high-level API to control Chrome or Chromium over the DevTools Protocol
- [jest-puppeteer](https://github.com/smooth-code/jest-puppeteer) – provides all required configuration to run tests using Puppeteer
- [expect-puppeteer](https://github.com/smooth-code/jest-puppeteer/tree/master/packages/expect-puppeteer) – assertion library for Puppeteer

Tests are kept in tests/e2e/specs folder.

## Other helpful resources

WooCommerce E2E setup docs: https://github.com/woocommerce/woocommerce/tree/master/tests/e2e/README.md
WooCommerce E2E Environment setup docs (for extensions): https://github.com/woocommerce/woocommerce/blob/master/tests/e2e/env/README.md
