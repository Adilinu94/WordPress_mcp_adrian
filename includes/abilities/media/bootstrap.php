<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// media abilities - class_exists guards + require_once + register
$novamira_adrianv2_media_files = [
    __DIR__ . '/class-batch-media-upload.php',
    __DIR__ . '/class-delete-media.php',
    __DIR__ . '/class-edit-media.php',
    __DIR__ . '/class-featured-image.php',
    __DIR__ . '/class-list-media.php',
    __DIR__ . '/class-media-upload.php',
    __DIR__ . '/class-media-usage.php',
    __DIR__ . '/class-upload-asset.php',
];

foreach ( $novamira_adrianv2_media_files as $novamira_adrianv2_media_file ) {
    if ( file_exists( $novamira_adrianv2_media_file ) ) {
        require_once $novamira_adrianv2_media_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Media\Batch_Media_Upload' ) && method_exists( 'Novamira\AdrianV2\Abilities\Media\Batch_Media_Upload', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Media\Batch_Media_Upload::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Media\Delete_Media' ) && method_exists( 'Novamira\AdrianV2\Abilities\Media\Delete_Media', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Media\Delete_Media::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Media\Edit_Media' ) && method_exists( 'Novamira\AdrianV2\Abilities\Media\Edit_Media', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Media\Edit_Media::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Media\Featured_Image' ) && method_exists( 'Novamira\AdrianV2\Abilities\Media\Featured_Image', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Media\Featured_Image::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Media\List_Media' ) && method_exists( 'Novamira\AdrianV2\Abilities\Media\List_Media', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Media\List_Media::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Media\Media_Upload' ) && method_exists( 'Novamira\AdrianV2\Abilities\Media\Media_Upload', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Media\Media_Upload::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Media\Media_Usage' ) && method_exists( 'Novamira\AdrianV2\Abilities\Media\Media_Usage', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Media\Media_Usage::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Media\Upload_Asset' ) && method_exists( 'Novamira\AdrianV2\Abilities\Media\Upload_Asset', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Media\Upload_Asset::register();
        }
