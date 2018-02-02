<?php
namespace App\Http\Controllers;

use App\User;
use App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use App\Providers\BabiatorsHelpers;
use Illuminate\Support\Facades\Auth;
use Log;

class BabiatorsController
{
    /**
     * Show the profile for the given user.
     *
     * @param  int  $id
     * @return Response
     */

    private $skey;
    private $spass;
    private $shost;
    private $admin_url;
    private $requestObject;
    private $helper;

     public function __construct()
    {

        $this->helper = new BabiatorsHelpers();

        //$val = Config::get('app.shopify_key');
        $this->skey = config('app.shopify_key');
        $this->spass = config('app.shopify_pass');
        $this->shost = config('app.shopify_host');
        $this->admin_url = "https://" . $this->skey .":" . $this->spass . "@" .$this->shost;
    }

    public function getLostProduct(){

        $url = $this->admin_url . "/admin/products.json";
        $product = $this->helper->createCurlPostRequest($url,NULL,"GET");
        foreach($product->products as $p){
            if(preg_match("/Lost and Found Item/",$p->title)){
                return $p->id;
            }
        }
        sleep(1);
    }



    private function setLoginSessionVariables($user=NULL,$pass=NULL,$shopifyid=NULL){   
        session(['email' => $user, "password" => $pass, "shopifyid"=>$shopifyid]);
    }

    public function login(Request $request){

        Auth::logout();

        $content = new \stdClass;
        $content->text="Not Authorized";
        $status = 400;

        session()->regenerate();

        if($request->has("email") && $request->has("password")){

            $returning_user = $this->helper->checkUserCredentials($request->input("email"),$request->input("password"));

            if(sizeof($returning_user)>0){
                $this->setLoginSessionVariables($request->input("email"),$request->input("password"),$returning_user[0]->shopifyid);
                $existingUser = $this->helper->getCustomerByID($returning_user[0]->shopifyid);

                if(isset($existingUser->errors)){
                    session()->regenerate();
                    return redirect()->route("home")->withErrors(['message'=>$existingUser->errors]);
                }
                else{
                    Log::info("login 1b");
                    session(['user' => $existingUser]);
                    return redirect()->route("account",['id' => $existingUser->customer->{"id"} ])->with("user",$existingUser);
                }
                
            }
            else{
                Log::info("login2");
                $existing_user = $this->helper->checkForMagentoUser($request->input("email"),$request->input("password"));

                if(sizeof($existing_user)>0){
                    return $this->createCustomer($request,$existing_user[0],true);
                }
                else{
                    return back()->withErrors(['message'=>"User Not Found"]);
                }
            }
        }
        else{
            return back()->withErrors(["message"=>"Not Authorized"]);
            //return response(json_encode($content), $status)->header('Content-Type', 'application/json');    
        }
    }

    public function logout(Request $request){
        Auth::logout();
        $request->session()->flush();
        return redirect()->route("home");
    }

    public function updateUserTags($id=NULL,$redeem_barcode=NULL,$registration = true, $addtag = true){
        $customerURL = $this->admin_url . "/admin/customers/" . $id . ".json";
        $result = $this->helper->createCurlPostRequest($customerURL,NULL,"GET");

        Log::info( $customerURL );
        Log::info(json_encode($result));
        
        $newinfo = new \stdClass;
        $newinfo->customer = new \stdClass;
        $updateuser = false;

        $tagName = ($registration)?"registration":"redeemed";

         if(!is_null($redeem_barcode) && isset($result->customer)){

            if(!preg_match(("/" . $tagName . "-" . $redeem_barcode . "/"),$result->customer->tags)){
                $updateuser = true;

                if($addtag){
                    $newinfo->customer->tags = $result->customer->tags;
                    $newinfo->customer->tags .= ("," . $tagName . "-" . $redeem_barcode . "-" . time());
                    sleep(1);
                    $customerURL = $this->admin_url . "/admin/customers/" . $id . ".json";
                    $result = $this->helper->createCurlPostRequest($customerURL,$newinfo,"PUT");
                }
            }
         }

         return $updateuser;
    }

