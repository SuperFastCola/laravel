<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

//gets user session and passes that to view
View::composer('*', function($view)
{

  if(isset(session('user')->customer)){
    $customer = session('user')->customer;  
  }
  else{
    $customer = session('user');
  }

  if(is_null($customer)){
  	$customer = NULL;
  }

  $view->with('customer', $customer);
});


Route::get('/',['as' => 'home', function(){
	return view('home');
}]);

Route::post('login', 'Controller@login');
Route::get('logout', 'Controller@logout');

Route::get('products', 'Controller@getOrderedProducts');

Route::post('redeem/{id}/', 'Controller@redeemProduct');

Route::post('register/{id}/', 'Controller@registerProduct');

Route::get('upc/{upc}', 'Controller@getProductByUPC');


Route::get('orders/{email}', 'Controller@getCustomerOrder');

Route::get('account/create',["as"=>"account_issue",function(){
	return view('customerCreate');
}]);

Route::post('account/create',["as"=>"account","uses"=>"Controller@createCustomer"]);

Route::get('customer/account/{id}', ["as"=>"account",function($id){
	return view('customerAccount');
}])->middleware('checkLogin');

Route::get('customer/registrations/{id}', ["as"=>"registration",function($id){
	return view('customerRegistrations');
}])->middleware('checkLogin');

Route::get('customer/registrations/{id}/list','Controller@customerRegistrations')->middleware('checkLogin');

Route::get('babiators/register', ["as"=>"register",function(){
	return view('registerNewProduct');
}])->middleware('checkLogin');

Route::post('customer/update',"Controller@updateCustomerInformation")->middleware('checkLogin');

Route::get('customer/{id}/carturl',"Controller@customerCart")->middleware('checkLogin');
Route::post('customer/{id}/carturl',"Controller@customerCart")->middleware('checkLogin');


Route::get('fulfillment','Controller@processWebHook');
Route::post('fulfillment','Controller@processWebHook');
Route::get('customer/cart/{id}', function(){
	return view('viewCart');
})->middleware('checkLogin');

Route::post('datadump','Controller@datadump');

Route::get('test', "Controller@testOutput");
