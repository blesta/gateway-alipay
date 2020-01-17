# Alipay Gateway

[![Build Status](https://travis-ci.org/blesta/gateway-alipay.svg?branch=master)](https://travis-ci.org/blesta/gateway-alipay) [![Coverage Status](https://coveralls.io/repos/github/blesta/gateway-alipay/badge.svg?branch=master)](https://coveralls.io/github/blesta/gateway-alipay?branch=master)

This is a nonmerchant gateway for Blesta that integrates with [Alipay](https://global.alipay.com/).

## Install the Gateway

1. You can install the gateway via composer:

    ```
    composer require blesta/alipay
    ```

2. Upload the source code to a /components/gateways/nonmerchant/alipay/ directory within
your Blesta installation path.

    For example:

    ```
    /var/www/html/blesta/components/nonmerchant/alipay/
    ```

3. Log in to your admin Blesta account and navigate to
> Settings > Payment Gateways

4. Find the Alipay gateway and click the "Install" button to install it

5. You're done!

### Blesta Compatibility

|Blesta Version|Module Version|
|--------------|--------------|
|< v4.9.0|v1.0.0|
|>= v4.9.0|v1.1.0|
