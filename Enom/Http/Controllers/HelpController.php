<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Static help pages. No database reads, provider calls, configuration values or costs. */
class HelpController extends Controller
{
    public const ADMIN_TOPICS = [
        'setup' => ['title' => 'First configuration', 'summary' => 'The safe order for creating the server, credentials and product.'],
        'enrollment' => ['title' => 'Enrolling an existing domain', 'summary' => 'What enrollment checks, and what it changes.'],
        'calendar' => ['title' => 'Renewal calendar and credit', 'summary' => 'Issue and due days, and paying from customer credit.'],
        'sponsorship' => ['title' => 'Sponsorship approval and purchase', 'summary' => 'Why saving an approval never spends, and how spending works.'],
        'costs' => ['title' => 'Quotes and confirmed charges', 'summary' => 'Why a quote is not a charge, and when a charge stays unconfirmed.'],
        'results' => ['title' => 'Daily checks and billing results', 'summary' => 'What each registrar check and billing run result means.'],
        'review' => ['title' => 'Held, uncertain and retired requests', 'summary' => 'Resuming unsent renewals, provider facts, and retired invoice payments.'],
    ];

    /** One short customer help page. */
    public function customer(Request $request)
    {
        abort_unless($request->user() !== null, 403);

        return view('enom::help.customer');
    }

    public function admin(Request $request, ?string $topic = null)
    {
        abort_unless($request->user()?->hasPermission('admin.enom.manage'), 403);
        abort_unless($topic === null || isset(self::ADMIN_TOPICS[$topic]), 404);

        return view('enom::help.admin', ['topic' => $topic, 'topics' => self::ADMIN_TOPICS]);
    }
}