    public function redeemProduct(Request $request){

        if ($request->isMethod('post')) {
                $lfid = $this->getLostProduct();

                $product = json_decode($request->input("info"));
                $continue = false;

                if(isset($product->variant->id)){
                    $continue = $this->updateUserTags($product->user->id,$product->variant->barcode,false, false);    
                }
                else{
                    $continue = $this->updateUserTags($product->user->id,$product->product->barcode,false, false); 
                }
                
                if($continue){
                    $new_product_variant_url = $this->admin_url . "/admin/products/" . $lfid . "/variants.json";
                    $createVariant = new \stdClass;
                    $createVariant->variant = new \stdClass;
                    $createVariant->variant->price = 0.00;
                    $createVariant->variant->title = $product->variant->title;
                    $createVariant->variant->option1 = ("Product Title=" . $product->product->title . ":Product ID=" . $product->product->id  . ":Variant Title=" . $product->variant->title . ":Variant Id=" . $product->variant->id . ":Variant Barcode=" . $product->variant->barcode . ":uid=" . $product->user->id . ":ukey=" .  uniqid());

                    //create new lost item variant
                    $result = $this->helper->createCurlPostRequest($new_product_variant_url,$createVariant);

                    //create a new product image 
                    $imageVariantUrl = $this->admin_url . "/admin/products/" . $lfid . "/images.json";
                    $imageSrc = $product->product->image;                
            
                    $variantImage = array("image"=> array("variant_ids"=>array($result->variant->id),"src"=>$imageSrc ) );
                    $imageCreationCurl = $this->helper->createCurlPostRequest($imageVariantUrl,$variantImage);

                    $result->variant->image = $imageCreationCurl->image;
                    
                    return response(json_encode($result), "200")->header('Content-Type', 'application/json');
                }
                else{
                    $content = new \stdClass;
                    $content->text="Product Already Redeemed";
                    return response(json_encode($content), "400")->header('Content-Type', 'application/json');  
                }
        }
        else{
            $content = new \stdClass;
            $content->text="Incorrect Form Method Type";
            return response(json_encode($content), "400")->header('Content-Type', 'application/json'); 
        }
            
    }

     public function registerProduct(Request $request){
        if ($request->isMethod('post')) {

                $product = json_decode($request->input("info"));
                $continue = false;

                if(isset($product->variant->id)){
                    $continue = $this->updateUserTags($product->user->id,$product->variant->barcode);    
                }
                else{
                    $continue = $this->updateUserTags($product->user->id,$product->product->barcode); 
                }
                
                if($continue){
                    $content = new \stdClass;
                    $content->text= ($product->product->title );
                    return response(json_encode($content), "200")->header('Content-Type', 'application/json');
                }
                else{
                    $content = new \stdClass;
                    $content->text="Product Already Redeemed";
                    return response(json_encode($content), "400")->header('Content-Type', 'application/json');  
                }
        }
        else{
            $content = new \stdClass;
            $content->text="Incorrect Form Method Type";
            return response(json_encode($content), "400")->header('Content-Type', 'application/json'); 
        }
            
    }

    public function customerRegistrations($id=NULL,Request $request){

        $result = $this->helper->getCustomerByID($id);
        $tags = explode(",",$result->customer->tags);

        $url = $this->admin_url . "/admin/products.json";
        $product = $this->helper->createCurlPostRequest($url,NULL,'GET');

        $registrations = array();
        $content = new \stdClass;

        if(sizeof($tags)>0){
            for($i=0;$i<sizeof($tags); $i++){

                $thetag = trim($tags[$i]);
                
                $matches=NULL;
                $redeemed = ( preg_match("/^redeemed\-/",$thetag) )?true:false;
                $barcodefound = preg_match("/(registration|redeemed)\-([0-9]{1,})\-/",$thetag,$matches);

                if($barcodefound){
                    $count = 0;
                    foreach($product->products as $p){
                        $item = new \stdClass;
                        foreach($p->variants as $v){

                            if($v->barcode === $matches[2]){
                                $item->variant = $v; 
                                $item->product = $product->products[$count];
                                $item->redeemed = $redeemed;
                                $registrations[] = $item;
                            }
                        }
                        $count++;
                    }
                }
            }//end foreach

            if(sizeof($registrations)>0){

                $clean = array();
                foreach($registrations as $r){

                    if(!isset($clean[$r->variant->barcode])){
                        $clean[$r->variant->barcode] = $r;
                    }
                    else{
                     if($clean[$r->variant->barcode]->redeemed==false && $r->redeemed==true){
                        $clean[$r->variant->barcode]==$r;
                     }
                    }
                }

                return response(json_encode($clean), "200")->header('Content-Type', 'application/json');
            }
             else{
                $content->text="No Products Found";
                return response(json_encode($content), "401")->header('Content-Type', 'application/json');
            }
     
        }
        else{
            $content->text="No Registrations Found";
            return response(json_encode($content), "401")->header('Content-Type', 'application/json');
        }
    }

