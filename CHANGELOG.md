# Changelog

## 0.5.0

- Domains sell through Paymenter's own cart and checkout. Each ending (.com, .net, …) is a hidden product with yearly prices set by your pricing rules. Endings priced below Enom's cost stay off sale.
- An order goes to Enom only after its whole invoice is paid. If the customer's account details are incomplete, the order waits until they are fixed; staff can apply the corrected details from Registrar requests.
- Transfers ask for the transfer code with an exact deadline, and customers can change a saved code until the transfer is sent. A missed deadline or a refused transfer returns the payment as account credit.
- Renewals follow the expiry date Enom confirms. Renewal invoices go out 21 days before it by default.
- Nameservers and transfer lock refresh automatically once a registration or transfer completes.
- Customers see clearer status for queued, cancelled and in-progress orders. Staff see which registrar requests need attention, including requests Enom never confirmed.
- Cost syncs warn about renewals billed below cost and link straight to the pricing page.

### Installation and upgrade

This release supports fresh installs and reinstalling over data from the same 0.5 schema. It does not convert 0.4 or earlier development schemas. Back up the extension files and the database before uploading, and do not upload 0.5 over an older schema. After uploading, check the schema and the installed version.
