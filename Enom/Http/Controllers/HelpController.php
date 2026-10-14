<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Static help pages. No database reads, provider calls, configuration values or costs. */
class HelpController extends Controller
{
    public const CUSTOMER_TOPICS = [
        'ordering' => ['title' => 'Registering and transferring', 'summary' => 'What you can order, how long a quote lasts, and what a transfer needs.'],
        'payment' => ['title' => 'Paying an invoice', 'summary' => 'How to pay, and what to do if a card is declined or payment is processing.'],
        'statuses' => ['title' => 'Request statuses', 'summary' => 'What each request status means, and why a paid invoice is not a renewed domain.'],
        'renewal' => ['title' => 'Renewal', 'summary' => 'Expiry dates, invoice dates, and turning renewal off or back on.'],
        'dns' => ['title' => 'Nameservers and DNS', 'summary' => 'Nameservers, DNS and SRV records, and DNSSEC.'],
        'contacts' => ['title' => 'Contacts and locks', 'summary' => 'Contact updates, verification, and the registrar lock.'],
        'privacy' => ['title' => 'Contact privacy', 'summary' => 'Buying, renewing and turning privacy on or off.'],
        'transfers' => ['title' => 'Transfer code', 'summary' => 'What syncing the transfer code does and what it does not do.'],
    ];

    public const ADMIN_TOPICS = [
        'setup' => ['title' => 'First configuration', 'summary' => 'The safe order for creating the server, credentials and product.'],
        'enrollment' => ['title' => 'Enrolling an existing domain', 'summary' => 'What enrollment checks, and what it changes.'],
        'calendar' => ['title' => 'Renewal calendar and credit', 'summary' => 'Issue and due days, and paying from customer credit.'],
        'sponsorship' => ['title' => 'Sponsorship approval and purchase', 'summary' => 'Why saving an approval never spends, and how spending works.'],
        'costs' => ['title' => 'Quotes and confirmed charges', 'summary' => 'Why a quote is not a charge, and when a charge stays unconfirmed.'],
        'results' => ['title' => 'Daily checks and billing results', 'summary' => 'What each registrar check and billing run result means.'],
        'review' => ['title' => 'Held, uncertain and retired requests', 'summary' => 'Resuming unsent renewals, provider facts, and retired invoice payments.'],
    ];

    public function customer(Request $request, ?string $topic = null)
    {
        abort_unless($request->user() !== null, 403);
        abort_unless($topic === null || isset(self::CUSTOMER_TOPICS[$topic]), 404);

        return view('enom::help.customer', ['topic' => $topic, 'topics' => self::CUSTOMER_TOPICS]);
    }

    public function admin(Request $request, ?string $topic = null)
    {
        abort_unless($request->user()?->hasPermission('admin.enom.manage'), 403);
        abort_unless($topic === null || isset(self::ADMIN_TOPICS[$topic]), 404);

        return view('enom::help.admin', ['topic' => $topic, 'topics' => self::ADMIN_TOPICS]);
    }
}
