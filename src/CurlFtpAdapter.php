<?php

namespace VladimirYuldashev\Flysystem;

use DateTime;
use Normalizer;
use RuntimeException;
use League\Flysystem\Util;
use League\Flysystem\Config;
use League\Flysystem\Util\MimeType;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\AbstractFtpAdapter;

class CurlFtpAdapter extends AbstractFtpAdapter
{
    protected $configurable = [
        'host',
        'port',
        'username',
        'password',
        'root',
        'ssl',
        'sslVerifyPeer',
        'sslVerifyHost',
        'utf8',
        'timeout',
        'proxyHost',
        'proxyPort',
        'proxyUsername',
        'proxyPassword',
    ];

    /** @var Curl */
    protected $connection;

    /** @var int unix timestamp when connection was established */
    protected $connectionTimestamp = 0;

    /** @var bool */
    protected $isPureFtpd;

    /** @var @int */
    protected $sslVerifyPeer = 1;

    /** @var @int */
    protected $sslVerifyHost = 2;

    /** @var bool */
    protected $utf8 = false;

    /** @var string */
    protected $proxyHost;

    /** @var int */
    protected $proxyPort;

    /** @var string */
    protected $proxyUsername;

    /** @var string */
    protected $proxyPassword;

    /**
     * @param bool $ssl
     */
    public function setSsl($ssl)
    {
        $this->ssl = (bool) $ssl;
    }

    /**
     * @param int $sslVerifyPeer
     */
    public function setSslVerifyPeer($sslVerifyPeer)
    {
        $this->sslVerifyPeer = $sslVerifyPeer;
    }

    /**
     * @param int $sslVerifyHost
     */
    public function setSslVerifyHost($sslVerifyHost)
    {
        $this->sslVerifyHost = $sslVerifyHost;
    }

    /**
     * @param bool $utf8
     */
    public function setUtf8($utf8)
    {
        $this->utf8 = (bool) $utf8;
    }

    /**
     * @return string
     */
    public function getProxyHost()
    {
        return $this->proxyHost;
    }

    /**
     * @param string $proxyHost
     */
    public function setProxyHost($proxyHost)
    {
        $this->proxyHost = $proxyHost;
    }

    /**
     * @return int
     */
    public function getProxyPort()
    {
        return $this->proxyPort;
    }

    /**
     * @param int $proxyPort
     */
    public function setProxyPort($proxyPort)
    {
        $this->proxyPort = $proxyPort;
    }

    /**
     * @return string
     */
    public function getProxyUsername()
    {
        return $this->proxyUsername;
    }

    /**
     * @param string $proxyUsername
     */
    public function setProxyUsername($proxyUsername)
    {
        $this->proxyUsername = $proxyUsername;
    }

    /**
     * @return string
     */
    public function getProxyPassword()
    {
        return $this->proxyPassword;
    }

    /**
     * @param string $proxyPassword
     */
    public function setProxyPassword($proxyPassword)
    {
        $this->proxyPassword = $proxyPassword;
    }

