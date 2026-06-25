<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// elementor abilities - class_exists guards + require_once + register.
$novamira_adrianv2_elementor_files = array(
	__DIR__ . '/class-elementor-check-setup.php',
	__DIR__ . '/class-elementor-edit-element.php',
	__DIR__ . '/class-add-global-class-variant.php',
	__DIR__ . '/class-apply-variable-to-class.php',
	__DIR__ . '/class-elementor-assign-class-to-containers.php',
	__DIR__ . '/class-elementor-inject-calibrated-page.php',
	__DIR__ . '/class-batch-build-page.php',
	__DIR__ . '/class-batch-class.php',
	__DIR__ . '/class-batch-get-content.php',
	__DIR__ . '/class-clone-element.php',
	__DIR__ . '/class-apply-variable-map-to-page.php',
	__DIR__ . '/class-design-token-remap.php',
	__DIR__ . '/class-convert-page-v3-to-v4.php',
	__DIR__ . '/class-convert-site-v3-to-v4.php',
	__DIR__ . '/_deprecated/class-convert-kit-to-v4.php',
	__DIR__ . '/class-create-component.php',
	__DIR__ . '/class-detach-component.php',
	__DIR__ . '/class-duplicate-page.php',
	__DIR__ . '/class-edit-global-class-variant.php',
	__DIR__ . '/class-edit-interaction.php',
	__DIR__ . '/class-export-design-system.php',
	__DIR__ . '/class-get-page-markdown.php',
	__DIR__ . '/class-global-widgets.php',
	__DIR__ . '/class-html-to-elementor-widget-plan.php',
	__DIR__ . '/class-import-design-system.php',
	__DIR__ . '/class-insert-component.php',
	__DIR__ . '/class-kit-convert-v3-to-v4.php',
	__DIR__ . '/class-list-class-variants.php',
	__DIR__ . '/class-list-elementor-pages.php',
	__DIR__ . '/class-list-templates.php',
	__DIR__ . '/class-page-settings.php',
	__DIR__ . '/class-patch-element-styles.php',
	__DIR__ . '/class-remove-global-class.php',
	__DIR__ . '/class-reorder-element.php',
	__DIR__ . '/class-setup-v4-foundation.php',
);

foreach ( $novamira_adrianv2_elementor_files as $novamira_adrianv2_elementor_file ) {
	if ( file_exists( $novamira_adrianv2_elementor_file ) ) {
		require_once $novamira_adrianv2_elementor_file;
	}
}

// Auto-register all abilities in this sub-domain.
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Add_Global_Class_Variant' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Add_Global_Class_Variant', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Add_Global_Class_Variant::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Elementor_Assign_Class_To_Containers' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Elementor_Assign_Class_To_Containers', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Elementor_Assign_Class_To_Containers::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Elementor_Inject_Calibrated_Page' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Elementor_Inject_Calibrated_Page', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Elementor_Inject_Calibrated_Page::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Apply_Variable_To_Class' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Apply_Variable_To_Class', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Apply_Variable_To_Class::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Batch_Build_Page' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Batch_Build_Page', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Batch_Build_Page::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Batch_Class' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Batch_Class', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Batch_Class::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Batch_Get_Content' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Batch_Get_Content', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Batch_Get_Content::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Clone_Element' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Clone_Element', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Clone_Element::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Convert_Kit_To_V4' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Convert_Kit_To_V4', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Convert_Kit_To_V4::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Create_Component' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Create_Component', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Create_Component::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Detach_Component' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Detach_Component', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Detach_Component::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Duplicate_Page' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Duplicate_Page', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Duplicate_Page::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Edit_Global_Class_Variant' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Edit_Global_Class_Variant', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Edit_Global_Class_Variant::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Edit_Interaction' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Edit_Interaction', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Edit_Interaction::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Export_Design_System' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Export_Design_System', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Export_Design_System::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Get_Page_Markdown' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Get_Page_Markdown', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Get_Page_Markdown::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Global_Widgets' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Global_Widgets', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Global_Widgets::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Html_To_Elementor_Widget_Plan' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Html_To_Elementor_Widget_Plan', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Html_To_Elementor_Widget_Plan::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Import_Design_System' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Import_Design_System', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Import_Design_System::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Insert_Component' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Insert_Component', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Insert_Component::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Kit_Convert_V3_To_V4' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Kit_Convert_V3_To_V4', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Kit_Convert_V3_To_V4::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\List_Class_Variants' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\List_Class_Variants', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\List_Class_Variants::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\List_Elementor_Pages' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\List_Elementor_Pages', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\List_Elementor_Pages::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\List_Templates' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\List_Templates', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\List_Templates::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Page_Settings' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Page_Settings', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Page_Settings::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Patch_Element_Styles' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Patch_Element_Styles', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Patch_Element_Styles::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Remove_Global_Class' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Remove_Global_Class', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Remove_Global_Class::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Reorder_Element' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Reorder_Element', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Reorder_Element::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Setup_V4_Foundation' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Setup_V4_Foundation', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Setup_V4_Foundation::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Convert_Page_V3_To_V4' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Convert_Page_V3_To_V4', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Convert_Page_V3_To_V4::register();
}
if ( class_exists( 'Novamira\\AdrianV2\\Abilities\\Elementor\\Elementor_Check_Setup' ) && method_exists( 'Novamira\\AdrianV2\\Abilities\\Elementor\\Elementor_Check_Setup', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Elementor_Check_Setup::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Apply_Variable_Map_To_Page' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Apply_Variable_Map_To_Page', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Apply_Variable_Map_To_Page::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Convert_Site_V3_To_V4' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Convert_Site_V3_To_V4', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Convert_Site_V3_To_V4::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Design_Token_Remap' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Design_Token_Remap', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Design_Token_Remap::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Design_Token_Remap' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Design_Token_Remap', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Design_Token_Remap::register();
}
require_once __DIR__ . '/class-fix-orphan-styles.php';
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Fix_Orphan_Styles' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Fix_Orphan_Styles', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Fix_Orphan_Styles::register();
}
require_once __DIR__ . '/class-list-v3-pages.php';
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\List_V3_Pages' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\List_V3_Pages', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\List_V3_Pages::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Elementor_Edit_Element' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Elementor_Edit_Element', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Elementor_Edit_Element::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Get_Page_Elements' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Get_Page_Elements', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Get_Page_Elements::register();
}
if ( class_exists( 'Novamira\AdrianV2\Abilities\Elementor\Register_Google_Font' ) && method_exists( 'Novamira\AdrianV2\Abilities\Elementor\Register_Google_Font', 'register' ) ) {
	Novamira\AdrianV2\Abilities\Elementor\Register_Google_Font::register();
}
