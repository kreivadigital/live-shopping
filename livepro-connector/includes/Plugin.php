<?php

namespace LiveProConnector;

use LiveProConnector\Admin\SettingsPage;
use LiveProConnector\Frontend\WidgetAssets;
use LiveProConnector\Rest\CartRoute;
use LiveProConnector\Rest\OrderPendingRoute;
use LiveProConnector\Rest\ProductDetailsRoute;
use LiveProConnector\Support\ProductDataResolver;
use LiveProConnector\Support\SettingsRepository;

final class Plugin
{
    private SettingsPage $settingsPage;
    private WidgetAssets $widgetAssets;
    private OrderPendingRoute $orderPendingRoute;
    private ProductDetailsRoute $productDetailsRoute;
    private CartRoute $cartRoute;

    public function __construct(string $pluginFile)
    {
        $settings = new SettingsRepository();
        $productDataResolver = new ProductDataResolver();

        $this->settingsPage = new SettingsPage($settings, $pluginFile);
        $this->widgetAssets = new WidgetAssets($settings, $pluginFile);
        $this->orderPendingRoute = new OrderPendingRoute($settings);
        $this->productDetailsRoute = new ProductDetailsRoute($productDataResolver);
        $this->cartRoute = new CartRoute();
    }

    public function boot(): void
    {
        $this->settingsPage->boot();
        $this->widgetAssets->boot();
        $this->orderPendingRoute->boot();
        $this->productDetailsRoute->boot();
        $this->cartRoute->boot();
    }
}
