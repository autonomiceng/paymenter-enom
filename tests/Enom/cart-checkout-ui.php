<?php
$enomTestLabel = 'cart-checkout-ui';
require dirname(__DIR__).'/enom-native-app.php';
require dirname(__DIR__).'/enom-http.php';
require dirname(__DIR__).'/enom-client-fixture.php';

use Paymenter\Extensions\Servers\Enom\Services\CartOrdering;
use Paymenter\Extensions\Servers\Enom\Services\Catalogue;

$user=client('checkout-ui@example.com');
$url=Paymenter\Extensions\Servers\Enom\Services\DomainOrders::checkoutUrl($com,'register','prefilled.com',3);
verify($product->hidden && str_contains($url,'config%5Bdomain%5D=prefilled.com') && str_contains($url,'plan='),'Hidden checkout URL lacks its purchased plan or name.');
$page=enomHttp($user,'GET',$url);
verify($page->getStatusCode()===200 && str_contains($page->getContent(),'prefilled.com') && str_contains($page->getContent(),'Domain name'),'Direct hidden product checkout did not prefill the domain.');
$before=count($providerCommands);
$fields=app(CartOrdering::class)->fields($product);
verify(count($providerCommands)===$before && array_column($fields,'name')===['domain'],'Rendering checkout fetched provider data or exposed private fields.');
$cart=enomCartStart($user); enomCartAdd($user,$cart,'waiting.com'); enomCartCheckout($user,$cart);
$domain=Paymenter\Extensions\Servers\Enom\Models\Domain::where('domain','waiting.com')->sole();
$page=enomHttp($user,'GET','/domains/'.$domain->id);
verify($page->getStatusCode()===200 && str_contains($page->getContent(),'Pay the invoice'),'Pending domain lost its payment link.');
foreach([['POST','/domains/quote'],['POST','/domains/order'],['GET','/domains/order/'.str_repeat('a',48)]] as [$method,$uri]) { verify(enomHttp($user,$method,$uri)->getStatusCode()===404,'Obsolete ordering route remains.'); }
$availability['move-ui.com']='transferable';
$transfer=enomCartOrder($user,'move-ui.com','transfer');
$page=enomHttp($user,'GET','/domains/'.$transfer['domain']->id);
verify($page->getStatusCode()===200 && str_contains($page->getContent(),'id="transfer-code"') && str_contains($page->getContent(),'name="transfer_code"'),'Pending transfer does not include the code form.');
verify(!str_contains(json_encode($transfer['service']->properties->pluck('value','key')),'epp'),'Native service property retained a transfer code.');
echo 'Cart checkout UI: '.$checks.' passed; native direct checkout and domain routes.'.PHP_EOL;
$successful=true;
