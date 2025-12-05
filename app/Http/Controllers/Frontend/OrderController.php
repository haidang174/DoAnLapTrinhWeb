<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $orders = Order::where('user_id', Auth::id())
            ->with(['orderDetails'])
            ->latest()
            ->paginate(10);

        return view('frontend.orders.index', compact('orders'));
    }

    public function show($id)
    {
        $order = Order::where('user_id', Auth::id())
            ->with(['orderDetails.productAttribute', 'coupon', 'paymentTransactions'])
            ->findOrFail($id);

        return view('frontend.orders.show', compact('order'));
    }

    public function success($id)
    {
        $order = Order::where('user_id', Auth::id())
            ->with(['orderDetails'])
            ->findOrFail($id);

        return view('frontend.orders.success', compact('order'));
    }

    public function cancel($id)
    {
        $order = Order::where('user_id', Auth::id())->findOrFail($id);

        if (!$order->canBeCancelled()) {
            return back()->with('error', 'Không thể hủy đơn hàng này!');
        }

        // Hoàn lại tồn kho
        foreach ($order->orderDetails as $detail) {
            $detail->productAttribute->increaseStock($detail->quantity);
        }

        $order->updateStatus('cancelled');

        return back()->with('success', 'Đã hủy đơn hàng thành công!');
    }

    public function track(Request $request)
    {
        $request->validate([
            'order_code' => 'required|string',
        ]);

        $order = Order::where('order_code', $request->order_code)->first();

        if (!$order) {
            return back()->with('error', 'Không tìm thấy đơn hàng!');
        }

        return view('frontend.orders.track', compact('order'));
    }
}