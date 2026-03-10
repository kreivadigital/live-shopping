<?php

namespace LiveProConnector\Support;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variation;

final class ProductDataResolver
{
    public function resolve(int $productId, int $variationId = 0): ?array
    {
        if (!function_exists('wc_get_product') || $productId <= 0) {
            return null;
        }

        $baseProduct = wc_get_product($productId);
        if (!$baseProduct instanceof WC_Product) {
            return null;
        }

        $variationProduct = $variationId > 0 ? wc_get_product($variationId) : null;
        if ($variationId > 0 && !$variationProduct instanceof WC_Product) {
            $variationProduct = null;
        }

        $contextProduct = $variationProduct instanceof WC_Product ? $variationProduct : $baseProduct;
        $gallery = $this->collectGallery($baseProduct, $variationProduct);
        $parentAttributes = $this->extractAttributeGroups($baseProduct);
        $variationAttributes = $variationProduct instanceof WC_Product_Variation
            ? $this->extractVariationAttributeGroups($variationProduct)
            : [];

        $colors = $this->dedupeValues(array_merge(
            $this->matchAttributeValues($parentAttributes, ['color', 'colour', 'colores']),
            $this->matchAttributeValues($variationAttributes, ['color', 'colour', 'colores'])
        ));

        $sizes = $this->dedupeValues(array_merge(
            $this->matchAttributeValues($parentAttributes, ['size', 'sizes', 'talle', 'talles', 'tamano', 'tamaño']),
            $this->matchAttributeValues($variationAttributes, ['size', 'sizes', 'talle', 'talles', 'tamano', 'tamaño'])
        ));

        return [
            'product_id' => $productId,
            'variation_id' => $variationId > 0 ? $variationId : null,
            'name' => $contextProduct->get_name(),
            'price' => html_entity_decode(wp_strip_all_tags((string) $contextProduct->get_price_html()), ENT_QUOTES, 'UTF-8'),
            'image' => $gallery[0] ?? '',
            'gallery' => $gallery,
            'colors' => $colors,
            'sizes' => $sizes,
        ];
    }

    private function collectGallery(WC_Product $baseProduct, ?WC_Product $variationProduct): array
    {
        $urls = [];

        if ($variationProduct instanceof WC_Product) {
            $variationImageId = $variationProduct->get_image_id();
            if ($variationImageId > 0) {
                $url = wp_get_attachment_image_url($variationImageId, 'large');
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        foreach ([$baseProduct, $variationProduct] as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }

            $imageId = $product->get_image_id();
            if ($imageId > 0) {
                $url = wp_get_attachment_image_url($imageId, 'large');
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }

            foreach ($product->get_gallery_image_ids() as $galleryId) {
                $url = wp_get_attachment_image_url((int) $galleryId, 'large');
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        return $this->dedupeValues($urls);
    }

    /**
     * @return array<string, array{name: string, values: string[]}>
     */
    private function extractAttributeGroups(WC_Product $product): array
    {
        $groups = [];

        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute) {
                continue;
            }

            $name = (string) $attribute->get_name();
            $values = [];

            if ($attribute->is_taxonomy()) {
                $values = wc_get_product_terms($product->get_id(), $name, ['fields' => 'names']);
            } else {
                $values = array_map('strval', $attribute->get_options());
            }

            $groups[$this->normalizeKey($name)] = [
                'name' => wc_attribute_label($name),
                'values' => $this->dedupeValues($values),
            ];
        }

        return $groups;
    }

    /**
     * @return array<string, array{name: string, values: string[]}>
     */
    private function extractVariationAttributeGroups(WC_Product_Variation $product): array
    {
        $groups = [];

        foreach ($product->get_attributes() as $taxonomy => $value) {
            $attributeName = str_starts_with((string) $taxonomy, 'attribute_')
                ? substr((string) $taxonomy, 10)
                : (string) $taxonomy;

            $label = wc_attribute_label($attributeName, $product);
            $values = [];
            $normalizedValue = is_string($value) ? trim($value) : '';

            if ($normalizedValue !== '') {
                $resolved = $this->resolveVariationValue($attributeName, $normalizedValue);
                $values[] = $resolved;
            }

            $groups[$this->normalizeKey($attributeName)] = [
                'name' => $label !== '' ? $label : (string) $attributeName,
                'values' => $this->dedupeValues($values),
            ];
        }

        return $groups;
    }

    /**
     * @param array<string, array{name: string, values: string[]}> $groups
     * @param string[] $candidates
     * @return string[]
     */
    private function matchAttributeValues(array $groups, array $candidates): array
    {
        $normalizedCandidates = array_map([$this, 'normalizeKey'], $candidates);
        $values = [];

        foreach ($groups as $slug => $group) {
            if (!in_array($slug, $normalizedCandidates, true) && !in_array($this->normalizeKey($group['name']), $normalizedCandidates, true)) {
                continue;
            }

            $values = array_merge($values, $group['values']);
        }

        return $this->dedupeValues($values);
    }

    private function resolveVariationValue(string $attributeName, string $value): string
    {
        if (taxonomy_exists($attributeName)) {
            $term = get_term_by('slug', $value, $attributeName);
            if ($term && !is_wp_error($term)) {
                return (string) $term->name;
            }
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $values
     * @return string[]
     */
    private function dedupeValues(array $values): array
    {
        $seen = [];
        $unique = [];

        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[] = $normalized;
        }

        return $unique;
    }

    private function normalizeKey(string $value): string
    {
        $normalized = remove_accents($value);
        $normalized = strtolower(trim($normalized));
        return preg_replace('/\s+/', '_', $normalized) ?? $normalized;
    }
}
