<?php

namespace Paymenter\Extensions\Servers\Enom\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Customer help page. */
class HelpController extends Controller
{
    /** One short customer help page. */
    public function customer(Request $request)
    {
        abort_unless($request->user() !== null, 403);

        return view('enom::help.customer');
    }


    public function admin(Request $request, ?string $topic = null)
    {
        abort_unless($request->user()?->hasPermission('admin.enom.manage'), 403);

        return redirect('/admin/enom-setup');
    }
}
