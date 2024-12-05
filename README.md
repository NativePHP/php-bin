# Fork of NativePHP/php-bin with gRPC support

This is a fork of the [NativePHP/php-bin](https://github.com/NativePHP/php-bin) repository with added support for gRPC.

Unless you need gRPC support, you should use the original repository.

This package is meant to be used in an NativePHP app like this:

```shell
composer require srwiez/php-bin-with-grpc --dev
```

Open your `.env` file and add the following line:

```dotenv
NATIVEPHP_PHP_BINARY_PATH=vendor/srwiez/php-bin-with-grpc/
```

And voilà!

## Supported versions

We currently support PHP versions 8.1 to 8.4, along with macOS and Linux.

|     | MacOS x86 | MacOS arm64 | Linux x86 | Linux arm64 | Windows                                                                            |
|-----|-----------|-------------|-----------|-------------|------------------------------------------------------------------------------------|
| 8.1 | ✅         | ✅           | ✅         | Soon        | [Unsupported at the moment](https://github.com/crazywhalecc/static-php-cli/issues) |
| 8.2 | ✅         | ✅           | ✅         | Soon        | [Unsupported at the moment](https://github.com/crazywhalecc/static-php-cli/issues) |
| 8.3 | ✅         | ✅           | ✅         | Soon        | [Unsupported at the moment](https://github.com/crazywhalecc/static-php-cli/issues) |
| 8.4 | ✅         | ✅           | ✅         | Soon        | [Unsupported at the moment](https://github.com/crazywhalecc/static-php-cli/issues) |

## License

This repository is simply a redistribution of PHP. As such its use and further distribution is subject to the various
license agreements found in [the licenses](license-files/)
