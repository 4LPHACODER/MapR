<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignatureAssetController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['signature' => $this->current($request->user()->id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $file = $request->file('signature');
        if ($file === null) {
            abort(422, 'Choose a PNG or JPEG electronic-signature image.');
        }

        $path = $file->getRealPath();
        $bytes = $path ? (int) filesize($path) : 0;
        if ($bytes < 1 || $bytes > 2 * 1024 * 1024) {
            abort(422, 'The electronic-signature image must be between 1 byte and 2 MB.');
        }

        $contents = (string) file_get_contents($path);
        if (preg_match('/<\s*(svg|script|html)\b|<\?xml/i', $contents) === 1) {
            abort(422, 'SVG and other non-image files are not accepted.');
        }

        $info = @getimagesize($path);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            abort(422, 'Only a PNG or JPEG electronic-signature image is accepted.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 16 || $height < 16 || $width > 4000 || $height > 4000) {
            abort(422, 'The electronic-signature image must be between 16 and 4000 pixels on each side.');
        }

        $extension = $mime === 'image/png' ? 'png' : 'jpg';
        $stored = 'signature-images/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($stored, $contents);

        $userId = $request->user()->id;
        DB::table('user_signature_assets')->where('user_id', $userId)->where('is_current', true)->update([
            'is_current' => false,
            'updated_at' => now(),
        ]);
        DB::table('user_signature_assets')->insert([
            'user_id' => $userId,
            'storage_path' => $stored,
            'mime_type' => $mime,
            'byte_size' => $bytes,
            'width' => $width,
            'height' => $height,
            'sha256' => hash('sha256', $contents),
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['signature' => $this->current($userId)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $current = DB::table('user_signature_assets')->where('user_id', $userId)->where('is_current', true)->first();
        if ($current) {
            $referenced = Schema::hasColumn('report_signatures', 'user_signature_asset_id')
                && DB::table('report_signatures')->where('user_signature_asset_id', $current->id)->exists();
            DB::table('user_signature_assets')->where('id', $current->id)->update([
                'is_current' => false,
                'updated_at' => now(),
            ]);
            if (! $referenced) {
                Storage::disk('local')->delete($current->storage_path);
            }
        }

        return response()->json(['signature' => $this->current($userId)]);
    }

    public function preview(Request $request): StreamedResponse|Response
    {
        $asset = DB::table('user_signature_assets')
            ->where('user_id', $request->user()->id)
            ->where('is_current', true)
            ->first();
        abort_if($asset === null || ! Storage::disk('local')->exists($asset->storage_path), 404);

        return Storage::disk('local')->response($asset->storage_path, 'electronic-signature.'.$this->extension($asset->mime_type), [
            'Content-Type' => $asset->mime_type,
        ]);
    }

    private function current(int $userId): ?array
    {
        if (! Schema::hasTable('user_signature_assets')) {
            return null;
        }

        $asset = DB::table('user_signature_assets')->where('user_id', $userId)->where('is_current', true)->first();
        if ($asset === null) {
            return null;
        }

        return [
            'id' => $asset->id,
            'width' => $asset->width,
            'height' => $asset->height,
            'label' => 'Electronic-signature image',
            'preview_url' => '/'.request()->segment(1).'/api/signature/preview',
        ];
    }

    private function extension(string $mime): string
    {
        return $mime === 'image/png' ? 'png' : 'jpg';
    }
}
