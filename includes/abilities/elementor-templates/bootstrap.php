<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if (!defined('ABSPATH')) {
    exit();
}

$files = [
    __DIR__ . '/class-template-manager.php',
    __DIR__ . '/class-kit-manifest.php',
    __DIR__ . '/class-kit-page-creator.php',
    __DIR__ . '/class-kit-site-configurator.php',
    __DIR__ . '/class-kit-menu-builder.php',
    __DIR__ . '/class-kit-rollback.php',
    __DIR__ . '/class-kit-plugin-installer.php',
    __DIR__ . '/class-kit-media-handler.php',
    __DIR__ . '/class-kit-font-localizer.php',
    __DIR__ . '/class-kit-self-heal.php',
    __DIR__ . '/class-import-template-kit.php',
];
foreach ($files as $f) {
    if (file_exists($f)) require_once $f;
}

$abilities = [
    'Novamira\AdrianV2\Abilities\ElementorTemplates\Template_Manager',
    'Novamira\AdrianV2\Abilities\ElementorTemplates\Import_Template_Kit',
];
foreach ($abilities as $class) {
    if (class_exists($class) && method_exists($class, 'register')) {
        $class::register();
    }
}
