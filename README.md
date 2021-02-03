# retrieve content and metadata from any URL

Github: 
![GitHub tag](https://img.shields.io/github/v/tag/pforret/pf_pageinfo)
![Tests](https://github.com/pforret/pf_pageinfo/workflows/Run%20Tests/badge.svg)
![Psalm](https://github.com/pforret/pf_pageinfo/workflows/Detect%20Psalm%20warnings/badge.svg)
![Styling](https://github.com/pforret/pf_pageinfo/workflows/Check%20&%20fix%20styling/badge.svg)

Packagist: 
[![Packagist Version](https://img.shields.io/packagist/v/pforret/pf_pageinfo.svg?style=flat-square)](https://packagist.org/packages/pforret/pf_pageinfo)
[![Packagist Downloads](https://img.shields.io/packagist/dt/pforret/pf_pageinfo.svg?style=flat-square)](https://packagist.org/packages/pforret/pf_pageinfo)

retrieve content and metadata from any URL

	created on 2021-02-03 by peter@forret.com

## Installation

You can install the package via composer:

```bash
composer require pforret/pf_pageinfo
```

## Usage

``` php
$obj = new Pforret\PfPageinfo();
echo $obj->echoPhrase('Hello, pforret!');
```

## Testing

``` bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email author_email instead of using the issue tracker.

## Credits

- [Peter Forret](https://github.com/pforret)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
