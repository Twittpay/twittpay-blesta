# TwittPay for Blesta

Blesta non-merchant payment gateway

Part of the [TwittPay](https://twittpay.com) addon family.

## Quick start

1. Download the latest zip from the **Releases** page of this repository.
2. Install it on your Blesta following the guide below.
3. Open the TwittPay settings and enter your **Brand Key**. You can get it from [your dashboard](https://twittpay.com/user/brands).
4. Make a small test payment to confirm everything works.

Payments are always re-verified on your server before an order or invoice is marked paid.

## Detailed installation guide

```text
===========================================================================
 TWITTPAY - Blesta gateway
===========================================================================

 WHERE IT GOES
   Extract this zip at your Blesta root - the folder that has config/, app/ and
   components/ in it. Everything lands under:

     components/gateways/nonmerchant/twittpay/

   Nothing you already have is overwritten.

 INSTALL
   1. Blesta admin -> Settings -> Company -> Payment Gateways -> Available.
   2. Find "TwittPay" and click Install.
   3. Fill in the three fields:

        Endpoint URL      your own gateway address, e.g.
                          https://checkout.twittpay.com
                          (this is the API host - the same one on your
                          gateway's developer page)

        Brand Key           from your gateway dashboard, under Brands

        USD to BDT Rate   only used when an invoice is not in BDT

   4. Click Update Settings.
   5. Settings -> Company -> Billing/Payment -> Accepted currencies must include
      BDT if you invoice in BDT.

 HOW IT WORKS
   * The client clicks Pay, Blesta calls buildProcess(), and the module creates
     the payment and sends them to the gateway's hosted page.
   * When they come back, Blesta calls success() on the gateway callback URL.
   * The gateway also calls that same URL from its own server - that is
     validate(). No browser is involved there.
   * Both of them verify the transaction against the API before Blesta is told
     anything, so a hand-typed ?status=completed does nothing.

 WHAT A PAYMENT LOOKS LIKE IN BLESTA
   COMPLETED  -> approved, the invoice is paid
   PENDING    -> recorded as a pending transaction. The customer has sent the
                 money and your merchant has not approved it yet; the next
                 notification turns it into approved or declined. Do not ask the
                 customer to pay again.
   ERROR      -> declined

 CURRENCY
   The gateway charges BDT. A BDT invoice is sent as it is. An invoice in any
   other currency is multiplied by the USD to BDT Rate, and the original amount
   and currency travel in metadata - so the transaction Blesta records is still
   in the invoice's own currency, not the converted BDT.

   If you invoice in something other than USD, set the rate to that currency's
   BDT rate.

 WHAT TO WATCH
   * The Endpoint URL is your API host. Pasting the whole endpoint or a trailing
     /api is fine - only the scheme and host are used.
   * Refunds and voids are not supported through the API. Refund on your
     gateway's side, then record it in Blesta by hand.
   * Every API call is written to Blesta's gateway log
     (Tools -> Logs -> Gateway), so a failed payment can be read back.
   * Multi-company Blesta: the callback URL carries the company id, so each
     company can have its own key.

 CHECKED
   The PHP was checked with a lexer that balances braces only inside real PHP
   code, and config.json parses as JSON. PHP itself was NOT run - there is no
   PHP binary on the machine this was built on, so php -l was never executed.
   Install it on a test company first.
```
