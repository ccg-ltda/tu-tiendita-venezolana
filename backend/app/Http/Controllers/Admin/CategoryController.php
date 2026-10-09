<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\MySqlCategoryRepository;
use App\Repositories\MySqlSubcategoryRepository;
use App\Services\AdminAuditService;
use App\Services\PersistenceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController extends Controller
{
    public function index(MySqlCategoryRepository $categories): JsonResponse
    {
        try { return response()->json(['categories' => $categories->all()]); }
        catch (\Throwable) { return response()->json(['message' => 'No fue posible consultar las categorías.'], 503); }
    }
    public function store(Request $request, MySqlCategoryRepository $categories, AdminAuditService $audit): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100']]);
        try { $category = $categories->create($input['name']); }
        catch (PersistenceException $error) { return $this->error($error, 'categoría'); }
        $audit->record($request, 'CREATE', 'CATEGORY', $category['id'], [], $category);
        return response()->json(['category' => $category], 201);
    }
    public function update(int $categoryId, Request $request, MySqlCategoryRepository $categories, AdminAuditService $audit): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100']]); $before = $this->category($categories, $categoryId);
        try { $category = $categories->update($categoryId, $input['name']); }
        catch (PersistenceException $error) { return $this->error($error, 'categoría'); }
        $audit->record($request, 'UPDATE', 'CATEGORY', $categoryId, $before, $category);
        return response()->json(['category' => $category]);
    }
    public function status(int $categoryId, Request $request, MySqlCategoryRepository $categories, AdminAuditService $audit): JsonResponse
    {
        $input = $request->validate(['active' => ['required', 'boolean']]); $before = $this->category($categories, $categoryId);
        try { $category = $categories->setActive($categoryId, (bool) $input['active']); }
        catch (PersistenceException $error) { return $this->error($error, 'categoría'); }
        $audit->record($request, $category['active'] ? 'ACTIVATE' : 'DEACTIVATE', 'CATEGORY', $categoryId, $before, $category);
        return response()->json(['category' => $category]);
    }
    public function storeSubcategory(int $categoryId, Request $request, MySqlSubcategoryRepository $subcategories, AdminAuditService $audit): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100']]);
        try { $subcategory = $subcategories->create($categoryId, $input['name']); }
        catch (PersistenceException $error) { return $this->error($error, 'subcategoría'); }
        $audit->record($request, 'CREATE', 'SUBCATEGORY', $subcategory['id'], [], $subcategory);
        return response()->json(['subcategory' => $subcategory], 201);
    }
    public function updateSubcategory(int $subcategoryId, Request $request, MySqlSubcategoryRepository $subcategories, AdminAuditService $audit): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $before = $this->subcategory($subcategories, $subcategoryId);
        try { $subcategory = $subcategories->update($subcategoryId, $input['name']); }
        catch (PersistenceException $error) { return $this->error($error, 'subcategoría'); }
        $audit->record($request, 'UPDATE', 'SUBCATEGORY', $subcategoryId, $before, $subcategory);
        return response()->json(['subcategory' => $subcategory]);
    }
    public function subcategoryStatus(int $subcategoryId, Request $request, MySqlSubcategoryRepository $subcategories, AdminAuditService $audit): JsonResponse
    {
        $input = $request->validate(['active' => ['required', 'boolean']]);
        $before = $this->subcategory($subcategories, $subcategoryId);
        try { $subcategory = $subcategories->setActive($subcategoryId, (bool) $input['active']); }
        catch (PersistenceException $error) { return $this->error($error, 'subcategoría'); }
        $audit->record($request, $subcategory['active'] ? 'ACTIVATE' : 'DEACTIVATE', 'SUBCATEGORY', $subcategoryId, $before, $subcategory);
        return response()->json(['subcategory' => $subcategory]);
    }
    private function category(MySqlCategoryRepository $categories, int $id): array { foreach ($categories->all() as $category) if ($category['id'] === $id) return $category; return []; }
    private function subcategory(MySqlSubcategoryRepository $subcategories, int $id): array { return $subcategories->findById($id) ?? []; }
    private function error(PersistenceException $error, string $resource): JsonResponse
    {
        $message = match ($error->remoteCode()) {
            'CATEGORY_DUPLICATE' => 'Ya existe una categoría con ese nombre.',
            'VIRTUAL_CATEGORY_RESERVED' => 'Promociones es una agrupación virtual y no puede administrarse como categoría.',
            'SUBCATEGORY_DUPLICATE' => 'Ya existe una subcategoría con ese nombre en esta categoría.',
            'CATEGORY_NOT_FOUND', 'SUBCATEGORY_NOT_FOUND' => 'El registro solicitado ya no existe.',
            default => "No fue posible guardar la {$resource}.",
        };
        return response()->json(['message' => $message, 'code' => $error->remoteCode()], $error->status());
    }
}
