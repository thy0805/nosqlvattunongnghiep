<?php

use App\Http\Controllers\{ShopController,ShopAdminController};
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::get('api/shop-media/{id}',[\App\Http\Controllers\ShopUploadController::class,'show'])->whereNumber('id');

Route::prefix('api/shop')->group(function():void {
    Route::get('chains',[ShopController::class,'chains']);
    foreach(['register','login','forgot','reset'] as $action) Route::post('account/'.$action,[ShopController::class,'account'])->defaults('action',$action)->middleware('throttle:5,1');
    foreach(['logout','password'] as $action) Route::post('account/'.$action,[ShopController::class,'account'])->defaults('action',$action)->middleware('throttle:20,1');
    Route::match(['get','patch'],'account/profile',[ShopController::class,'account'])->defaults('action','profile');
    Route::match(['get','post'],'account/addresses',[ShopController::class,'addresses']);
    Route::match(['patch','delete'],'account/addresses/{id}',[ShopController::class,'addresses'])->whereNumber('id');
    Route::prefix('{chainId}')->whereNumber('chainId')->group(function():void {
        Route::get('home',[ShopController::class,'home']);
        Route::get('products',[ShopController::class,'products']);
        Route::get('products/{slug}',[ShopController::class,'product']);
        Route::match(['get','put'],'cart',[ShopController::class,'cart']);
        Route::match(['get','post','delete'],'wishlist',[ShopController::class,'wishlist']);
        Route::get('pages/{slug}',[ShopController::class,'page']);
        Route::get('assistant',[ShopController::class,'assistant']);
    });
});
Route::prefix('api/shop-admin')->middleware(['auth',EnsureActiveUser::class])->group(function():void {
    Route::get('scope',[ShopAdminController::class,'scope']);
    Route::prefix('{chainId}')->whereNumber('chainId')->group(function():void {
        Route::match(['get','put'],'settings',[ShopAdminController::class,'settings']);
        Route::get('products',[ShopAdminController::class,'products']);
        Route::put('products/{productId}',[ShopAdminController::class,'products'])->whereNumber('productId');
        Route::match(['get','post'],'content/{kind}',[ShopAdminController::class,'content'])->whereIn('kind',['pages','banners']);
        Route::put('content/{kind}/{id}',[ShopAdminController::class,'content'])->whereIn('kind',['pages','banners'])->whereNumber('id');
    });
});

