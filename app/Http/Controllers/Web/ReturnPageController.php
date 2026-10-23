<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CourierReturn;
use Inertia\Inertia;

class ReturnPageController extends Controller {
    public function __invoke() {
        return Inertia::render('Returns/Index', [
            'recent' => CourierReturn::with('shipment:id,sales_order_id,courier,tracking_id')->latest('id')->take(20)->get(),
        ]);
    }
}
