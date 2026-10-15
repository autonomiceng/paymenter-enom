<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Customer help page and the old staff help address. */
class HelpController extends Controller
{
    /** One short customer help page. */
    public function customer(Request $request)
    {
        abort_unless($request->user() !== null, 403);

        return view('enom::help.customer');
    }

    /** Staff guidance now lives on the Enom setup page in the admin panel. */
    public function admin(Request $request, ?string $topic = null)
    {
        abort_unless($request->user()?->hasPermission('admin.enom.manage'), 403);

        return redirect('/admin/enom-setup');
    }
}
