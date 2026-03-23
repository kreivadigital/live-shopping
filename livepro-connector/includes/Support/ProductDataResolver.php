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
        $variationMatrix = $this->collectVariationMatrix($baseProduct, $gallery);

        $colors = $this->dedupeValues(array_merge(
            $this->matchAttributeValues($parentAttributes, ['color', 'colour', 'colores']),
            $this->matchAttributeValues($variationAttributes, ['color', 'colour', 'colores'])
        ));
        $colorOptions = $this->collectColorOptions($baseProduct, $colors);

        $sizes = $this->dedupeValues(array_merge(
            $this->matchAttributeValues($parentAttributes, ['size', 'sizes', 'talle', 'talles', 'tamano', 'tamaño']),
            $this->matchAttributeValues($variationAttributes, ['size', 'sizes', 'talle', 'talles', 'tamano', 'tamaño'])
        ));

        return [
            'product_id' => $productId,
            'variation_id' => $variationId > 0 ? $variationId : null,
            'name' => $contextProduct->get_name(),
            'price' => $this->resolveDisplayPrice($contextProduct),
            'image' => $gallery[0] ?? '',
            'gallery' => $gallery,
            'colors' => $colors,
            'color_option_details' => $colorOptions,
            'sizes' => $sizes,
            'variation_matrix' => $variationMatrix,
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

        $products = [$baseProduct];
        if ($variationProduct instanceof WC_Product) {
            $products[] = $variationProduct;
        }

        foreach ($baseProduct->get_children() as $childVariationId) {
            $childProduct = wc_get_product((int) $childVariationId);
            if ($childProduct instanceof WC_Product) {
                $products[] = $childProduct;
            }
        }

        foreach ($products as $product) {
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
     * @param string[] $gallery
     * @return array<int, array{
     *   variation_id: int,
     *   color: string,
     *   size: string,
     *   image: string,
     *   price: string,
     *   in_stock: bool,
     *   purchasable: bool,
     *   image_gallery_index: int
     * }>
     */
    private function collectVariationMatrix(WC_Product $baseProduct, array $gallery): array
    {
        $matrix = [];

        foreach ($baseProduct->get_children() as $variationId) {
            $variation = wc_get_product((int) $variationId);
            if (!$variation instanceof WC_Product_Variation) {
                continue;
            }

            $variationAttributes = $this->extractVariationAttributeGroups($variation);
            $matchedColors = $this->matchAttributeValues($variationAttributes, ['color', 'colour', 'colores']);
            $matchedSizes = $this->matchAttributeValues($variationAttributes, ['size', 'sizes', 'talle', 'talles', 'tamano', 'tamaño']);

            $color = isset($matchedColors[0]) ? (string) $matchedColors[0] : '';
            $size = isset($matchedSizes[0]) ? (string) $matchedSizes[0] : '';

            $image = '';
            $imageId = $variation->get_image_id();
            if ($imageId > 0) {
                $image = (string) wp_get_attachment_image_url($imageId, 'large');
            }

            if ($image === '') {
                $imageId = $baseProduct->get_image_id();
                if ($imageId > 0) {
                    $image = (string) wp_get_attachment_image_url($imageId, 'large');
                }
            }

            $matrix[] = [
                'variation_id' => (int) $variation->get_id(),
                'color' => $color,
                'size' => $size,
                'image' => $image,
                'price' => $this->resolveDisplayPrice($variation),
                'in_stock' => $variation->is_in_stock(),
                'purchasable' => $variation->is_purchasable(),
                'image_gallery_index' => $this->findGalleryIndex($gallery, $image),
            ];
        }

        return $matrix;
    }

    /**
     * @param string[] $knownColors
     * @return array<int, array{label: string, image: string, variation_id: ?int, disabled: bool}>
     */
    private function collectColorOptions(WC_Product $baseProduct, array $knownColors): array
    {
        $ordered = [];
        $indexByKey = [];

        foreach ($knownColors as $color) {
            $normalized = trim((string) $color);
            if ($normalized === '') {
                continue;
            }

            $key = $this->normalizeKey($normalized);
            $indexByKey[$key] = count($ordered);
            $ordered[] = [
                'label' => $normalized,
                'image' => '',
                'variation_id' => null,
                'disabled' => true,
            ];
        }

        foreach ($baseProduct->get_children() as $variationId) {
            $variation = wc_get_product((int) $variationId);
            if (!$variation instanceof WC_Product_Variation) {
                continue;
            }

            $variationAttributes = $this->extractVariationAttributeGroups($variation);
            $matchedColors = $this->matchAttributeValues($variationAttributes, ['color', 'colour', 'colores']);
            if (empty($matchedColors)) {
                continue;
            }

            $image = '';
            $imageId = $variation->get_image_id();
            if ($imageId > 0) {
                $image = (string) wp_get_attachment_image_url($imageId, 'large');
            }

            foreach ($matchedColors as $colorLabel) {
                $normalizedLabel = trim((string) $colorLabel);
                if ($normalizedLabel === '') {
                    continue;
                }

                $key = $this->normalizeKey($normalizedLabel);
                if (!isset($indexByKey[$key])) {
                    $indexByKey[$key] = count($ordered);
                    $ordered[] = [
                        'label' => $normalizedLabel,
                        'image' => '',
                        'variation_id' => null,
                        'disabled' => true,
                    ];
                }

                $entryIndex = $indexByKey[$key];
                $existing = $ordered[$entryIndex];

                if ($image !== '' && ($existing['image'] === '' || $existing['disabled'])) {
                    $ordered[$entryIndex] = [
                        'label' => $existing['label'] !== '' ? $existing['label'] : $normalizedLabel,
                        'image' => $image,
                        'variation_id' => (int) $variation->get_id(),
                        'disabled' => false,
                    ];
                }
            }
        }

        return $ordered;
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

    private function resolveDisplayPrice(WC_Product $product): string
    {
        $numericPrice = $this->resolveDisplayPriceAmount($product);
        if ($numericPrice !== null) {
            return $this->toPlainText((string) wc_price($numericPrice));
        }

        return $this->extractLastPriceToken((string) $product->get_price_html());
    }

    private function resolveDisplayPriceAmount(WC_Product $product): ?float
    {
        if ($product->is_type('variable')) {
            $minVariationPrice = $product->get_variation_price('min', true);
            if ($minVariationPrice !== '') {
                return (float) $minVariationPrice;
            }
        }

        $rawPrice = $product->get_price();
        if ($rawPrice === '' || $rawPrice === null) {
            return null;
        }

        return (float) wc_get_price_to_display($product);
    }

    private function extractLastPriceToken(string $priceHtml): string
    {
        $plain = $this->toPlainText($priceHtml);
        if ($plain === '') {
            return '';
        }

        $currencySymbol = function_exists('get_woocommerce_currency_symbol')
            ? (string) get_woocommerce_currency_symbol()
            : '$';
        $pattern = '/' . preg_quote($currencySymbol, '/') . '\s*\d[\d.,]*/u';

        if (preg_match_all($pattern, $plain, $matches) && !empty($matches[0])) {
            return trim((string) end($matches[0]));
        }

        return $plain;
    }

    private function toPlainText(string $value): string
    {
        $plain = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain);

        return is_string($plain) ? trim($plain) : '';
    }

    private function normalizeKey(string $value): string
    {
        $normalized = remove_accents($value);
        $normalized = strtolower(trim($normalized));
        return preg_replace('/\s+/', '_', $normalized) ?? $normalized;
    }

    /**
     * @param string[] $gallery
     */
    private function findGalleryIndex(array $gallery, string $image): int
    {
        $needle = trim($image);
        if ($needle === '') {
            return 0;
        }

        foreach ($gallery as $index => $candidate) {
            if (trim((string) $candidate) === $needle) {
                return (int) $index;
            }
        }

        return 0;
    }
}
