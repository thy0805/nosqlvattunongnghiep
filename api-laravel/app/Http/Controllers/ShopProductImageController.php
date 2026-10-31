<?php

namespace App\Http\Controllers;

use App\Commerce\CommerceStore;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ShopProductImageController extends Controller
{
    public function __construct(private readonly CommerceStore $store) {}

    public static function imagePath(string $chainId, string $key): string
    {
        abort_unless(preg_match('/^[1-9][0-9]*$/D', $chainId) && preg_match('/^[a-f0-9-]{36}\.(jpg|png|webp)$/D', $key), 404);
        return storage_path('app/product-images/'.$chainId.'/'.$key);
    }

    public function create(Request $request, string $chainId)
    {
        $this->store->admin($request, $chainId, null, true);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp']]);
        $file = $request->file('file');
        $size = @getimagesize($file->getRealPath());
        if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] > 8000 || $size[1] > 8000 || $size[0] * $size[1] > 24000000 || ($size['mime'] ?? '') !== $file->getMimeType()) {
            throw ValidationException::withMessages(['file' => 'Ảnh không hợp lệ hoặc vượt giới hạn 24 triệu điểm ảnh / 8000 px mỗi chiều.']);
        }
        $extension = match ($size['mime']) {'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => abort(422)};
        $key = Str::uuid().'.'.$extension;
        $path = self::imagePath($chainId, $key);
        $this->store->admin($request, $chainId, null, true);
        $file->move(dirname($path), $key);
        return response()->json(['data' => ['url' => '/api/shop-product-images/'.$chainId.'/'.$key]], 201);
    }

    public function show(Request $request, string $chainId, string $key)
    {
        $path = self::imagePath($chainId, $key);
        abort_unless(is_file($path), 404);
        $url = '/api/shop-product-images/'.$chainId.'/'.$key;
        if (!$this->store->first('shop_products', ['chain_id' => $chainId, 'published' => true, 'images' => $url])) {
            abort_unless($request->user(), 404);
            $this->store->admin($request, $chainId, null, true);
        }
        return response()->file($path, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