    /**
     * Establish a connection.
     */
    public function connect()
    {
        $this->connection = new Curl();
        $this->connection->setOptions([
            CURLOPT_URL => $this->getBaseUri(),
            CURLOPT_USERPWD => $this->getUsername().':'.$this->getPassword(),
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->getTimeout(),
        ]);

        if ($this->ssl) {
            $this->connection->setOption(CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
        }

        $this->connection->setOption(CURLOPT_SSL_VERIFYHOST, $this->sslVerifyHost);
        $this->connection->setOption(CURLOPT_SSL_VERIFYPEER, $this->sslVerifyPeer);

        if ($proxyUrl = $this->getProxyHost()) {
            $proxyPort = $this->getProxyPort();
            $this->connection->setOption(CURLOPT_PROXY, $proxyPort ? $proxyUrl.':'.$proxyPort : $proxyUrl);
            $this->connection->setOption(CURLOPT_HTTPPROXYTUNNEL, true);
        }

        if ($username = $this->getProxyUsername()) {
            $this->connection->setOption(CURLOPT_PROXYUSERPWD, $username.':'.$this->getProxyPassword());
        }

        $this->pingConnection();
        $this->connectionTimestamp = time();
        $this->setUtf8Mode();
        $this->setConnectionRoot();
    }

    /**
     * Close the connection.
     */
    public function disconnect()
    {
        if ($this->connection !== null) {
            $this->connection = null;
        }
        $this->isPureFtpd = null;
    }

    /**
     * Check if a connection is active.
     *
     * @return bool
     */
    public function isConnected()
    {
        return $this->connection !== null && !$this->hasConnectionReachedTimeout();
    }

    /**
     * @return bool
     */
    protected function hasConnectionReachedTimeout()
    {
        return $this->connectionTimestamp + $this->getTimeout() < time();
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        $result = $this->writeStream($path, $stream, $config);

        if ($result === false) {
            return false;
        }

        $result['contents'] = $contents;
        $result['mimetype'] = Util::guessMimeType($path, $contents);

        return $result;
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        $connection = $this->getConnection();

        $result = $connection->exec([
            CURLOPT_URL => $this->getBaseUri().'/'.$path,
            CURLOPT_UPLOAD => 1,
            CURLOPT_INFILE => $resource,
        ]);

        if ($result === false) {
            return false;
        }

        $type = 'file';

        return compact('type', 'path');
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $connection = $this->getConnection();

        $moveCommands = [
            'RNFR '.$path,
            'RNTO '.$newpath,
        ];

        $response = $this->rawPost($connection, $moveCommands);
        list($code) = explode(' ', end($response), 2);

        return (int) $code === 250;
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $file = $this->read($path);

        if ($file === false) {
            return false;
        }

        return $this->write($newpath, $file['contents'], new Config()) !== false;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $connection = $this->getConnection();

        $response = $this->rawCommand($connection, 'DELE '.$path);
        list($code) = explode(' ', end($response), 2);

        return (int) $code === 250;
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $connection = $this->getConnection();

        $response = $this->rawCommand($connection, 'RMD '.$dirname);
        list($code) = explode(' ', end($response), 2);

        return (int) $code === 250;
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $connection = $this->getConnection();

        $response = $this->rawCommand($connection, 'MKD '.$dirname);
        list($code) = explode(' ', end($response), 2);
        if ((int) $code !== 257) {
            return false;
        }

        return ['type' => 'dir', 'path' => $dirname];
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $connection = $this->getConnection();

        if ($visibility === AdapterInterface::VISIBILITY_PUBLIC) {
            $mode = $this->getPermPublic();
        } else {
            $mode = $this->getPermPrivate();
        }

        $request = sprintf('SITE CHMOD %o %s', $mode, $path);
        $response = $this->rawCommand($connection, $request);
        list($code) = explode(' ', end($response), 2);
        if ((int) $code !== 200) {
            return false;
        }

        return $this->getMetadata($path);
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        if (!$object = $this->readStream($path)) {
            return false;
        }

        $object['contents'] = stream_get_contents($object['stream']);
        fclose($object['stream']);
        unset($object['stream']);

        return $object;
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $stream = fopen('php://temp', 'w+b');

        $connection = $this->getConnection();

        $result = $connection->exec([
            CURLOPT_URL => $this->getBaseUri().'/'.$path,
            CURLOPT_FILE => $stream,
        ]);

        if (!$result) {
            fclose($stream);

            return false;
        }

        rewind($stream);

        return ['type' => 'file', 'path' => $path, 'stream' => $stream];
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        if ($path === '') {
            return ['type' => 'dir', 'path' => ''];
        }

        $request = rtrim('LIST -A '.$this->normalizePath($path));

        $connection = $this->getConnection();
        $result = $connection->exec([CURLOPT_CUSTOMREQUEST => $request]);
        if ($result === false) {
            return false;
        }
        $listing = $this->normalizeListing(explode(PHP_EOL, $result), '');

        return current($listing);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        if (!$metadata = $this->getMetadata($path)) {
            return false;
        }

        $metadata['mimetype'] = MimeType::detectByFilename($path);

        return $metadata;
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        $response = $this->rawCommand($this->getConnection(), 'MDTM '.$path);
        list($code, $time) = explode(' ', end($response), 2);
        if ($code !== '213') {
            return false;
        }

        if (strpos($time, '.')) {
            $datetime = DateTime::createFromFormat('YmdHis.u', $time);
        } else {
            $datetime = DateTime::createFromFormat('YmdHis', $time);
        }

        if (!$datetime) {
            return false;
        }

        return ['path' => $path, 'timestamp' => $datetime->getTimestamp()];
    }

    /**
     * {@inheritdoc}
     *
     * @param string $directory
     */
    protected function listDirectoryContents($directory, $recursive = false)
    {
        if ($recursive === true) {
            return $this->listDirectoryContentsRecursive($directory);
        }

        $request = rtrim('LIST -aln '.$this->normalizePath($directory));

        $connection = $this->getConnection();
        $result = $connection->exec([CURLOPT_CUSTOMREQUEST => $request]);
        if ($result === false) {
            return [];
        }

        if ($directory === '/') {
            $directory = '';
        }

        return $this->normalizeListing(explode(PHP_EOL, $result), $directory);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $directory
     */
    protected function listDirectoryContentsRecursive($directory)
    {
        $request = rtrim('LIST -aln '.$this->normalizePath($directory));

        $connection = $this->getConnection();
        $result = $connection->exec([CURLOPT_CUSTOMREQUEST => $request]);

        $listing = $this->normalizeListing(explode(PHP_EOL, $result), $directory);
        $output = [];

        foreach ($listing as $item) {
            if ($item['type'] === 'file') {
                $output[] = $item;
            } elseif ($item['type'] === 'dir') {
                $output = array_merge($output, $this->listDirectoryContentsRecursive($item['path']));
            }
        }

        return $output;
    }

    /**
     * Normalize a permissions string.
     *
     * @param string $permissions
     *
     * @return int
     */
    protected function normalizePermissions($permissions)
    {
        // remove the type identifier
        $permissions = substr($permissions, 1);
        // map the string rights to the numeric counterparts
        $map = ['-' => '0', 'r' => '4', 'w' => '2', 'x' => '1'];
        $permissions = strtr($permissions, $map);
        // split up the permission groups
        $parts = str_split($permissions, 3);
        // convert the groups
        $mapper = function ($part) {
            return array_sum(str_split($part));
        };

        // converts to decimal number
        return octdec(implode('', array_map($mapper, $parts)));
    }

    /**
     * Normalize path depending on server.
     *
     * @param string $path
     *
     * @return string
     */
    protected function normalizePath($path)
    {
        if (empty($path)) {
            return '';
        }

        $path = Normalizer::normalize($path);

        if ($this->isPureFtpdServer()) {
            $path = str_replace(' ', '\ ', $path);
        }

        $path = str_replace('*', '\\*', $path);

        return $path;
    }

    /**
     * @return bool
     */
    protected function isPureFtpdServer()
    {
        if ($this->isPureFtpd === null) {
            $response = $this->rawCommand($this->getConnection(), 'HELP');
            $response = end($response);
            $this->isPureFtpd = stripos($response, 'Pure-FTPd') !== false;
        }

        return $this->isPureFtpd;
    }

    /**
     * Sends an arbitrary command to an FTP server.
     *
     * @param  Curl   $connection The CURL instance
     * @param  string $command    The command to execute
     *
     * @return array Returns the server's response as an array of strings
     */
    protected function rawCommand($connection, $command)
    {
        $response = '';
        $callback = function ($ch, $string) use (&$response) {
            $response .= $string;

            return strlen($string);
        };
        $connection->exec([
            CURLOPT_CUSTOMREQUEST => $command,
            CURLOPT_HEADERFUNCTION => $callback,
        ]);

        return explode(PHP_EOL, trim($response));
    }

    /**
     * Sends an arbitrary command to an FTP server using POSTQUOTE option. This makes sure all commands are run
     * in succession and increases chance of success for complex operations like "move/rename file".
     *
     * @param  Curl  $connection The CURL instance
     * @param  array $commandsArray    The commands to execute
     *
     * @return array Returns the server's response as an array of strings
     */
    protected function rawPost($connection, array $commandsArray)
    {
        $response = '';
        $callback = function ($ch, $string) use (&$response) {
            $response .= $string;

            return strlen($string);
        };
        $connection->exec([
            CURLOPT_POSTQUOTE => $commandsArray,
            CURLOPT_HEADERFUNCTION => $callback,
        ]);

        return explode(PHP_EOL, trim($response));
    }

    /**
     * Returns the base url of the connection.
     *
     * @return string
     */
    protected function getBaseUri()
    {
        $protocol = $this->ssl ? 'ftps' : 'ftp';

        return $protocol.'://'.$this->getHost().':'.$this->getPort();
    }

    /**
     * Check the connection is established.
     */
    protected function pingConnection()
    {
        // We can't use the getConnection, because it will lead to an infinite cycle
        if ($this->connection->exec() === false) {
            throw new RuntimeException('Could not connect to host: '.$this->getHost().', port:'.$this->getPort());
        }
    }

    /**
     * Set the connection to UTF-8 mode.
     */
    protected function setUtf8Mode()
    {
        if (!$this->utf8) {
            return;
        }

        $response = $this->rawCommand($this->connection, 'OPTS UTF8 ON');
        list($code, $message) = explode(' ', end($response), 2);
        if ($code !== '200') {
            throw new RuntimeException(
                'Could not set UTF-8 mode for connection: '.$this->getHost().'::'.$this->getPort()
            );
        }
    }

    /**
     * Set the connection root.
     */
    protected function setConnectionRoot()
    {
        $root = $this->getRoot();
        if (empty($root)) {
            return;
        }

        // We can't use the getConnection, because it will lead to an infinite cycle
        $response = $this->rawCommand($this->connection, 'CWD '.$root);
        list($code) = explode(' ', end($response), 2);
        if ((int) $code !== 250) {
            throw new RuntimeException('Root is invalid or does not exist: '.$this->getRoot());
        }
    }
}
