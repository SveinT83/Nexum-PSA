<?php

/**
 * Global helper functions for the application.
 */

if (!function_exists('breadcrumbs')) {
    /**
     * Retrieve breadcrumbs configuration for the current route.
     *
     * This function attempts to find breadcrumb definitions in the config file
     * matching the current route name. It handles both prefixed (tech.*) and
     * non-prefixed route names to ensure consistency across the admin panel.
     *
     * @return array List of breadcrumb items, each with 'label' and optional 'route'.
     */
    function breadcrumbs()
    {
        /** @var \Illuminate\Routing\Route|null $currentRoute */
        $currentRoute = request()->route();

        // Get the current route name
        $route = $currentRoute?->getName();

        if (!$route) {
            return [];
        }

        // Get the entire breadcrumbs configuration array.
        // We avoid using config("breadcrumbs.$route") because Laravel's config()
        // treats dots as nested array keys, but our breadcrumb keys contain dots.
        $allCrumbs = config('breadcrumbs', []);

        // 1. Direct match
        if (isset($allCrumbs[$route])) {
            return resolve_breadcrumb_labels($allCrumbs[$route]);
        }

        // 2. Fallback: If route has 'tech.' prefix, try without it
        if (strpos($route, 'tech.') === 0) {
            $fallback = substr($route, 5);
            if (isset($allCrumbs[$fallback])) {
                return resolve_breadcrumb_labels($allCrumbs[$fallback]);
            }
        }

        // 3. Fallback: If route DOES NOT have 'tech.' prefix, try with it
        if (strpos($route, 'tech.') !== 0) {
            $prefixed = "tech.$route";
            if (isset($allCrumbs[$prefixed])) {
                return resolve_breadcrumb_labels($allCrumbs[$prefixed]);
            }
        }

        return [];
    }
}

if (!function_exists('resolve_breadcrumb_labels')) {
    /**
     * Resolve dynamic breadcrumb labels from route-bound models.
     *
     * Config entries can use `label_from`, for example `asset.name`, to keep
     * breadcrumbs centralized without writing per-view breadcrumb markup.
     */
    function resolve_breadcrumb_labels(array $crumbs): array
    {
        return collect($crumbs)
            ->map(function (array $crumb): array {
                if (isset($crumb['label_from'])) {
                    [$routeParameter, $attribute] = array_pad(explode('.', $crumb['label_from'], 2), 2, null);
                    $model = request()->route($crumb['label_from_param'] ?? $routeParameter);
                    $crumb['label'] = ($attribute ? data_get($model, $attribute) : null) ?: ($crumb['label'] ?? 'Current');
                    unset($crumb['label_from'], $crumb['label_from_param']);
                }

                return $crumb;
            })
            ->all();
    }
}
