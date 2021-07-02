# Setting up the Docker environment

## Prerequisites

### Docker
Obviously.

### PayPal Developer Account
You will need to create or sign into a [PayPal developer account](https://developer.paypal.com/) and [create buyer and seller accounts](https://developer.paypal.com/docs/payflow/express-checkout/testing/#create-paypal-sandbox-seller-and-buyer-accounts) to use with this plugin. You will need to use the aforementioned PayPal Sandbox seller account credentials with your store in your site's settings for this plugin, while you will be able to use your buyer account credentials to make test purchases at checkout using this plugin.

To load your Sandbox seller account credentials into the initialised Docker container for this site, create a `local.env` file in the route directory of this plugin including the following variables.

```
PAYPAL_SANDBOX_API_USERNAME=YOUR_SANDBOX_BUSINESS_ACCOUNT_API_USERNAME_HERE
PAYPAL_SANDBOX_API_PASSWORD=YOUR_SANDBOX_BUSINESS_ACCOUNT_API_PASSWORD_HERE
PAYPAL_SANDBOX_API_SIGNATURE=YOUR_SANDBOX_BUSINESS_ACCOUNT_API_SIGNATURE_HERE
```

## Getting started
To start up the local environment and create the containers for this plugin, run the following command.

```
npm run up
```

To bring the containers back down once you're finished, run this command.

```
npm run down
```

Your brand spanking new site should now be ready at http://localhost:8082 for you to use at your leisure.

## Jurassic Tube (Optional)
If you would like to use or share a more stable URL as opposed to relying on `localhost`, there is an available [Jurassic Tube](https://fieldguide.automattic.com/jurassic-tube/) configuration for you to take advantage of.

Once you have started the Docker containers for this plugin, simply run the below command to run the setup for Jurassic Tube and follow the instructions that will appear.

```
npm run tube:setup
```

Now to open up the tunnel to your Jurassic Tube URL, you can run `npm run tube:start` and you can close the tunnel as desired using `npm run tube:stop`.

## Using the local environment

### WordPress Admin
Open http://localhost:8082/wp-admin/
```
Username: admin
Password: admin
```

### Connecting to MySQL
Open phpMyAdmin at http://localhost:8083/, or connect using other MySQL clients with these credentials:
```
Host: localhost
Port: 5678
Username: wordpress
Password: wordpress
```
