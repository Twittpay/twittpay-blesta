<?php

/**
 * en_us language for the TwittPay gateway.
 */
// Basics
$lang['TwittPay.name'] = 'TwittPay';
$lang['TwittPay.description'] = 'Accept bKash, Nagad, Rocket, Upay and card payments through your own TwittPay gateway.';

// Errors
$lang['TwittPay.!error.api_key.valid'] = 'Please enter your Brand Key.';
$lang['TwittPay.!error.currency_rate.valid'] = 'The USD to BDT rate must be a number.';
$lang['TwittPay.!error.api.response'] = 'The gateway could not start this payment. Please try again or contact support.';

// Settings
$lang['TwittPay.meta.api_key'] = 'Brand Key';
$lang['TwittPay.meta.currency_rate'] = 'USD to BDT Rate';

// Field notes
$lang['TwittPay.tooltip.api_key'] = 'From your gateway dashboard, under Brands.';
$lang['TwittPay.tooltip.currency_rate'] = 'Only used when an invoice is not in BDT. 1 USD = this many BDT.';
