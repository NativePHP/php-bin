# PHP binaries used by the NativePHP framework

## Installation

You can install the package via Composer:

```shell
composer require nativephp/php-bin --dev
```

or NPM:

```shell
npm -i @nativephp/php-bin --save-dev
```

### ℹ️ Heads up...
> You should install this package as a `dev` dependency, in most cases, and simply copy what you need from it to the
relevant parts of your application. This will save you distributing all of the PHP binaries with every installation.

## Building

NativePHP uses [`static-php-cli`](https://static-php.dev) to build minimal, statically-linked, self-contained PHP
executables for each platform.

They are automatically built weekly to get the latest versions of PHP near enough as soon as they become available.

[Check here](https://github.com/NativePHP/php-bin/blob/main/php-extensions.txt) for the definitive list of
extensions that are compiled in.

## Issues

Please raise any issues on the [NativePHP/laravel](https://github.com/nativephp/laravel/issues/new/choose) repo.

## Credits

- [Marcel Pociot](https://github.com/mpociot)
- [Simon Hamp](https://github.com/simonhamp)
- [All Contributors](../../contributors)

## License

This repository is simply a redistribution of PHP. As such its use and further distribution is subject to the various
license agreements found in [the licenses](license-files/)
