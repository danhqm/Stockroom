<?php

namespace App\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogueController extends Controller
{
    public function index(string $catalogue): JsonResponse
    {
        $page = DB::table($catalogue)->orderBy('id')->paginate(25);

        return response()->json($page);
    }

    public function store(Request $request, string $catalogue): JsonResponse
    {
        $data = $this->validated($request, $catalogue);
        try {
            $id = DB::table($catalogue)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'This SKU already exists.']);
        }

        return response()->json(['data' => DB::table($catalogue)->find($id)], 201);
    }

    public function update(Request $request, string $catalogue, int $id): JsonResponse
    {
        abort_unless(DB::table($catalogue)->where('id', $id)->exists(), 404);
        $data = $this->validated($request, $catalogue);
        try {
            DB::table($catalogue)->where('id', $id)->update([...$data, 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'This SKU already exists.']);
        }

        return response()->json(['data' => DB::table($catalogue)->find($id)]);
    }

    private function validated(Request $request, string $catalogue): array
    {
        $rules = ['name' => 'required|string|max:160'];
        if ($catalogue === 'products') {
            $rules += ['sku' => 'required|string|max:64', 'archived' => 'sometimes|boolean'];
        }

        return $request->validate($rules);
    }
}
