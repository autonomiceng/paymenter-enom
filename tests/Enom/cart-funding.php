<?php
$enomTestLabel = 'cart-funding';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use Paymenter\Extensions\Servers\Enom\Models\Domain;
use Paymenter\Extensions\Servers\Enom\Models\Operation;

$user = client('funding-owner@example.com');
$cart = enomCartStart($user);
enomCartAdd($user, $cart, 'fund-first.com', 'register', 3);
enomCartAdd($user, $cart, 'fund-second.com');
$hosting = App\Models\Product::create(['name'=>'Hosting','slug'=>'funding-hosting','category_id'=>$product->category_id]);
$hostingPlan = $hosting->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_unit'=>'month','billing_period'=>1]);
$hostingPlan->prices()->create(['currency_code'=>'USD','price'=>'20.00','setup_fee'=>'0.00']);
$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hostingPlan->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>['domain'=>'fund-first.com']]);
enomCartCheckout($user, $cart);
$invoice = Invoice::sole();
verify($invoice->items()->count()===3 && Service::count()===3 && (float)$invoice->total===68.0,'Mixed native checkout lost a domain or hosting line.');
$initials = Operation::all();
$invoice->transactions()->create(['amount'=>'36.00', 'status'=>'succeeded']);
$invoice->update(['status'=>Invoice::STATUS_PAID]);
foreach ($initials as $op) { verify(!$operations->funded($op->fresh()), 'One line funded from a partial mixed invoice.'); }
verify(Operation::where('state','queued')->count()===0, 'Partial payment queued a registrar request.');
InvoiceTransaction::withoutEvents(fn()=> $invoice->transactions()->create(['amount'=>'40.00', 'status'=>'succeeded']));
foreach ($initials as $op) { verify($operations->funded($op->fresh()), 'Overpayment did not cover the invoice.'); }
InvoiceTransaction::withoutEvents(fn()=> $invoice->transactions()->create(['amount'=>'-20.00', 'status'=>'succeeded']));
foreach ($initials as $op) { verify(!$operations->funded($op->fresh()), 'Successful negative adjustment was ignored.'); }
InvoiceTransaction::withoutEvents(fn()=> $invoice->transactions()->create(['amount'=>'20.00', 'status'=>'succeeded']));
$operations->acceptPaidInvoice($invoice->fresh());
foreach ($initials as $op) { verify($op->fresh()->state==='queued', 'Fully funded registration did not queue: '.$op->fresh()->safe_code); }
$op = $initials->first()->fresh();
$item = App\Models\InvoiceItem::findOrFail($op->invoice_item_id);
$item->update(['price'=>'35.99']);
verify(!$operations->funded($op->fresh()), 'Changed frozen line amount funded an order.');
$item->update(['price'=>'36.00']);
$policy=$op->policy;
$op->update(['policy'=>array_replace($policy,['checkout_years'=>1])]);
verify(!$operations->funded($op->fresh()), 'Changed purchased years funded an order.');
$op->update(['policy'=>$policy]);
$op->domain->service->update(['user_id'=>client('wrong-owner@example.com')->id]);
verify(!$operations->funded($op->fresh()), 'Changed service owner funded an order.');
$op->domain->service->update(['user_id'=>$user->id]);

// A first-term coupon remains recorded for native usage limits; renewal is annual and full price.
$cart = enomCartStart($user);
enomCartAdd($user,$cart,'coupon.com','register',3);
$coupon=App\Models\Coupon::create(['code'=>'FIRST20','type'=>'percentage','applies_to'=>'all','value'=>20,'recurring'=>1]);
$cart->update(['coupon_id'=>$coupon->id]);
enomCartCheckout($user,$cart);
$discount=Domain::where('domain','coupon.com')->sole();
$discountOp=$discount->operations()->sole();
verify($discountOp->amount==='28.80' && (float)$discount->service->price===14.0 && (int)$discount->service->coupon_id===$coupon->id, 'Coupon leaked into annual renewal or initial total differs.');
$discountInvoice=Invoice::findOrFail($discountOp->invoice_id);
$discountInvoice->transactions()->create(['amount'=>$discountInvoice->total,'status'=>'succeeded']);
verify($discount->service->fresh()->status===Service::STATUS_ACTIVE && $discount->service->fresh()->expires_at===null, 'Native payment activation restored a renewal date.');
verify($discountOp->fresh()->state==='queued', 'Native successful payment did not release the registration.');

foreach ([100,150] as $percent) {
    $cart=enomCartStart($user); enomCartAdd($user,$cart,'zero'.$percent.'.com');
    $coupon=App\Models\Coupon::create(['code'=>'ZERO'.$percent,'type'=>'percentage','applies_to'=>'all','value'=>$percent,'recurring'=>1]);
    $coupon->products()->attach($product->id);
    $cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hostingPlan->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>['domain'=>'zero'.$percent.'.com']]);
    $cart->update(['coupon_id'=>$coupon->id]);
    $before=tableRows(['orders','services','invoices','invoice_items','enom_domains','enom_operations']);
    enomCartCheckout($user,$cart);
    verify(tableRows(array_keys($before))===$before, 'Zero domain coupon left checkout obligations.');
}

$cancelled=enomCartOrder($user,'cancelled.com');
$cancelled['service']->update(['status'=>Service::STATUS_CANCELLED]);
verify($cancelled['operation']->fresh()->state==='rejected' && $cancelled['operation']->fresh()->payload===null, 'Cancelled unpaid order was not retired and purged.');
$cancelled['invoice']->transactions()->create(['amount'=>$cancelled['invoice']->total,'status'=>'succeeded']);
$operations->acceptPaidInvoice($cancelled['invoice']->fresh());
verify($cancelled['operation']->fresh()->state==='rejected', 'Late payment revived cancelled initial work.');
verify(count(array_filter($providerCommands,fn($c)=>$c[0]!=='Check'))===0,'Funding test sent a provider write.');
echo 'Cart funding: '.$checks.' passed; full invoice, net payments, coupons, native activation and cancellation; SQLite.'.PHP_EOL;
$successful=true;
