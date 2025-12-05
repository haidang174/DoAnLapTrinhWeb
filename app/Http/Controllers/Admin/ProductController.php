<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductImage;
use App\Models\ProductAttribute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'mainImage']);

        // Tìm kiếm
        if ($request->has('search')) {
            $query->where('product_name', 'like', '%' . $request->search . '%');
        }

        // Lọc theo category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->latest()->paginate(20);
        $categories = Category::all();

        return view('admin.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        $categories = Category::all();
        return view('admin.products.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_name' => 'required|string|max:191',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric|min:0',
            
            // Images
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'main_image_index' => 'nullable|integer',
            
            // Attributes
            'attributes.*.size' => 'nullable|string|max:50',
            'attributes.*.color' => 'nullable|string|max:50',
            'attributes.*.price' => 'required|numeric|min:0',
            'attributes.*.quantity' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        
        try {
            // Tạo product
            $product = Product::create([
                'product_name' => $request->product_name,
                'category_id' => $request->category_id,
                'description' => $request->description,
                'base_price' => $request->base_price,
            ]);

            // Upload images
            if ($request->hasFile('images')) {
                $mainImageIndex = $request->main_image_index ?? 0;
                
                foreach ($request->file('images') as $index => $image) {
                    $path = $image->store('products', 'public');
                    
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => $path,
                        'is_main' => $index == $mainImageIndex,
                    ]);
                }
            }

            // Tạo attributes
            if ($request->has('attributes')) {
                foreach ($request->attributes as $attr) {
                    ProductAttribute::create([
                        'product_id' => $product->id,
                        'size' => $attr['size'] ?? null,
                        'color' => $attr['color'] ?? null,
                        'price' => $attr['price'],
                        'quantity' => $attr['quantity'],
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('admin.products.index')
                ->with('success', 'Thêm sản phẩm thành công!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }

    public function show(Product $product)
    {
        $product->load(['category', 'images', 'attributes']);
        return view('admin.products.show', compact('product'));
    }

    public function edit(Product $product)
    {
        $product->load(['images', 'attributes']);
        $categories = Category::all();
        return view('admin.products.edit', compact('product', 'categories'));
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'product_name' => 'required|string|max:191',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric|min:0',
            
            // Images
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'main_image_index' => 'nullable|integer',
            
            // Attributes
            'attributes.*.id' => 'nullable|exists:product_attributes,id',
            'attributes.*.size' => 'nullable|string|max:50',
            'attributes.*.color' => 'nullable|string|max:50',
            'attributes.*.price' => 'required|numeric|min:0',
            'attributes.*.quantity' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        
        try {
            // Cập nhật product
            $product->update([
                'product_name' => $request->product_name,
                'category_id' => $request->category_id,
                'description' => $request->description,
                'base_price' => $request->base_price,
            ]);

            // Xử lý images mới
            if ($request->hasFile('images')) {
                $mainImageIndex = $request->main_image_index ?? 0;
                
                foreach ($request->file('images') as $index => $image) {
                    $path = $image->store('products', 'public');
                    
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => $path,
                        'is_main' => $index == $mainImageIndex,
                    ]);
                }
            }

            // Cập nhật attributes
            if ($request->has('attributes')) {
                $existingIds = [];
                
                foreach ($request->attributes as $attr) {
                    if (isset($attr['id'])) {
                        // Cập nhật existing
                        $attribute = ProductAttribute::find($attr['id']);
                        if ($attribute) {
                            $attribute->update([
                                'size' => $attr['size'] ?? null,
                                'color' => $attr['color'] ?? null,
                                'price' => $attr['price'],
                                'quantity' => $attr['quantity'],
                            ]);
                            $existingIds[] = $attr['id'];
                        }
                    } else {
                        // Tạo mới
                        $newAttr = ProductAttribute::create([
                            'product_id' => $product->id,
                            'size' => $attr['size'] ?? null,
                            'color' => $attr['color'] ?? null,
                            'price' => $attr['price'],
                            'quantity' => $attr['quantity'],
                        ]);
                        $existingIds[] = $newAttr->id;
                    }
                }

                // Xóa các attributes không còn trong request
                ProductAttribute::where('product_id', $product->id)
                    ->whereNotIn('id', $existingIds)
                    ->delete();
            }

            DB::commit();

            return redirect()->route('admin.products.index')
                ->with('success', 'Cập nhật sản phẩm thành công!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }

    public function destroy(Product $product)
    {
        DB::beginTransaction();
        
        try {
            // Xóa images từ storage
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image->image_url);
            }

            // Xóa product (cascade sẽ xóa images, attributes)
            $product->delete();

            DB::commit();

            return redirect()->route('admin.products.index')
                ->with('success', 'Xóa sản phẩm thành công!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }

    // Xóa image riêng lẻ
    public function deleteImage($id)
    {
        $image = ProductImage::findOrFail($id);
        
        // Không cho xóa ảnh main nếu còn ảnh khác
        if ($image->is_main) {
            $otherImages = ProductImage::where('product_id', $image->product_id)
                ->where('id', '!=', $image->id)
                ->count();
                
            if ($otherImages > 0) {
                return back()->with('error', 'Vui lòng chọn ảnh chính khác trước khi xóa ảnh này!');
            }
        }

        Storage::disk('public')->delete($image->image_url);
        $image->delete();

        return back()->with('success', 'Xóa ảnh thành công!');
    }

    // Set main image
    public function setMainImage($id)
    {
        $image = ProductImage::findOrFail($id);
        
        // Bỏ main của tất cả ảnh cùng product
        ProductImage::where('product_id', $image->product_id)
            ->update(['is_main' => false]);
        
        // Set main cho ảnh này
        $image->update(['is_main' => true]);

        return back()->with('success', 'Đã đặt làm ảnh chính!');
    }
}