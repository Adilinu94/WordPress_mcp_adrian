<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for Novamira AdrianV2 tests.
 *
 * Loads Composer autoloader (for PHPUnit + PSR-4 test namespace)
 * and the V3_To_V4_Converter class.
 */

// Define ABSPATH to prevent the converter's exit guard from killing PHPUnit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', true );
}

// 1. Composer autoloader — required for PHPUnit itself and PSR-4 test discovery.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// 2. Converter class (already in classmap, but explicit load avoids edge cases).
require_once __DIR__ . '/../includes/helpers/class-v3-to-v4-converter.php';

// 3. AutoFixer and Auditor classes.
require_once __DIR__ . '/../includes/helpers/class-conversion-auto-fixer.php';
require_once __DIR__ . '/../includes/helpers/class-conversion-auditor.php';

// 4. Abilities under test.
require_once __DIR__ . '/../includes/abilities/elementor/class-design-token-remap.php';
