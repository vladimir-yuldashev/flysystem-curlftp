<?php

namespace VladimirYuldashev\Flysystem\Tests;

use Faker\Factory;
use PHPUnit\Framework\TestCase as BaseTestCase;
use VladimirYuldashev\Flysystem\CurlFtpAdapter;

abstract class TestCase extends BaseTestCase
{
    protected $root = '';

    /** @var CurlFtpAdapter */
    protected $adapter;

    public function setUp(): void
    {
        $this->root = getenv('FTP_ADAPTER_ROOT');
        $this->createResourceDir('/');

        $this->adapter = new CurlFtpAdapter([
            'protocol' => getenv('FTP_ADAPTER_PROTOCOL'),
            'host' => getenv('FTP_ADAPTER_HOST'),
            'port' => getenv('FTP_ADAPTER_PORT'),
            'username' => getenv('FTP_ADAPTER_USER'),
            'password' => getenv('FTP_ADAPTER_PASSWORD'),
            'timeout' => getenv('FTP_ADAPTER_TIMEOUT') ?: 35,
            'root' => $this->root,
            'utf8' => true,
            'ssl' => false,
            'ftps' => false, // use ftps:// with implicit TLS or ftp:// with explicit TLS
            'passive' => true, // default use PASV mode
            'skipPasvIp' => false, // ignore the IP address in the PASV response
            'verbose' => false, // set verbose mode on/off
        ]);
    }

    public function tearDown(): void
    {
        $this->adapter->disconnect();
        unset($this->adapter);
        $this->clearResources();
    }

    protected function getResourcesPath()
    {
        return __DIR__.'/resources/';
    }

    protected function getResourceContent($path)
    {
        return file_get_contents($this->getResourceAbsolutePath($path));
    }

    protected function getResourceAbsolutePath($path)
    {
        return implode('/', array_filter([
            rtrim($this->getResourcesPath(), '/'),
            trim($this->root, '/'),
            ltrim($path, '/'),
        ]));
    }

    protected function createResourceDir($path): void
    {
        if (empty($path)) {
            return;
        }
        $absolutePath = $this->getResourceAbsolutePath($path);
        if (! is_dir($absolutePath)) {
            $umask = umask(0);
            mkdir($absolutePath, 0777, true);
            umask($umask);
        }
    }

    protected function createResourceFile($path, $filedata = ''): void
    {
        $this->createResourceDir(dirname($path));
        $absolutePath = $this->getResourceAbsolutePath($path);
        file_put_contents($absolutePath, $filedata);
    }

    protected function randomFileName()
    {
        return $this->faker()->name.'.'.$this->faker()->fileExtension;
    }

    protected function faker()
    {
        return Factory::create();
    }

    protected function clearResources(): void
    {
        exec('rm -rf '.escapeshellarg($this->getResourcesPath()).'*');
        exec('rm -rf '.escapeshellarg($this->getResourcesPath()).'.* 2>/dev/null');
        clearstatcache();
    }
}
