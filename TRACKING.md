# Usage Tracking

_Pinterest for WooCommerce_ implements usage tracking, based on the native [WooCommerce Usage Tracking](https://woocommerce.com/usage-tracking/), and is only enabled when WooCommerce Tracking is enabled.

When a store opts in to WooCommerce usage tracking and uses _Pinterest for WooCommerce_, they will also be opted in to the tracking added by _Pinterest for WooCommerce_.

## What is tracked

As in WooCommerce core, only non-sensitive data about how a store is set up and managed is tracked. We **do not track or store personal data** from your clients.

<woo-tracking-jsdoc>
<!---
Everything below will be automatically generated by `woo-tracking-jsdoc`.
Do not edit it manually!
-->

### [`wcadmin_pfw_account_connect_button_click`](assets/source/setup-guide/app/components/Account/Connection.js#L37)
Clicking on "Connect" Pinterest account button.
#### Emitters
- [`AccountConnection`](assets/source/setup-guide/app/components/Account/Connection.js#L60) 

### [`wcadmin_pfw_account_convert_button_click`](assets/source/setup-guide/app/steps/SetupAccount.js#L31)
Clicking on "… convert your personal account" button.
#### Emitters
- [`SetupAccount`](assets/source/setup-guide/app/steps/SetupAccount.js#L51) 

### [`wcadmin_pfw_account_create_button_click`](assets/source/setup-guide/app/steps/SetupAccount.js#L26)
Clicking on "… create a new Pinterest account" button.
#### Emitters
- [`SetupAccount`](assets/source/setup-guide/app/steps/SetupAccount.js#L51) 

### [`wcadmin_pfw_account_disconnect_button_click`](assets/source/setup-guide/app/components/Account/Connection.js#L42)
Clicking on "Disconnect" Pinterest account button during account setup.
#### Emitters
- [`AccountConnection`](assets/source/setup-guide/app/components/Account/Connection.js#L60) 

### [`wcadmin_pfw_business_account_connect_button_click`](assets/source/setup-guide/app/components/Account/BusinessAccountSelection.js#L24)
Clicking on "Connect" business account button.
#### Emitters
- [`BusinessAccountSelection`](assets/source/setup-guide/app/components/Account/BusinessAccountSelection.js#L40) 

### [`wcadmin_pfw_business_account_create_button_click`](assets/source/setup-guide/app/components/Account/BusinessAccountSelection.js#L19)
Clicking on "Create business account" button.
#### Emitters
- [`BusinessAccountSelection`](assets/source/setup-guide/app/components/Account/BusinessAccountSelection.js#L40) 

### [`wcadmin_pfw_get_started_faq`](assets/source/setup-guide/app/views/LandingPageApp.js#L193)
Clicking on getting started page faq item to collapse or expand it.
#### Properties
|   |   |   |
|---|---|---|
`action` | `string` | `'expand' \| 'collapse'` What action was initiated.
`question_id` | `string` | Identifier of the clicked question.
#### Emitters
- [`FaqQuestion`](assets/source/setup-guide/app/views/LandingPageApp.js#L212) whenever the FAQ is toggled.

<!---
End of `woo-tracking-jsdoc`-generated content.
-->
</woo-tracking-jsdoc>