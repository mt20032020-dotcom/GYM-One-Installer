# PHP library to create passes for iOS Wallet

[![Packagist Version](https://img.shields.io/packagist/v/pkpass/pkpass)](https://packagist.org/packages/pkpass/pkpass)
[![Packagist Downloads](https://img.shields.io/packagist/dt/pkpass/pkpass)](https://packagist.org/packages/pkpass/pkpass)
[![Packagist License](https://img.shields.io/packagist/l/pkpass/pkpass)](LICENSE)

This class provides the functionality to create passes for Wallet in Apple's iOS. It creates,
signs and packages the pass as a `.pkpass` file according to Apple's documentation.

## Requirements

- PHP 7.0 or higher (may also work with older versions)
- PHP [ZIP extension](http://php.net/manual/en/book.zip.php) (often installed by default)
- Access to filesystem to write temporary cache files

## Installation

Simply run the following command in your project's root directory to install via [Composer](https://getcomposer.org/):

```
composer require pkpass/pkpass
```

Or add to your composer.json: `"pkpass/pkpass": "^2.0.0"`

## Usage

Please take a look at the [examples/example.php](examples/example.php) file for example usage. For more info on the JSON for the pass and how to
style it, take a look at the [docs at developers.apple.com](https://developer.apple.com/library/ios/documentation/UserExperience/Reference/PassKit_Bundle/Chapters/Introduction.html).

### Included demos

- 📦 [Simple example](examples/example.php)
- ✈️ [Flight ticket example](examples/full_sample/)
- ☕️ [Starbucks card example](examples/starbucks_sample/)

## API Documentation

API documentation is available for all main classes:

- **[PKPass API Documentation](docs/PKPass.md)** - Main class for creating Apple Wallet passes
- **[PKPassBundle API Documentation](docs/PKPassBundle.md)** - Bundle multiple passes into a single `.pkpasses` file
- **[FinanceOrder API Documentation](docs/FinanceOrder.md)** - Create Apple Wallet Orders for financial transactions

## Requesting the Pass Certificate

1. Go to the [iOS Provisioning portal](https://developer.apple.com/account/ios/identifier/passTypeId).
2. Create a new Pass Type ID, and write down the Pass ID you choose, you'll need it later.
3. Click the edit button under your newly created Pass Type ID and generate a certificate according to the instructions
   shown on the page. Make sure _not_ to choose a name for the Certificate but keep it empty instead.
4. Download the .cer file and drag it into Keychain Access.
5. Choose to filter by **Certificates** in the top filter bar.
6. Find the certificate you just imported and click the triangle on the left to reveal the private key.
7. Select both the certificate and the private key it, then right-click the certificate in Keychain Access and
   choose `Export 2 items…`.
8. Choose a password and export the file to a folder.

![Exporting P12 file](docs/guide-export.gif)

### Getting the example.php sample to work

1. Request the Pass certificate (`.p12`) as described above and upload it to your server.
2. Set the correct path and password on [line 22](examples/example.php#L22).
3. Change the `passTypeIdentifier` and `teamIndentifier` to the correct values on lines [29](examples/example.php#L29) and [31](examples/example.php#L31) (`teamIndentifier` can be found on the [Developer Portal](https://developer.apple.com/account/#/membership)).

After completing these steps, you should be ready to go. Upload all the files to your server and navigate to the address
of the examples/example.php file on your iPhone.

## Debugging

### Using the Console app

If you aren't able to open your pass on an iPhone, plug the iPhone into a Mac and open the 'Console' application. On the left, you can select your iPhone. You will then be able to inspect any errors that occur while adding the pass:

![Console with Passkit error](docs/console.png)

- `Trust evaluate failure: [leaf TemporalValidity]`: If you see this error, your pass was signed with an outdated certificate.
- `Trust evaluate failure: [leaf LeafMarkerOid]`: You did not leave the name of the certificate empty while creating it in the developer portal.

### OpenSSL errors

When you get the error 'Could not read certificate file', this might be related to using an OpenSSL version that has deprecated some older hashes - [more info here](https://schof.link/2Et6z3m).

There may be no need to configure OpenSSL to use legacy algorithms. It's easier and more portable just to convert the encrypted certificates file. The steps below use a .p12 file but it should work to swap these commands for a .pfx file.

Instructions:

1. `openssl pkcs12 -legacy -in key.p12 -nodes -out key_decrypted.tmp` (replace key.p12 with your .p12 file name).
2. `openssl pkcs12 -in key_decrypted.tmp -export -out key_new.p12 -certpbe AES-256-CBC -keypbe AES-256-CBC -iter 2048` (use the newly generated key_new.p12 file in your pass generation below)

The `key_new.p12` file should now be compatible with OpenSSL v3+.

## Changelog

**Version 2.4.0 - October 2024**

- Add `PKPassBundle` class to bundle multiple passes into a single `.pkpasses` file.

**Version 2.3.2 - September 2024**

- Fix order mime type, add better error reporting.

**Version 2.3.1 - March 2024**

- Chore: add gitattributes.

**Version 2.3.0 - February 2024**

- Add support for Wallet Orders.

**Version 2.2.0 - December 2023**

- Update default WWDR certificate to G4.

**Version 2.1.0 - April 2023**

- Add alternative method for extracting P12 contents to circumvent issues in recent versions of OpenSSL.

**Version 2.0.2 - October 2022**

- Switch to `ZipArchive::OVERWRITE` method of opening ZIP due to PHP 8 deprecation ([#120](https://github.com/includable/php-pkpass/pull/120)).

**Version 2.0.1 - October 2022**

- Update WWDR certificate to v6 ([#118](https://github.com/includable/php-pkpass/issues/118)).

**Version 2.0.0 - September 2022**

- Changed signature of constructor to take out third `$json` parameter.
- Remove deprecated `setJSON()` method.
- Removed `checkError()` and `getError()` methods in favor of exceptions.

## Support & documentation

Please read the instructions above and consult the [Wallet Documentation](https://developer.apple.com/wallet/) before
submitting tickets or requesting support. It might also be worth
to [check Stackoverflow](http://stackoverflow.com/search?q=%22PHP-PKPass%22), which contains quite a few questions about
this library.

<br /><br />

---

<div align="center">
	<b>
		<a href="https://includable.com/consultancy/?utm_source=includable/php-pkpass">Get professional support for this package →</a>
	</b>
	<br>
	<sub>
		Custom consulting sessions available for implementation support and feature development.
	</sub>
</div>