    public function getProductByUPC($barcode,Request $request){

           $url = $this->admin_url . "/admin/products.json";
           $product = $this->helper->createCurlPostRequest($url,NULL,'GET');

            $content = new \stdClass;

            $count = 0;
            foreach($product->products as $p){
                foreach($p->variants as $v){
                    if($v->barcode === $barcode){
                        $content->variant = $v; 
                        $content->product = $product->products[$count];
                    }
                }
            $count++;
            }

            if(isset($content->product) ){
                $content->text="Product Found";
                return response(json_encode($content), "200")->header('Content-Type', 'application/json');
            }
            else{
                $content->text="Product Not Found";
                return response(json_encode($content), "401")->header('Content-Type', 'application/json');
            }

            //return view('test',["data"=>json_encode($data)]);
    }

     public function return200Response(){
            ignore_user_abort(true);
            ob_start();
            $serverProtocole = filter_input(INPUT_SERVER, 'SERVER_PROTOCOL', FILTER_SANITIZE_STRING);
            header($serverProtocole.' 200 OK');
            header('Content-Encoding: none');
            header('Content-Length: '.ob_get_length());
            header('Connection: close');
            ob_end_flush();
            ob_flush();
            flush();
    }

    public function processWebHook(Request $request){
        $this->return200Response();
        $data = NULL;
        $lostitem = false;
        $real_variant_barcode = NULL;

        if ($request->isMethod('post') && $request->input("fulfillment_status")=="fulfilled" ) {

            Log::info(json_encode($request->all()));

            $content = new \stdClass;
            $purchasing_user = NULL;

            if($request->has("line_items")){
                $noteString = "Redeemed Lost and Found Items:\n";
                    
                for($i=0,$y=0;$i<sizeof($request->input('line_items'));$i++,$y++){

                    $title = $request->input('line_items.' . $i . '.title');
                    if(preg_match("/lost and found item/i",$title)){
                        $lostitem = true;

                        $note = explode(":",$request->input('line_items.' . $i . '.variant_title'));
                       
                        foreach($note as $n){
                            if(!preg_match("/(uid|ukey)\=/",$n)){
                                $noteString .= (preg_replace("/\=/",": ",$n) . "\n");
                            }

                            if(preg_match("/(uid)\=/",$n)){
                                $purchasing_user = preg_replace("/uid\=/","",$n);
                                Log::info("Purchasing User "  . $purchasing_user );
                            }

                            if(preg_match("/Variant\sId\=/",$n)){
                                $real_variant_id = preg_replace("/Variant\sId\=/","",$n);
                            }

                            if(preg_match("/Variant\sBarcode\=/i",$n)){
                                $real_variant_barcode = preg_replace("/Variant\sBarcode\=/","",$n);
                                $noteString .= "\n";
                            }
                        }


                        $lost_item_variant_id = $request->input('line_items.' . $i . '.variant_id');

                        //check for previously processed
                        $getWebHookID = $request->input('customer.id') . "-" . $request->input('id') . "-" . $real_variant_id . "-" . $real_variant_barcode;
                        $setWebHookProcessed = DB::table('webhooksProcessed')->where(["order-id-barcode"=>$getWebHookID])->get();

                        if(sizeof($setWebHookProcessed)==0){                    
                            //remove variant from LOST and FOund Product
                            $delete_varian_url =  $this->admin_url . "/admin/products/" . $request->input('line_items.' . $i . '.product_id') . "/variants/" . $lost_item_variant_id . ".json";
                            $variant_delete = $this->helper->createCurlPostRequest($delete_varian_url,NULL,"DELETE");        
                            sleep(1);

                            //update real inventory
                            $update_variant_url = $this->admin_url . "/admin/variants/" . $real_variant_id . ".json";

                            $update_variant = new \stdClass;
                            $update_variant->variant = new \stdClass;
                            $update_variant->variant->id = $real_variant_id;
                            $update_variant->variant->inventory_quantity_adjustment = -($request->input('line_items.' . $i . '.quantity'));
                            $update_variant_request = $this->helper->createCurlPostRequest($update_variant_url,$update_variant,"PUT");

                            Log::info($update_variant_url);
                            Log::info(json_encode($update_variant_request));

                            sleep(1);
                            //Update Order and Add Variant Title To Notes

                            $order_url = $this->admin_url . "/admin/orders/" . $request->input('id') . ".json";
                            $order_object = new \stdClass;
                            $order_object->order = new \stdClass;
                            $order_object->order->id = $request->input('id');
                            $order_object->order->note = $noteString;

                            sleep(1);

                            if(!is_null($purchasing_user)){
                                $order_update = $this->helper->createCurlPostRequest($order_url,$order_object,"PUT");
                                $this->updateUserTags($purchasing_user,$real_variant_barcode,false, true); 
                                $insertStatement = DB::table('webhooksProcessed')->insert(["order-id-barcode" => $getWebHookID]);
                            }
                            else{
                                Log::info("No purchasing User FOund");
                            }
                        }
                        else{
                            Log::info("Already Processed: " +  $getWebHookID);
                            
                        }

                    }//end if item is lost and found

                    if($y>=sizeof($request->input('line_items'))){
                        break;
                    }

                }//end for each

                if($lostitem){
                    $content->text="Successful Order Completed";
                }
                else{
                    $content->text="Processed";
                }
            }
            else{
                $content->text="Request Incomplete";
            }

        }
        else{
            return view('test',["data"=>"Working"]);
        }
    }

