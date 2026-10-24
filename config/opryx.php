<?php
return [
    // Self-service sign-up page is served only on this host (e.g. app.example.com). Empty = sign-up disabled (super admin creates companies).
    'signup_host' => env('OPRYX_SIGNUP_HOST'),
    // New sign-ups get <slug>.<tenant_root> (needs a wildcard DNS record + wildcard subdomain on the hosting).
    'tenant_root' => env('OPRYX_TENANT_ROOT'),
    'trial_days' => (int) env('OPRYX_TRIAL_DAYS', 14),
    // Shown to client companies on the Billing page: how to pay the subscription.
    'billing_note' => env('OPRYX_BILLING_NOTE', 'Pay by bKash / Nagad / bank transfer and send the transaction ID to your Opryx provider. Your plan is extended as soon as the payment is confirmed.'),
];
