<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// utilities abilities - class_exists guards + require_once + register
$novamira_adrianv2_utilities_files = [
    __DIR__ . '/class-hello-world.php',
    __DIR__ . '/class-self-audit.php',
    __DIR__ . '/class-get-project-styles.php',
    __DIR__ . '/class-catalog-abilities.php',
    __DIR__ . '/class-skill-list.php',
];

foreach ( $novamira_adrianv2_utilities_files as $novamira_adrianv2_utilities_file ) {
    if ( file_exists( $novamira_adrianv2_utilities_file ) ) {
        require_once $novamira_adrianv2_utilities_file;
    }
}

require_once __DIR__ . '/class-list-style-keys.php';

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Utilities\Hello_World' ) && method_exists( 'Novamira\AdrianV2\Abilities\Utilities\Hello_World', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Utilities\Hello_World::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Utilities\Self_Audit' ) && method_exists( 'Novamira\AdrianV2\Abilities\Utilities\Self_Audit', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Utilities\Self_Audit::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Utilities\Get_Project_Styles' ) && method_exists( 'Novamira\AdrianV2\Abilities\Utilities\Get_Project_Styles', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Utilities\Get_Project_Styles::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Utilities\Discover_Ability_Metadata' ) && method_exists( 'Novamira\AdrianV2\Abilities\Utilities\Discover_Ability_Metadata', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Utilities\Discover_Ability_Metadata::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Utilities\List_Style_Keys' ) && method_exists( 'Novamira\AdrianV2\Abilities\Utilities\List_Style_Keys', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Utilities\List_Style_Keys::register();
        }
        if ( class_exists( 'Novamira\\AdrianV2\\Abilities\\Utilities\\Skill_List' ) && method_exists( 'Novamira\\AdrianV2\\Abilities\\Utilities\\Skill_List', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Utilities\Skill_List::register();
        }
