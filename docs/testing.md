# Offline development tests

Use [CONTRIBUTING](../CONTRIBUTING.md) for dependency setup, selected-suite commands and release checks.
The native dependency is pristine official Paymenter 1.5.9 with locked Composer dependencies and default-theme assets. The guard checks Composer identity and the literal app version. It rejects installed applications, `.env` files and cached configuration. Tests download no dependencies.

## Suite boundaries

| Suite | Evidence |
| --- | --- |
| lint | PHP syntax for runtime and tests. |
| package | Deterministic runtime-only ZIP, manifest, metadata, license and builder refusals. |
| provider | Injected protocol responses, response bounds, strict money, exact-term catalogue prices, contacts, registry inputs and RDAP. |
| native | Real services, cart, invoices and transactions; ownership, renewal admission, stale claims, reconciliation, cost caps, refunds, reminders and management readbacks. |
| ui | Native HTTP routes, sessions and middleware; checkout, client views, Filament permissions and local jsdom behavior. |

The full run selects all five suites. A selected subset records omissions explicitly and establishes only that subset's coverage. Failed assertions, missing dependencies, timeouts and changed runtime bytes fail the run. Retained expected behavior always uses a hard assertion.

## Isolation and fixtures

The runner gives each child a small environment, blocks PHP URL streams and network transport functions, and verifies every runtime byte after each command. Provider requests use injected transports; JavaScript uses synthetic fetch, clipboard and dialog boundaries. Use an external network block as well when running in a container.

Native suites share `tests/enom-native-app.php`: a disposable application, in-memory SQLite, test-only bcrypt rounds and the installed extension. Boot and currency creation can be disabled explicitly by fixtures that need their own setup. Inert fixture helpers share current managed-domain and native-cart setup; executable suites do not include other suites. Temporary apps are removed at shutdown. Runner receipts and failed outputs remain under `enom-tests-*` in the selected temporary directory.

Portable XML fixtures are synthetic or sanitized protocol shapes. `tests/fixtures/enom-test-api/` contains trimmed TEST API captures for `GetTLDList`, exact-term `PE_GetProductPrice`, `GetExtAttributes`, `GetTLDDetails`, balance and child nameservers. Tracking keys, times, balances and account/domain identifiers are removed or replaced. Some detail captures reuse the `.com` shape; the `.us` country list is shortened. Synthetic malformed-price and unknown-outcome cases supplement captures. Captures establish parser behavior, not provider write acceptance.

`tests/public-addresses.php` generates public IPv4/IPv6 inputs for glue validation and derives CIDR boundaries from blocked ranges. No generated address is contacted.

UI helpers use the native HTTP kernel and session login, real form keys and optional production CSRF checks. Domain-page helpers render through the controller. Filament resources render through Livewire after panel registration. Without PHP `intl`, pagination uses a minimal view and the TLD bulk-selection table is checked through its definition. These checks do not establish browser behavior.

## Acceptance limits

Passing offline checks do not establish native upload/install acceptance, MariaDB concurrency, actual browser/mobile/keyboard behavior, custom-theme completion, real registrar writes, incoming transfer completion, grace renewal acceptance, gateway settlement or SMTP delivery. Compatibility beyond Paymenter 1.5.9 remains unverified. See the [lifecycle reminder limitation](design/domain-lifecycle.md#reminders).

## Supported PHP

Runtime source supports PHP 8.3 and 8.4, matching Paymenter 1.5.9. CI's separate PHP 8.3
job parses every shipped PHP file and runs the offline token guard in
`tests/Enom/php83-compatibility.php`. The guard rejects known PHP 8.4 function calls,
asymmetric visibility, property hooks and unparenthesized `new` dereferences; synthetic
positive/negative fixtures run with it. Namespaced user functions and function imports
are resolved without executing source. The guard also runs in the offline lint suite.
This is a targeted regression check, not exhaustive PHP compatibility analysis: dynamic
function names, extension availability and untested runtime behavior remain outside it.
The existing full CI suite still runs on PHP 8.3; local suites may use PHP 8.4.

`request-gates.php` includes synthetic worker-deadline cases at 244/245/246 seconds and claim
lock delays. They retain a full request and 30-second finish margin, preserve unsent refusals and
check request-local deadline isolation. SQLite and injected clocks do not prove production waits.

`registration-setup.php` includes committed contact-recovery fixtures before, exactly at and
after the transfer code deadline. They assert queued execution before the deadline, a retained
staff review hold without enqueue at/after it, and unchanged saved inputs/payment/funding.
