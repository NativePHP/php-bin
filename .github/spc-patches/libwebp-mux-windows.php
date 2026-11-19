<?php

// Passed to `spc build` with --with-added-patch on Windows. SPC runs it at every patch point.
//
// PHP 8.6's GD needs libwebpmux on Windows: ext/gd/config.w32 checks for libwebpmux.lib
// and webp/mux.h, and libgd/gd_webp.c includes mux.h. SPC 2.8.5 builds libwebp on
// Windows with -DWEBP_BUILD_LIBWEBPMUX=OFF, so the GD build fails.
//
// Right after SPC builds libwebp, rebuild it with the same flags plus mux, and install
// it into the same build root. That adds libwebpmux.lib and webp/mux.h next to the rest.
// Only for 8.6 on Windows. 8.3 to 8.5 are untouched.
//
// Remove this once SPC builds libwebpmux on Windows itself.

if (patch_point() !== 'after-library[libwebp]-build') {
    return;
}

if (PHP_OS_FAMILY !== 'Windows' || builder()->getPHPVersionID() < 80600) {
    return;
}

logger()->info('PHP 8.6: rebuilding libwebp with libwebpmux for GD');

$source = SOURCE_PATH . '\libwebp';

\SPC\store\FileSystem::resetDir($source . '\build-mux');

cmd()->cd($source)
    ->execWithWrapper(
        builder()->makeSimpleWrapper('cmake'),
        '-B build-mux ' .
        '-A x64 ' .
        '-DCMAKE_TOOLCHAIN_FILE=' . builder()->cmake_toolchain_file . ' ' .
        '-DCMAKE_BUILD_TYPE=Release ' .
        '-DBUILD_SHARED_LIBS=OFF ' .
        '-DWEBP_LINK_STATIC=ON ' .
        '-DWEBP_BUILD_ANIM_UTILS=OFF ' .
        '-DWEBP_BUILD_CWEBP=OFF ' .
        '-DWEBP_BUILD_DWEBP=OFF ' .
        '-DWEBP_BUILD_GIF2WEBP=OFF ' .
        '-DWEBP_BUILD_IMG2WEBP=OFF ' .
        '-DWEBP_BUILD_VWEBP=OFF ' .
        '-DWEBP_BUILD_WEBPINFO=OFF ' .
        '-DWEBP_BUILD_LIBWEBPMUX=ON ' .
        '-DWEBP_BUILD_WEBPMUX=OFF ' .
        '-DWEBP_BUILD_EXTRAS=OFF ' .
        '-DCMAKE_INSTALL_PREFIX=' . BUILD_ROOT_PATH
    )
    ->execWithWrapper(
        builder()->makeSimpleWrapper('cmake'),
        '--build build-mux --config Release --target install -j' . builder()->concurrency
    );

foreach ([BUILD_LIB_PATH . '\libwebpmux.lib', BUILD_INCLUDE_PATH . '\webp\mux.h'] as $file) {
    if (! file_exists($file)) {
        throw new \RuntimeException("libwebp rebuild finished but {$file} is missing");
    }
}
