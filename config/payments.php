<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active gateway
    |--------------------------------------------------------------------------
    |
    | The one line that decides which provider handles payments. Switching to
    | Midtrans later means writing the driver, registering it below, and
    | changing this value. No controller or model needs to know.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Available drivers
    |--------------------------------------------------------------------------
    */

    'gateways' => [
        'manual' => \App\Payments\Gateways\ManualTransferGateway::class,
        // 'midtrans' => \App\Payments\Gateways\MidtransGateway::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Charge expiry
    |--------------------------------------------------------------------------
    |
    | How long a buyer has to pay before the charge lapses and the reserved
    | stock is released. Real gateways enforce their own window too, so keep
    | this at or below whatever the provider is configured with.
    |
    */

    'expiry_hours' => (int) env('PAYMENT_EXPIRY_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Manual transfer account
    |--------------------------------------------------------------------------
    |
    | Where buyers send money while no provider is connected. Kept in config
    | rather than hardcoded so it can differ between local and production.
    |
    */

    'manual' => [
        'bank_name' => env('MANUAL_BANK_NAME', 'BCA'),
        'account_number' => env('MANUAL_BANK_ACCOUNT'),
        'account_holder' => env('MANUAL_BANK_HOLDER', 'Rapaku'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Midtrans
    |--------------------------------------------------------------------------
    |
    | Filled in when the driver is written. Sandbox needs no company documents,
    | so it can be tested long before production access is approved.
    |
    */

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_PRODUCTION', false),
    ],

];