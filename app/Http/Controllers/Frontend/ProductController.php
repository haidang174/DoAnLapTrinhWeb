<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'mainImage', 'attributes']);

        // Lọc theo category
        if ($request->has('category')) {
            $query->where('category_id', $request->category);
        }

        // Tìm kiếm
        if ($request->has('search')) {
            $query->where('product_name', 'like', '%' . $request->search . '%');
        }

        // Lọc theo giá
        if ($request->has('min_price')) {
            $query->whereHas('attributes', function ($q) use ($request) {
                $q->where('price', '>=', $request->min_price);
            });
        }

        if ($request->has('max_price')) {
            $query->whereHas('attributes', function ($q) use ($request) {
                $q->where('price', '<=', $request->max_price);
            });
        }

        // Sắp xếp
        switch ($request->get('sort', 'latest')) {
            case 'price_asc':
                $query->join('product_attributes', 'products.id', '=', 'product_attributes.product_id')
                    ->orderBy('product_attributes.price', 'asc')
                    ->select('products.*')
                    ->distinct();
                break;
            case 'price_desc':
                $query->join('product_attributes', 'products.id', '=', 'product_attributes.product_id')
                    ->orderBy('product_attributes.price', 'desc')
                    ->select('products.*')
                    ->distinct();
                break;
            case 'name':
                $query->orderBy('product_name', 'asc');
                break;
            default:
                $query->latest();
        }

        $products = $query->paginate(12);
        $categories = Category::withCount('products')->get();

        return view('frontend.products.index', compact('products', 'categories'));
    }

    public function show($id)
    {
        $product = Product::with(['category', 'images', 'attributes'])
            ->findOrFail($id);

        // Sản phẩm liên quan
        $relatedProducts = Product::with(['mainImage', 'attributes'])
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->take(4)
            ->get();

        return view('frontend.products.show', compact('product', 'relatedProducts'));
    }

    public function search(Request $request)
    {
        $keyword = $request->get('q');
        
        $products = Product::with(['category', 'mainImage', 'attributes'])
            ->where('product_name', 'like', '%' . $keyword . '%')
            ->orWhere('description', 'like', '%' . $keyword . '%')
            ->paginate(12);

        return view('frontend.products.search', compact('products', 'keyword'));
    }

    public function category($id)
    {
        $category = Category::findOrFail($id);
        
        $products = Product::with(['mainImage', 'attributes'])
            ->where('category_id', $id)
            ->paginate(12);

        $categories = Category::withCount('products')->get();

        return view('frontend.products.category', compact('category', 'products', 'categories'));
    }
}