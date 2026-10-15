<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';
// Existing isolated official-runtime bootstrap, synthetic SQLite, no installed app environment.
$bootstrap=file_get_contents(__DIR__.'/domain-billing.php');
$prefix=explode('$checks = 0;',$bootstrap,2)[0];
if(!str_starts_with($prefix,'<?php')||!str_contains($prefix,':memory:'))throw new RuntimeException('Synthetic bootstrap required');
eval(substr($prefix,strlen('<?php')));
$billing=new Paymenter\Extensions\Servers\Enom\Services\DomainBilling(new Paymenter\Extensions\Servers\Enom\Services\DomainOperations);
App\Models\Service::updating(fn($service)=>$billing->preserveNativeDate($service));
App\Models\Currency::create(['code'=>'USD','name'=>'Dollar','format'=>'1,000.00']);
$user=App\Models\User::create(['email'=>'hosting-schema@example.invalid','password'=>'synthetic']);
$server=App\Models\Server::create(['name'=>'Synthetic hosting','type'=>'server','extension'=>'Plesk','enabled'=>false]);
$category=App\Models\Category::create(['name'=>'Synthetic hosting','slug'=>'synthetic-schema-hosting']);
$product=App\Models\Product::create(['name'=>'Synthetic ordinary hosting','category_id'=>$category->id,'server_id'=>$server->id]);
$service=App\Models\Service::create(['user_id'=>$user->id,'product_id'=>$product->id,'price'=>15,'quantity'=>1,'currency_code'=>'USD','status'=>'active','expires_at'=>'2026-12-01']);
$native=Illuminate\Support\Facades\DB::select("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name");
$checks=0;
foreach(['missing_metadata','missing_table'] as $failure){
 if($failure==='missing_metadata')Illuminate\Support\Facades\Schema::table('enom_domains',fn($table)=>$table->dropColumn('billing_managed'));
 else Illuminate\Support\Facades\Schema::drop('enom_domains');
 $service->update(['expires_at'=>$failure==='missing_metadata'?'2027-01-01':'2027-02-01']);
 if($service->fresh()->expires_at->format('Y-m-d')!==($failure==='missing_metadata'?'2027-01-01':'2027-02-01'))throw new RuntimeException('Incomplete Enom migration broke native hosting date save');$checks++;
 if(Illuminate\Support\Facades\DB::select("SELECT name,sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'enom_%' ORDER BY name")!=$native)throw new RuntimeException('Native table definitions changed');$checks++;
}
$successful=true;echo 'Missing-schema native hook: '.$checks." checks passed; no provider calls.\n";
