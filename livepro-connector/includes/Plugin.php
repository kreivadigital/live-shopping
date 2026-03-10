<?php

namespace LiveProConnector;

use LiveProConnector\Admin\SettingsPage;
use LiveProConnector\Frontend\WidgetAssets;
use LiveProConnector\Rest\OrderPendingRoute;
use LiveProConnector\Support\SettingsRepository;

final class Plugin
{
    private SettingsPage $settingsPage;
    private WidgetAssets $widgetAssets;
    private OrderPendingRoute $orderPendingRoute;

    public function __construct(string $pluginFile)
    {
        $settings = new SettingsRepository();

        $this->settingsPage = new SettingsPage($settings, $pluginFile);
        $this->widgetAssets = new WidgetAssets($settings, $pluginFile);
        $this->orderPendingRoute = new OrderPendingRoute($settings);
    }

    public function boot(): void
    {
        $this->settingsPage->boot();
        $this->widgetAssets->boot();
        $this->orderPendingRoute->boot();
    }
}
