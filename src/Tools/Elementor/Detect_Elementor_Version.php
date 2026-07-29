<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report the Elementor (and Elementor Pro) version and whether atomic elements
 * (Elementor 4.0+) are supported, so an agent can choose between the classic
 * widget/container tools and the atomic tools (add-flexbox, add-atomic-widget).
 * Read-only.
 */
class Detect_Elementor_Version
{
    public function handle(array $args): array
    {
        $core = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown';
        $pro  = defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null;

        $atomic = Atomic_Element::is_supported();

        return [
            'elementor_version'     => $core,
            'elementor_pro_version' => $pro,
            'supports_atomic'       => $atomic,
            'recommended_mode'      => $atomic ? 'atomic' : 'legacy',
        ];
    }
}