Route::prefix('api/shop/{chainId}')->whereNumber('chainId')->group(function():void {
    Route::post('uploads',[\App\Http\Controllers\ShopUploadController::class,'create'])->middleware('throttle:30,1');
    Route::match(['get','post'],'business/profile',[\App\Http\Controllers\ShopBusinessController::class,'dealer']);
    Route::match(['get','post'],'business/quotes',[\App\Http\Controllers\ShopBusinessController::class,'quotes']);
    Route::post('business/quotes/{id}/decision',[\App\Http\Controllers\ShopBusinessController::class,'decision'])->whereNumber('id');
    Route::match(['get','post'],'business/recurring',[\App\Http\Controllers\ShopBusinessController::class,'schedules']);
    Route::put('business/recurring/{id}',[\App\Http\Controllers\ShopBusinessController::class,'schedules'])->whereNumber('id');
    Route::post('business/recurring/{id}/generate',[\App\Http\Controllers\ShopBusinessController::class,'generate'])->whereNumber('id');
    Route::get('offers',[\App\Http\Controllers\ShopMarketingController::class,'wallet']);
    Route::post('offers/{id}/claim',[\App\Http\Controllers\ShopMarketingController::class,'wallet'])->whereNumber('id');
    Route::post('checkout/quote',[\App\Http\Controllers\ShopOrderController::class,'quote']);
    Route::get('orders',[\App\Http\Controllers\ShopOrderController::class,'index']);
    Route::post('orders',[\App\Http\Controllers\ShopOrderController::class,'create'])->middleware('throttle:20,1');
    Route::get('orders/{id}',[\App\Http\Controllers\ShopOrderController::class,'show'])->whereNumber('id');
    Route::get('orders/{id}/reorder',[\App\Http\Controllers\ShopOrderController::class,'reorder'])->whereNumber('id');
    Route::post('orders/{id}/cancellation-requests',[\App\Http\Controllers\ShopOrderController::class,'cancellation'])->whereNumber('id');
    Route::post('orders/{id}/cancel',[\App\Http\Controllers\ShopOrderController::class,'cancel'])->whereNumber('id');
    Route::match(['get','post'],'tickets',[\App\Http\Controllers\ShopCareController::class,'tickets'])->middleware('throttle:30,1');
    Route::get('tickets/{id}',[\App\Http\Controllers\ShopCareController::class,'detail'])->whereNumber('id');
    Route::post('tickets/{id}/messages',[\App\Http\Controllers\ShopCareController::class,'message'])->whereNumber('id')->middleware('throttle:30,1');
    Route::post('tickets/{id}/state',[\App\Http\Controllers\ShopCareController::class,'state'])->whereNumber('id');
    Route::get('notifications',[\App\Http\Controllers\ShopCareController::class,'notifications']);
    Route::post('notifications/{id}/read',[\App\Http\Controllers\ShopCareController::class,'notifications'])->whereNumber('id');
    Route::get('products/{productId}/reviews',[\App\Http\Controllers\ShopCareController::class,'reviews'])->whereNumber('productId');
    Route::put('products/{productId}/reviews/{id}',[\App\Http\Controllers\ShopCareController::class,'editReview'])->whereNumber('productId')->whereNumber('id')->middleware('throttle:20,1');
    Route::post('products/{productId}/reviews',[\App\Http\Controllers\ShopCareController::class,'review'])->whereNumber('productId')->middleware('throttle:20,1');
    Route::post('products/{productId}/questions',[\App\Http\Controllers\ShopCareController::class,'question'])->whereNumber('productId')->middleware('throttle:20,1');
    Route::post('returns/{id}/replacement/accept',[\App\Http\Controllers\ShopReturnController::class,'acceptReplacement'])->whereNumber('id');
    Route::get('returns',[\App\Http\Controllers\ShopReturnController::class,'listing']);
    Route::post('returns',[\App\Http\Controllers\ShopReturnController::class,'create'])->middleware('throttle:20,1');
});
Route::prefix('api/shop-admin/{chainId}')->whereNumber('chainId')->middleware(['auth',EnsureActiveUser::class])->group(function():void {
    Route::get('reports',[\App\Http\Controllers\ShopReportController::class,'index']);
    Route::get('marketing/customers',[\App\Http\Controllers\ShopMarketingController::class,'customers']);
    Route::put('marketing/customers/{id}',[\App\Http\Controllers\ShopMarketingController::class,'customers'])->whereNumber('id');
    Route::get('business/dealers',[\App\Http\Controllers\ShopBusinessController::class,'dealers']);
    Route::post('business/dealers/{id}/decision',[\App\Http\Controllers\ShopBusinessController::class,'dealers'])->whereNumber('id');
    Route::put('business/dealers/{id}/credit',[\App\Http\Controllers\ShopBusinessController::class,'policy'])->whereNumber('id');
    Route::get('business/prices',[\App\Http\Controllers\ShopBusinessController::class,'prices']);
    Route::put('business/prices/{productId}',[\App\Http\Controllers\ShopBusinessController::class,'prices'])->whereNumber('productId');
    Route::get('business/quotes',[\App\Http\Controllers\ShopBusinessController::class,'quotes']);
    Route::post('business/quotes/{id}/offer',[\App\Http\Controllers\ShopBusinessController::class,'offer'])->whereNumber('id');
    Route::match(['get','post'],'marketing/{kind}',[\App\Http\Controllers\ShopMarketingController::class,'programs'])->whereIn('kind',['campaigns','vouchers']);
    Route::put('marketing/{kind}/{id}',[\App\Http\Controllers\ShopMarketingController::class,'programs'])->whereIn('kind',['campaigns','vouchers'])->whereNumber('id');
    Route::post('marketing/{kind}/{id}/status',[\App\Http\Controllers\ShopMarketingController::class,'status'])->whereIn('kind',['campaigns','vouchers'])->whereNumber('id');
    Route::post('cancellation-requests/{id}/decision',[\App\Http\Controllers\ShopOrderController::class,'cancellationDecision'])->whereNumber('id');
    Route::get('orders',[\App\Http\Controllers\ShopOrderController::class,'adminIndex']);
    Route::get('orders/{id}/picking',[\App\Http\Controllers\ShopOrderController::class,'picking'])->whereNumber('id');
    Route::put('orders/{id}/picking',[\App\Http\Controllers\ShopOrderController::class,'reallocate'])->whereNumber('id');
    Route::post('orders/{id}/actions',[\App\Http\Controllers\ShopOrderController::class,'adminAction'])->whereNumber('id');
    Route::put('orders/{id}/shipment',[\App\Http\Controllers\ShopFulfillmentController::class,'shipment'])->whereNumber('id');
    Route::post('orders/{id}/shipment',[\App\Http\Controllers\ShopFulfillmentController::class,'transition'])->whereNumber('id');
    Route::post('orders/{id}/payments',[\App\Http\Controllers\ShopFulfillmentController::class,'payment'])->whereNumber('id');
    Route::post('orders/{id}/complete',[\App\Http\Controllers\ShopFulfillmentController::class,'complete'])->whereNumber('id');
    Route::get('tickets',[\App\Http\Controllers\ShopCareController::class,'tickets']);
    Route::get('tickets/{id}',[\App\Http\Controllers\ShopCareController::class,'detail'])->whereNumber('id');
    Route::post('tickets/{id}/messages',[\App\Http\Controllers\ShopCareController::class,'message'])->whereNumber('id');
    Route::post('tickets/{id}/state',[\App\Http\Controllers\ShopCareController::class,'state'])->whereNumber('id');
    Route::get('moderation/review-summary',[\App\Http\Controllers\ShopCareController::class,'reviewSummary']);
    Route::get('moderation/{kind}',[\App\Http\Controllers\ShopCareController::class,'moderation'])->whereIn('kind',['reviews','questions']);
    Route::put('moderation/{kind}/{id}',[\App\Http\Controllers\ShopCareController::class,'moderation'])->whereIn('kind',['reviews','questions'])->whereNumber('id');
    Route::get('returns',[\App\Http\Controllers\ShopReturnController::class,'listing']);
    Route::post('returns/{id}/replacement',[\App\Http\Controllers\ShopReturnController::class,'replacement'])->whereNumber('id');
    Route::post('returns/{id}/replacement/handover',[\App\Http\Controllers\ShopReturnController::class,'handoverReplacement'])->whereNumber('id');
    Route::post('returns/{id}/decision',[\App\Http\Controllers\ShopReturnController::class,'decide'])->whereNumber('id');
    Route::post('returns/{id}/receive',[\App\Http\Controllers\ShopReturnController::class,'receive'])->whereNumber('id');
    Route::post('returns/{id}/refunds',[\App\Http\Controllers\ShopReturnController::class,'refund'])->whereNumber('id');
});
