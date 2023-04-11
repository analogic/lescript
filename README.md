# Simple Let's encrypt client concept in PHP

> **Note**: lescript is standalone part of [LEManager](https://github.com/analogic/lemanager)

Lescript is a simplified concept of ACME client implementation specifically for the Let's Encrypt service. It's goal is to have one
PHP file with no dependencies.

**Use at your own risk.**

## Usage

See commented content of **Lescript.php** and **_example.php**. Please rewrite the files to suit your needs - the purpose of this library is not to use it as is, nor to use it in production!

Support **challenge only through webroot**.

## Requirements

- PHP 5.3 and up
- OpenSSL extension
- Curl extension

## Why i created lescript?

Because of implementation of Let's Encrypt to [Poste.io](https://poste.io)!
