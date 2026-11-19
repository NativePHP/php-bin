<?php

// Passed to `spc build` with --with-added-patch on Windows. SPC runs it at every patch point.
//
// Fixes for building PHP 8.6 on Windows with SPC 2.8.5, which predates 8.6. Each one
// mirrors a fix in SPC's main branch (commit 23a943c, "fix gd and intl on windows").
// 8.3 to 8.5 are untouched. Remove this once we're on an SPC release that supports 8.6.
//
// 1. GD: SPC replaces ext/gd/config.w32 with its own copy as soon as php-src is unpacked.
//    2.8.5 uses its 8.5 copy for 8.6, which misses the source files 8.6's GD added
//    (gd_metadata.c, gd_color_match.c, ...), so the link fails. Put SPC's 8.6 copy
//    (gd_config_86.w32, vendored next to this file) in its place.
//
// 2. intl: SPC makes intl static by replacing `intl_error.c ", true,` in the full
//    EXTENSION("intl", ...) line. 8.6 added intl_icu_compat.c to that line, so the exact
//    match misses and intl silently builds shared, dropping out of the static binary.
//    Replace just the tail.
//
// 3. libwebp: 8.6's GD needs libwebpmux.lib and webp/mux.h, and SPC 2.8.5 builds libwebp
//    on Windows with -DWEBP_BUILD_LIBWEBPMUX=OFF. Right after SPC builds libwebp, rebuild it
//    with the same flags plus mux, into the same build root.

if (PHP_OS_FAMILY !== 'Windows') {
    return;
}

if (patch_point() === 'after-exts-extract') {
    if (builder()->getPHPVersionID() < 80600) {
        return;
    }

    $php_src = SOURCE_PATH . '\\php-src';

    logger()->info('PHP 8.6: using SPC\'s 8.6 GD config.w32');
    if (! copy(__DIR__ . '/gd_config_86.w32', $php_src . '\\ext\\gd\\config.w32')) {
        throw new \RuntimeException('Could not copy gd_config_86.w32 into php-src');
    }

    $intl = $php_src . '\\ext\\intl\\config.w32';
    $config = file_get_contents($intl);
    if (str_contains($config, 'intl_error.c ", true,')) {
        logger()->info('PHP 8.6: building intl static');
        file_put_contents($intl, str_replace('intl_error.c ", true,', 'intl_error.c ", PHP_INTL_SHARED,', $config));
    } elseif (! str_contains($config, 'intl_error.c ", PHP_INTL_SHARED,')) {
        throw new \RuntimeException('ext/intl/config.w32 has neither the shared nor the static form we expect');
    }

    return;
}

if (patch_point() !== 'after-library[libwebp]-build') {
    return;
}

if (builder()->getPHPVersionID() < 80600) {
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
