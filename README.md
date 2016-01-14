# Simple Let's encrypt client concept in PHP

*Notice: lescript is standalone part of [LEManager](https://github.com/analogic/lemanager)*

Lescript is simplified concept of ACME client implementation especially for Let's Encrypt service. It's goal is to have one 
easy to use PHP file without dependencies. 

**Use at your own risk.**

## Usage

See commented content of **Lescript.php** and **_example.php**. Please rewrite files to fit your needs - purpose of this library is not to use as it is nor use it in production!

Support **challenge only through webroot**.

## Requirements

- PHP 5.3 and up
- OpenSSL extension
- Curl extension

## Others

If you prefer more robust and clean library see excelent https://github.com/kelunik/acme


## Why i created lescript?

Because of implementation of Let's Encrypt to [Poste.io](https://poste.io)!
