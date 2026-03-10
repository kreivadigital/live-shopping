<?php

namespace LiveProConnector\Rest;

use LiveProConnector\Support\ProductDataResolver;
use WP_REST_Request;
use WP_REST_Response;

final class ProductDetailsRoute
{
    private ProductDataResolver $resolver;

    public function __construct(ProductDataResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('livepro/v1', '/product-view', [
            'methods' => 'GET',
            'callback' => [$this, 'getProductView'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function getProductView(WP_REST_Request $request): WP_REST_Response
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'WooCommerce no está activo'], 500);
        }

        $productId = absint($request->get_param('product_id'));
        $variationId = absint($request->get_param('variation_id'));

        if ($productId <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'product_id inválido'], 422);
        }

        $resolved = $this->resolver->resolve($productId, $variationId);
        if ($resolved === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Producto no encontrado'], 404);
        }

        return new WP_REST_Response([
            'ok' => true,
            'product' => $resolved,
        ], 200);
    }
}
