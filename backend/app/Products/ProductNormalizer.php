<?php

namespace App\Products;

final class ProductNormalizer
{
    public static function normalize(mixed $product): ?array
    {
        if (!is_array($product)) return null;
        $id = self::int($product['product_id'] ?? null, 1);
        $price = self::int($product['price_cop'] ?? null, 0);
        $inventory = self::int($product['inventory'] ?? null, 0);
        $revision = self::int($product['revision'] ?? null, 1);
        $active = self::bool($product['active'] ?? null);
        if ($id === null || $price === null || $inventory === null || $revision === null || $active === null) return null;
        foreach (['category'=>100,'subcategory'=>100,'name'=>500,'presentation'=>100] as $field=>$max) {
            if (!is_string($product[$field] ?? null) || ($value=trim($product[$field])) === '' || strlen($value) > $max) return null;
        }
        foreach (['image_path','legacy_img'] as $field) if (($product[$field] ?? null) !== null && !is_string($product[$field])) return null;
        return [
            'product_id'=>$id,'category'=>trim($product['category']),'subcategory'=>trim($product['subcategory']),
            'name'=>trim($product['name']),'presentation'=>trim($product['presentation']),'price_cop'=>$price,
            'inventory'=>$inventory,'active'=>$active,'image_path'=>$product['image_path'] ?? null,
            'legacy_img'=>$product['legacy_img'] ?? null,'created_at'=>isset($product['created_at']) ? (string)$product['created_at'] : null,
            'updated_at'=>isset($product['updated_at']) ? (string)$product['updated_at'] : null,'revision'=>$revision,
        ];
    }

    public static function snapshot(mixed $product): ?array
    {
        $p = self::normalize($product);
        if ($p === null) return null;
        unset($p['created_at'], $p['updated_at']);
        return $p;
    }

    public static function sheetRow(mixed $row): ?array
    {
        if (!is_array($row)) return null;
        $row = array_pad($row, 13, null);
        return self::normalize([
            'product_id'=>$row[0],'category'=>$row[1],'subcategory'=>$row[2],'name'=>$row[3],
            'presentation'=>$row[4],'price_cop'=>$row[5],'inventory'=>$row[6],'active'=>$row[7],
            'image_path'=>$row[8] === '' ? null : $row[8],'legacy_img'=>$row[9] === '' ? null : $row[9],
            'created_at'=>$row[10],'updated_at'=>$row[11],'revision'=>$row[12],
        ]);
    }

    public static function row(array $p): array
    {
        return [$p['product_id'],$p['category'],$p['subcategory'],$p['name'],$p['presentation'],$p['price_cop'],$p['inventory'],$p['active'],$p['image_path'] ?? null,$p['legacy_img'] ?? null,$p['created_at'] ?? '',$p['updated_at'] ?? '',$p['revision']];
    }

    private static function int(mixed $value, int $min): ?int
    { return (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value))) && (int)$value >= $min ? (int)$value : null; }
    private static function bool(mixed $value): ?bool
    { return match ($value) { true, 1, '1', 'TRUE', 'true' => true, false, 0, '0', 'FALSE', 'false' => false, default => null }; }
}