     public function getCustomerOrder($email,Request $request){
            //Update Order and Add Variant Title To Notes
                $customer_url = $this->admin_url . "/admin/customers/search.json?query=email:" . $email;
                $customer = $this->helper->createCurlPostRequest($customer_url,NULL,"GET");
                $customer_orders_url = $this->admin_url . "/admin/orders.json?customer_id=" . $customer->customers[0]->id;
                $customer_orders = $this->helper->createCurlPostRequest($customer_orders_url,NULL,"GET");
                return response(json_encode($customer_orders), "200")->header('Content-Type', 'application/json');

     }

     public function sendCustomerInformation($customer_info,Request $request){

        
        $new_customer_url = $this->admin_url . "/admin/customers.json";
        $new_customer_response = $this->helper->createCurlPostRequest($new_customer_url,$customer_info);

        if(isset($new_customer_response->customer->{"id"})){
            $email = $customer_info->customer->email;
            $password = $customer_info->customer->password;
            $shopifyid = $new_customer_response->customer->{"id"};
            $this->setLoginSessionVariables($email,$password,$shopifyid);
        }

        if(isset($new_customer_response->errors)){  
            $requestReturn = new \stdClass;
            $requestReturn->error = $new_customer_response->errors;
            $requestReturn->input = $request->all();

            session()->flash("errors",$requestReturn);
            return redirect()->route("account_issue")->withInput();
        }
        else{
            if(isset($customer_info->newuser)){
                $userStatement = DB::table('users')->where(['email'=>$email,'registration_number'=>$password])->update(['shopifyid' => $new_customer_response->customer->id]);
            }

            $userStatement = DB::table('logins')->insert(['email' => $email, 'password' => $password, "shopifyid"=>$new_customer_response->customer->id]);
            return redirect()->route("account",['id' => $new_customer_response->customer->{"id"} ])->with("user",$new_customer_response); 
        }    
     }

