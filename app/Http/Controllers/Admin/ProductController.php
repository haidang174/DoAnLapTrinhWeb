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
        // Validate dữ liệu
        $validated = $request->validate([
            'product_name' => 'required|string|max:191',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric|min:0',
            'images.*' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'attributes' => 'required|array|min:1',
            'attributes.*.price' => 'required|numeric|min:0',
            'attributes.*.quantity' => 'required|integer|min:0',
            'attributes.*.size' => 'nullable|string|max:50',
            'attributes.*.color' => 'nullable|string|max:50',
        ]);

        try {
            // Bắt đầu transaction
            DB::beginTransaction();

            // Tạo sản phẩm
            $product = Product::create([
                'product_name' => $validated['product_name'],
                'category_id' => $validated['category_id'],
                'description' => $validated['description'] ?? null,
                'base_price' => $validated['base_price'],
            ]);

            // Xử lý upload ảnh
            if ($request->hasFile('images')) {
                $mainImageIndex = $request->input('main_image_index', 0);
                
                foreach ($request->file('images') as $index => $image) {
                    // Lưu file vào storage/app/public/products
                    $path = $image->store('products', 'public');
                    
                    // Lưu vào database - SỬA TỪ image_path THÀNH image_url
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => $path,  // ← ĐÃ SỬA
                        'is_main' => ($index == $mainImageIndex) ? 1 : 0,
                    ]);
                }
            }

            // Lưu các phân loại (attributes/variants)
            if (!empty($validated['attributes'])) {
                foreach ($validated['attributes'] as $attribute) {
                    // Chỉ lưu nếu có đủ giá và số lượng
                    if (isset($attribute['price']) && isset($attribute['quantity'])) {
                        ProductAttribute::create([
                            'product_id' => $product->id,
                            'size' => !empty($attribute['size']) ? $attribute['size'] : null,
                            'color' => !empty($attribute['color']) ? $attribute['color'] : null,
                            'price' => $attribute['price'],
                            'quantity' => $attribute['quantity'],
                        ]);
                    }
                }
            }

            // Commit transaction
            DB::commit();

            return redirect()->route('admin.products.index')
                ->with('success', 'Sản phẩm đã được thêm thành công!');

        } catch (\Exception $e) {
            // Rollback nếu có lỗi
            DB::rollBack();
            
            return redirect()->back()
                ->withInput()
                ->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
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

    public function update(Request $request, $id)
    {
        $request->validate([
            'product_name' => 'required|string|max:191',
            'category_id' => 'required|exists:categories,id',
            'base_price' => 'required|numeric|min:0',
            'attributes' => 'required|array|min:1',
            'attributes.*.price' => 'required|numeric|min:0',
            'attributes.*.quantity' => 'required|integer|min:0',
        ]);
        
        $product = Product::findOrFail($id);
        
        // Update basic info
        $product->update([
            'product_name' => $request->product_name,
            'category_id' => $request->category_id,
            'description' => $request->description,
            'base_price' => $request->base_price,
        ]);
        
        // Handle attributes
        $existingAttributeIds = [];
        
        foreach ($request->attributes as $attrData) {
            if (isset($attrData['_destroy']) && $attrData['_destroy'] == '1') {
                // Xóa attribute
                if (isset($attrData['id'])) {
                    ProductAttribute::where('id', $attrData['id'])
                        ->where('product_id', $product->id)
                        ->delete();
                }
                continue;
            }
            
            if (isset($attrData['id']) && $attrData['id']) {
                // Update attribute cũ
                ProductAttribute::where('id', $attrData['id'])
                    ->where('product_id', $product->id)
                    ->update([
                        'size' => $attrData['size'] ?? null,
                        'color' => $attrData['color'] ?? null,
                        'price' => $attrData['price'],
                        'quantity' => $attrData['quantity'],
                    ]);
                $existingAttributeIds[] = $attrData['id'];
            } else {
                // Tạo attribute mới
                $newAttr = ProductAttribute::create([
                    'product_id' => $product->id,
                    'size' => $attrData['size'] ?? null,
                    'color' => $attrData['color'] ?? null,
                    'price' => $attrData['price'],
                    'quantity' => $attrData['quantity'],
                ]);
                $existingAttributeIds[] = $newAttr->id;
            }
        }
        
        // Handle images upload
        if ($request->hasFile('images')) {
            $currentImageCount = $product->images()->count();
            $newImageCount = count($request->file('images'));
            
            if ($currentImageCount + $newImageCount > 5) {
                return back()->withErrors(['images' => 'Tổng số ảnh không được vượt quá 5!']);
            }
            
            $isFirstImage = $currentImageCount == 0;
            
            foreach ($request->file('images') as $index => $image) {
                $path = $image->store('products', 'public');
                
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_url' => $path,
                    'is_main' => $isFirstImage && $index == 0,
                ]);
            }
        }
        
        return redirect()->route('admin.products.index')
            ->with('success', 'Cập nhật sản phẩm thành công!');
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
    public function deleteImage($imageId)
    {
        $image = ProductImage::findOrFail($imageId);
        $productId = $image->product_id;
        
        // Delete file from storage
        Storage::disk('public')->delete($image->image_url);
        
        // Delete from database
        $image->delete();
        
        // If deleted image was main, set first image as main
        $product = Product::find($productId);
        if (!$product->images()->where('is_main', true)->exists()) {
            $firstImage = $product->images()->first();
            if ($firstImage) {
                $firstImage->update(['is_main' => true]);
            }
        }
        
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