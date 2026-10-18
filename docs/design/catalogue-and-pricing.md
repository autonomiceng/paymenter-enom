# Catalogue and pricing

Targets Paymenter 1.5.9. [Ordering](ordering.md) owns checkout and binding; [lifecycle](domain-lifecycle.md) owns registrar execution and renewal authority.

## Catalogue and provider costs

Import `GetTLDList` into disabled endings. Registration, renewal and transfer have independent action switches. Enabling requires supported registry fields, usable priced terms and an annual renewal price for new orders. Registry minimum and maximum terms come from `GetTLDDetails`.

Read `PE_GetProductPrice` for the exact action and term. Registration requests each supported `Years` value from 1–10 unchanged; renewal and transfer use their action price. The response is a per-year wholesale amount. Total wholesale cost is that term's annual amount multiplied by years in integer ten-thousandths. Never substitute another term or combine first-year registration with renewal prices.

Daily cost sync publishes native USD prices. Missing, malformed or below-cost registration terms lose only their sale price. Transfers with unusable cost leave sale. Existing renewals continue at the computed retail price when below margin and alert staff. Missing renewal cost keeps the last renewal price and alerts staff. Explicitly disabled renewal billing remains disabled.

## Retail rules and products

Rules support fixed prices, cost plus amount and cost plus percent. Each ending uses the server default or its own rule. Optional rounding uses whole dollars, half dollars or `.99`. Round the annual retail amount before multiplying by purchased years. `PricingRule` owns integer conversion, arithmetic and decimal output; strict provider parsing and native tax conversion remain separate boundaries.

Each ending has hidden registration and transfer products in the `domains` category. Product markers `enom_catalogue=1`, `enom_tld_id` and `enom_action` identify their seller and action. The first published seller of an ending keeps `<ending>-registration` and `<ending>-transfer` slugs (dots become hyphens). Later sellers use the same base plus `-s<serverId>`. Retained products establish the first seller; disabling or republishing preserves slugs and all product/plan IDs. Only one seller per ending may be enabled. Disabled sellers keep hidden products without sale prices, and their retained services continue under the original server/account. Products allow separated quantities so different names can share checkout, while each domain line requires quantity one.

Registration plans cover supported, priced terms. Transfer has one one-year plan. The default transfer sale allowlist is `com,net,org`; staff must test other endings before enabling them. Immutable unsupported-ending rules still apply. An annual billing plan can exist without a one-year registration sale price when only longer registration terms are supported.

Public search and `/domains/pricing` show enabled endings with pre-tax prices labelled “plus tax where applicable.” Guest checks are limited to 30 per IP per minute and use a short cache. Checkout links carry a currently priced plan and prefilled domain. Sale switches and holds govern new checkout; they do not revoke paid fulfillment.

## Renewal pricing and authority

Domains use standard annual renewal pricing or explicit per-domain complimentary renewal. Staff pricing changes require `admin.enom.fund`; reseller costs require `admin.enom.costs`. Annual Service prices track current renewal pricing with native tax treatment. Existing invoices and requests retain frozen amounts and funding evidence. Switching to complimentary retires only clean unpaid renewal obligations; paid or partly paid history stays intact.

Complimentary renewal requires fresh admission and a frozen per-request cap. The server default cap is USD 50. Actual charges are recorded; an overrun pauses further automatic spending for that domain. This is no account-wide budget. Clients cannot request free initial orders, receive account-wide grants or set custom prices; staff initial orders are deferred.

ID Protect always has a separate invoice. Its price comes only from the server's `privacy_price`; a blank price stops sales. Product settings cannot supply it.

## Server settings

Credentials are encrypted and bound to one account/environment. Live mode also pins the site URL. Registrar processing defaults off; daily observation and renewal billing default on. Renewal invoice offsets default to issue 21 days and due 7 days before expiry. Full-invoice account-credit payment is optional and defaults off; grace renewal defaults off.

Blank default nameservers use Enom DNS. DNS tools default off. Observation rate limits are shared by account and default to 60 requests per minute. Reminder offsets and their delivery limits belong to [lifecycle](domain-lifecycle.md#reminders).