    public function updateCustomerInformation(Request $request){
        $customer = new \stdClass;
        $customer->customer = $request->all();

        $get_addresses = $this->admin_url . "/admin/customers/" .$request->get("id") . "/addresses.json";
        $existing_addresses = $this->helper->createCurlPostRequest($get_addresses,NULL,'GET');

        foreach($customer->customer["addresses"] as $a){

            if(isset($a["default"])){
                 $a["default"] = true;
                 $defaultAddress = new \stdClass;
                 $defaultAddress->customer = $a;
                 $update_default_url = $this->admin_url . "/admin/customers/" .$request->get("id")  . "/addresses/" . $a["id"] . "/default.json";
                 $update_default_response = $this->helper->createCurlPostRequest($update_default_url,$defaultAddress,'PUT');
            }            
            else if(isset($a["delete"])){
                $update_default_url = $this->admin_url . "/admin/customers/" .$request->get("id")  . "/addresses/" . $a["id"] . ".json";
                $update_default_response = $this->helper->createCurlPostRequest($update_default_url,NULL,'DELETE');
            }            
            else{
                $address = new \stdClass;
                $address->address = $a;
                $update_default_url = $this->admin_url . "/admin/customers/" .$request->get("id")  . "/addresses.json";
                $update_default_response = $this->helper->createCurlPostRequest($update_default_url,$address,'POST');
            }
        }

        if(isset($update_default_response->errors)){

            $error_message = $update_default_response->errors;
            
            if(isset($update_default_response->errors->signature) && preg_match("/has\salready\sbeen\staken/i",$update_default_response->errors->signature[0])){
                $error_message = new \stdClass;
                $error_message->Address = array("has already been taken");
            }

            return response(json_encode($error_message), "401")->header('Content-Type', 'application/json');  
        }else{
            $update_customer_url = $this->admin_url . "/admin/customers/" .$request->get("id") . ".json";
            $update_customer_response = $this->helper->createCurlPostRequest($update_customer_url,$customer,'PUT');
            return response(json_encode($this->helper->getCustomerByID($request->get("id") ) ), "200")->header('Content-Type', 'application/json');  
        }

     }

    public function customerCart($id,Request $request){
        $content = new \stdClass;
        $status = 200;

         if ($request->isMethod('post')) {

            Log::info("Reset");
            Log::info($request->input("reset"));

            
            if(is_null( session("cart_url")) || is_null($request->input("reset")) ){
                session(['cart_url' => $request->input("cart_url")]);    
            }
            
            $content->text="Cart URL Saved";
            $content->cart = session("cart_url");
         }
         else{
            if(!is_null( session("cart_url") ) ) {
                $content->cart = session("cart_url");
            }
            else{
                $status = 401;
                $content->text="Cart Not Found";
            }
         }

        return response(json_encode($content), $status)->header('Content-Type', 'application/json');  
    }

     public function createCustomer(Request $request,$userData=NULL,$magento=false){

        $check_for_keys = array("first_name","last_name","email","password","password_confirmation");
        $data_found = 0;
        $content = new \stdClass;

        if($userData){
            Log::info("if1");
            $customer = $this->helper->createCustomerObject($userData,$magento);            
            return $this->sendCustomerInformation($customer,$request);
        }
        else{
            Log::info("else");
            for($i=0;$i<sizeof($check_for_keys);$i++){
                if( $request->has($check_for_keys[$i]) ){
                    $data_found++;
                }
            }

            if($data_found < sizeof($check_for_keys) ){
                Log::info("if2");
                $error = new \stdClass;
                $error->error = array("Incomplete"=>["Please fill out the entire form"]);

                session()->flash("errors",$error);
                return redirect()->route("account_issue")->withInput();
            }
            else{
                Log::info("else 2");
                $newUser = $request->all();
                $customer = $this->helper->createCustomerObject($newUser);
                $customer->newuser = true;

                return $this->sendCustomerInformation($customer,$request);
            }
        }
                
     }

     public function resetLostProduct(){
        $id = $this->getLostProduct();

        if(!is_null($id)){
            //delete the Lost and FOund Item
            $url = $this->admin_url . "/admin/products/" . $id .".json";
            $product = $this->helper->createCurlPostRequest($url,NULL,"DELETE");
            sleep(1);
        }

        $new_item = new \stdClass;
        $new_item->product = new \stdClass;
        $new_item->product->title = "Lost and Found Item";
        $new_item->product->published_scope = "global";
        $new_item->product->publications = array();

        $publication = new \stdClass;
        $publication->published = true;
        $publication->channel_id = "93756490";

        $new_item->product->publications[] = $publication;
 
        $url = $this->admin_url . "/admin/products.json";
        $product = $this->helper->createCurlPostRequest($url,$new_item,"POST");

        Log::info(json_encode($product));
     }

     public function testOutput(){
        $customerURL = $this->admin_url . "/admin/customers/6566500298.json";
        $result = $this->helper->createCurlPostRequest($customerURL,NULL,"GET");
        Log::info(json_encode( $result ));

        //return view('test',[]);
     }


     public function datadump(Request $request){
        $this->return200Response();
        Log::info(json_encode($request->all()));
     }
   
}
?>