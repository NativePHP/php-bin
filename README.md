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

## Creating new binaries

NativePHP uses [`static-php-cli`](https://static-php.dev) to build minimal, statically-linked, self-contained PHP
executables for each platform.

You need to build separately for each PHP version:

### PHP 8.2
```shell
bin/spc download --clean
bin/spc download --with-php=8.2 --for-extensions "bcmath,ctype,curl,dom,fileinfo,filter,mbstring,openssl,pdo,pdo_sqlite,session,simplexml,sockets,sqlite3,tokenizer,xml,zlib"
```

### PHP 8.3
```shell
bin/spc download --clean
bin/spc download --with-php=8.3 --for-extensions "bcmath,ctype,curl,dom,fileinfo,filter,mbstring,openssl,pdo,pdo_sqlite,session,simplexml,sockets,sqlite3,tokenizer,xml,zlib"
```

### Build
```shell
bin/spc build --build-cli --build-embed "bcmath,ctype,curl,dom,fileinfo,filter,mbstring,openssl,pdo,pdo_sqlite,session,simplexml,sockets,sqlite3,tokenizer,xml,zlib"
```

You need to build these on the relevant platform to compile binaries for that platform.

## Issues

Please raise any issues on the [NativePHP/laravel](/nativephp/laravel/issues/new/choose) repo.

## Credits

- [Marcel Pociot](https://github.com/mpociot)
- [Simon Hamp](https://github.com/simonhamp)
- [All Contributors](../../contributors)

## License

This repository is simply a redistribution of PHP. As such its use and further distribution is subject to the various
license agreements found in [the licenses](license-files/)
