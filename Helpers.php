<?php

namespace App\Providers;

use Log;
use Illuminate\Support\Facades\DB;

class Helpers{

    private $admin_url;

    function __construct() {
        $this->admin_url = "https://" . config('app.shopify_key') .":" . config('app.shopify_pass') . "@" .config('app.shopify_host');
    }

    public function getCustomerByID($id){
        $customerurl = $this->admin_url . "/admin/customers/" . $id . ".json";
        $customerAddressUrl = $this->admin_url . "/admin/customers/" . $id . "/addresses.json";

        $customer = $this->createCurlPostRequest($customerurl,NULL,'GET');

        if(!isset($customer->errors)){
            sleep(1);
            $customerAddresses = $this->createCurlPostRequest($customerAddressUrl,NULL,'GET');
            $customer->customer->addresses = $customerAddresses->addresses;
            session(['user' => $customer->customer]);
        }
        
        return $customer;

    }

    public function output($object){
        echo "<pre>";
        print_r($object);
        echo "</pre>";
    }

    public function createCustomerObject($data,$checkMagentoData = false){

        if(gettype($data)=='array'){
            $data = json_decode(json_encode($data));
        }

        $new_customer = new \stdClass;
        $new_customer->customer = new \stdClass;
        $new_customer->customer->first_name = $data->first_name;
        $new_customer->customer->last_name = $data->last_name;
        $new_customer->customer->email = $data->email;
        $new_customer->customer->password = (isset($data->password)? $data->password : $data->registration_number);
        $new_customer->customer->password_confirmation = (isset($data->password_confirmation)? $data->password_confirmation : $data->registration_number);
        $new_customer->customer->addresses = array();
        
        if(isset($data->upc)){
            $new_customer->customer->note = "Previously Redeemed:" . $data->upc;    
        }

        $new_customer->customer->addresses[0] = new \stdClass;
        //adds a default address for user
        
        if($checkMagentoData){
            $addressKeys = array();
            $addressKeys["shipping_address"] = "address1";
            $addressKeys["shipping_address2"] = "address2";
            $addressKeys["shipping_city"] = "city";
            $addressKeys["shipping_state"] = "province";
            $addressKeys["shipping_address"] = "address1";
            $addressKeys["phone"] = "phone";
            $addressKeys["shipping_zip_code"] = "zip";
            $addressKeys["shipping_country"] = "country";
            $addressKeys["shipping_first_name"] = "first_name";
            $addressKeys["shipping_last_name"] = "last_name";

            foreach($addressKeys as  $key => $value){

                $checkKey = $key;
                $assignKey= $value;

                //get the form name element from the keys and values
                if(isset($data->{$checkKey})){
                    $new_customer->customer->addresses[0]->{$assignKey} = $data->{$checkKey};    
                }
                else if(isset($data->{$assignKey})){
                    $new_customer->customer->addresses[0]->{$assignKey} = $data->{$assignKey};
                }
                
            }
        }
        else{
            $new_customer->customer->addresses[0] = $data->addresses[0];
        }
        
        // Log::info("createCustomerObject");
        // Log::info(json_encode($new_customer));

        return $new_customer;
    }

    public function checkForMagentoUser($email=NULL,$password=NULL){
        $user = DB::table('users')->where(["email"=>$email,"registration_number"=>$password])->get();

        if(sizeof($user)>0){
            $double_record_check = DB::table('users')->where(["email"=>$email])->get();

            //checks for existing email in multiple records
            if(sizeof($double_record_check)>1){
                $upc_codes = "";
                foreach($double_record_check as $u){
                    $upc_codes .= $u->upc . ":";
                }

                $user[0]->upc = $upc_codes;
            }

            //Log::info($user[0]->upc);
        }
        return $user;
    }

    public function checkUserCredentials($email=NULL,$password=NULL,$shopifyid=NULL){
         return DB::table('logins')->where(["email"=>$email,"password"=>$password])->get();
    }

    public function createCurlPostRequest($url,$postObject=NULL,$postType="POST"){
        $post_options = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'Laravel App',
            CURLOPT_CUSTOMREQUEST => $postType
        );

        if($postObject!=NULL){
            $post_options[CURLOPT_POSTFIELDS] = json_encode($postObject);
            $post_options[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
        }


        $curl = curl_init();
        curl_setopt_array($curl,$post_options);
        $object = curl_exec($curl);
        curl_close($curl);
        return json_decode($object);
    }
}
