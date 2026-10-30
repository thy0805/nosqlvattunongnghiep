<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMongoModule
{
    public function handle(Request $request, Closure $next): Response
    {
        $controller = $request->route()->getControllerClass();
        if (!in_array($controller, [\App\Http\Controllers\ProfileController::class,
            \App\Http\Controllers\UserManagementController::class, \App\Http\Controllers\OrganizationController::class,
            \App\Http\Controllers\CatalogController::class, \App\Http\Controllers\CatalogWriteController::class,
            \App\Http\Controllers\ProductContentController::class, \App\Http\Controllers\CustomerController::class,
            \App\Http\Controllers\SupplierController::class, \App\Http\Controllers\PurchaseReceiptController::class,
            \App\Http\Controllers\InventoryController::class, \App\Http\Controllers\SalesOrderController::class,
            \App\Http\Controllers\DebtController::class, \App\Http\Controllers\StockTransferController::class, \App\Http\Controllers\OutboxController::class, \App\Http\Controllers\InventoryAlertController::class, \App\Http\Controllers\NotificationController::class, \App\Http\Controllers\SalesReportController::class], true)) {
            return response()->json(['message' => 'Chức năng này chưa sẵn sàng trong bản NoSQL.'], 503);
        }
        return $next($request);
    }
}
