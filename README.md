# Flysystem Adapter for the FTP with cURL implementation

[![Latest Stable Version](https://poser.pugx.org/vladimir-yuldashev/flysystem-curlftp/v/stable?format=flat-square)](https://packagist.org/packages/vladimir-yuldashev/flysystem-curlftp)
[![Build Status](https://github.com/vyuldashev/flysystem-curlftp/workflows/Tests/badge.svg)](https://github.com/vyuldashev/flysystem-curlftp/actions)
[![StyleCI](https://styleci.io/repos/90028075/shield?branch=master)](https://styleci.io/repos/90028075)
[![License](https://poser.pugx.org/vladimir-yuldashev/flysystem-curlftp/license?format=flat-square)](https://packagist.org/packages/vladimir-yuldashev/flysystem-curlftp)

This package contains a [Flysystem](https://flysystem.thephpleague.com/) FTP adapter with cURL implementation.
It supports both explicit and implicit SSL connections.

## Installation

You can install the package via composer:

``` bash
composer require vladimir-yuldashev/flysystem-curlftp
```

## Usage

``` php
use League\Flysystem\Filesystem;
use VladimirYuldashev\Flysystem\CurlFtpAdapter;

$adapter = new CurlFtpAdapter([
  'host' => 'ftp.example.com',
  'username' => 'username',
  'password' => 'password',

  /** optional config settings */
  'port' => 21,
  'root' => '/path/to/root',
  'utf8' => true,
  'ftps' => true, // use ftps:// with implicit TLS or ftp:// with explicit TLS
  'ssl' => true,
  'timeout' => 90, // connect timeout
  'passive' => true, // default use PASV mode
  'skipPasvIp' => true, // ignore the IP address in the PASV response 
  'sslVerifyPeer' => 0, // using 0 is insecure, use it only if you know what you're doing
  'sslVerifyHost' => 0, // using 0 is insecure, use it only if you know what you're doing
  'enableTimestampsOnUnixListings' => true,
  
  /** proxy settings */
  'proxyHost' => 'proxy-server.example.com',
  'proxyPort' => 80,
  'proxyUsername' => 'proxyuser',
  'proxyPassword' => 'proxypassword',
  
  'verbose' => false // set verbose mode on/off 
]);

$filesystem = new Filesystem($adapter);
``` 

## Testing

``` bash
$ composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
