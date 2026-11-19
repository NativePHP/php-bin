<?php

// Passed to `spc build` with --with-added-patch. SPC runs it at every patch point.
//
// SPC always unpacks phpmicro and applies the patches named in SPC_MICRO_PATCHES to
// php-src, even for a CLI-only build. On Windows these include the static CRT patches
// the CLI needs to link. For each name it picks the versioned file by stepping down a
// list that stops at 85. SPC 2.8.5 doesn't know 86, so on 8.6 it falls through to the
// oldest (_80) files, which don't apply.
//
// For 8.6, rewrite SPC_MICRO_PATCHES to name the newest versioned patch phpmicro has
// for each entry (cli_checks_85, win32_85, ...). patchMicro() reads the variable after
// this point and uses an exact file name as is. 8.3 to 8.5 are untouched.
//
// Remove this once SPC supports 8.6.

if (patch_point() !== 'after-micro-extract') {
    return;
}

if (builder()->getPHPVersionID() < 80600) {
    return;
}

$dir = SOURCE_PATH . '/php-src/sapi/micro/patches';
$names = array_filter(array_map('trim', explode(',', (string) getenv('SPC_MICRO_PATCHES'))));
$resolved = [];

foreach ($names as $name) {
    if (file_exists("{$dir}/{$name}.patch")) {
        $resolved[] = $name;
        continue;
    }

    foreach (['85', '84', '83', '82', '81', '80'] as $version) {
        if (file_exists("{$dir}/{$name}_{$version}.patch")) {
            $resolved[] = "{$name}_{$version}";
            continue 2;
        }
    }

    // Leave it for SPC to report as missing.
    $resolved[] = $name;
}

logger()->info('PHP 8.6: phpmicro patches ' . implode(',', $resolved));
putenv('SPC_MICRO_PATCHES=' . implode(',', $resolved));
