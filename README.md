# org.fsf.payment.trustcommerce

This extension allows CiviCRM users to use TrustCommerce.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP 7.0
* CiviCRM 4.7.x

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation

Intall the module in your custom extensions directory. The name of the top
level directory unpacked by the tarball should be changed to
`org.fsf.payment.trustcommerce`.

You will need to add rows to `civicrm_payment_processor_type` in your database.

    | id | name          | title                                         | description                     | is_active | is_default | user_name_label | password_label | signature_label | subject_label | class_name            | url_site_default                       | url_api_default | url_recur_default                      | url_button_default | url_site_test_default                  | url_api_test_default                   | url_recur_test_default                 | url_button_test_default | billing_mode | is_recur | payment_type | payment_instrument_id |
    | 19 | TrustCommerce | TrustCommerce (org.fsf.payment.trustcommerce) | TrustCommerce Payment Processor |         1 |       NULL | Vendor ID       | Password       |                 |               | Payment_TrustCommerce | https://vault.trustcommerce.com/trans/ |                 | https://vault.trustcommerce.com/trans/ |                    | https://vault.trustcommerce.com/trans/ | https://vault.trustcommerce.com/trans/ | https://vault.trustcommerce.com/trans/ |                         |            1 |        1 |            1 |                     1 |

## Usage

Go to Administer -> System Settings -> Payment Processors and add The
TrustCommerce PP.

## Known Issues

There are no hooks for installation and uninstallation of plugin, so tables
need to be updated manually.

If the class names in the `civicrm_payment_processor_type` or
`civicrm_payment_processor` tables are incorrect, then they need to be changed
to `Payment_TrustCommerce`.

