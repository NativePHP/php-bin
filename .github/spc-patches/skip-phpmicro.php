<?php

// Passed to `spc build` with --with-added-patch. SPC runs it at every patch point.
//
// SPC always unpacks phpmicro into php-src/sapi/micro and applies its patches,
// even when we only build the CLI. phpmicro's patches don't apply to PHP 8.6 yet
// (cli_checks_80, static_extensions_win32_80), which stops the build.
//
// For 8.6 we delete phpmicro straight after it's unpacked. SourcePatcher::patchMicro()
// then finds no php_micro.c and skips the patches. 8.3 to 8.5 are untouched.
//
// Remove this once SPC and phpmicro support 8.6.

if (patch_point() !== 'after-micro-extract') {
    return;
}

if (builder()->getPHPVersionID() < 80600) {
    return;
}

$micro = SOURCE_PATH . '/php-src/sapi/micro';

if (is_dir($micro)) {
    logger()->info('PHP 8.6: removing phpmicro so its patches are skipped');
    \SPC\store\FileSystem::removeDir($micro);
}
