<?php
/**
 * @file RedisSentinelClient.php
 * @author Ryota Namiki <ryo180@gmail.com>
 * @author Casper Langemeijer <casper@langemeijer.eu>
 */
class RedisSentinelClientNoConnectionException extends Exception
{
}

/**
 * @class RedisSentinelClient
 *
 * Redis Sentinel client class
 */
class RedisSentinelClient
{
    protected $_socket;
    protected $_host;
    protected $_port;

    public function __construct($h, $p = 26379)
    {
        $this->_host = $h;
        $this->_port = $p;
    }

    public function __destruct()
    {
        if ($this->_socket) {
            $this->_close();
        }
    }

    /**
     * Issue PING command
     *
     * @retval boolean true on succes, false on failure
     */
    public function ping()
    {
        if ($this->_connect()) {
            $this->_write('PING');
            $this->_write('QUIT');
            $data = $this->_get();
            $this->_close();
            return ($data === '+PONG');
        } else {
            return false;
        }
    }

    /**
     * Issue SENTINEL masters command
     *
     * @retval array of masters, contains the fields returned by the sentinel
     * @code
     * array (
     *   [0]  => // master index
     *     array(
     *       'name' => 'mymaster',
     *       'host' => 'localhost',
     *       'port' => 6379,
     *       ...
     *     ),
     *   ...
     * )
     * @endcode
     */
    public function masters()
    {
        if ($this->_connect()) {
            $this->_write('SENTINEL masters');
            $this->_write('QUIT');
            $data = $this->_extract($this->_get());
            $this->_close();
            return $data;
        } else {
            throw new RedisSentinelClientNoConnectionException;
        }
    }

    /**
     * Issue SENTINEL slaves command
     *
     * @param $master string master name
     * @retval array of slaves for the specified masters. returns data array with fiels returned by the sentinel.
     * @code
     * array (
     *   [0]  =>
     *     array(
     *       'name' => 'mymaster',
     *       'host' => 'localhost',
     *       'port' => 6379,
     *       ...
     *     ),
     *   ...
     * )
     * @endcode
     */
    public function slaves($master)
    {
        if ($this->_connect()) {
            $this->_write('SENTINEL slaves ' . $master);
            $this->_write('QUIT');
            $data = $this->_extract($this->_get());
            $this->_close();
            return $data;
        } else {
            throw new RedisSentinelClientNoConnectionException;
        }
    }

    /**
     * Issue SENTINEL is-master-down-by-addr command
     *
     * @param $ip string target server IP address
     * @param $port integer port number
     * @retval array with fields returned by the sentinel.
     * @code
     * array (
     *   [0]  => 1
     *   [1]  => leader
     * )
     * @endcode
     */
    public function is_master_down_by_addr($ip, $port)
    {
        if ($this->_connect()) {
            $this->_write('SENTINEL is-master-down-by-addr ' . $ip . ' ' . $port);
            $this->_write('QUIT');
            $data = $this->_get();
            $lines = explode("\r\n", $data, 4);
            list ( /* elem num*/, $state, /* length */, $leader) = $lines;
            $this->_close();
            return array(ltrim($state, ':'), $leader);
        } else {
            throw new RedisSentinelClientNoConnectionException;
        }
    }

    /**
     * Issue SENTINEL get-master-addr-by-name command
     *
     * @param $master string master name
     * @retval array with fields returned by the sentinel
     * @code
     * array (
     *   [0]  =>
     *     array(
     *       '<IP ADDR>' => '<PORT>',
     *     )
     * )
     * @endcode
     */
    public function get_master_addr_by_name($master)
    {
        if ($this->_connect()) {
            $this->_write('SENTINEL get-master-addr-by-name ' . $master);
            $this->_write('QUIT');
            $data = $this->_extract($this->_get());
            $this->_close();
            return $data;
        } else {
            throw new RedisSentinelClientNoConnectionException;
        }
    }

    /**
     * Issue SENTINEL reset command
     *
     * @param $pattern string Master name pattern (glob style)
     * @retval integer The number of master that matched
     */
    public function reset($pattern)
    {
        if ($this->_connect()) {
            $this->_write('SENTINEL reset ' . $pattern);
            $this->_write('QUIT');
            $data = $this->_get();
            $this->_close();
            return ltrim($data, ':');
        } else {
            throw new RedisSentinelClientNoConnectionException;
        }
    }

    /**
     * This method connects to the sentinel
     *
     * @retval boolean true on success, false on failure
     */
    protected function _connect()
    {
        $this->_socket = @fsockopen($this->_host, $this->_port, $en, $es);

        return !!($this->_socket);
    }

    /**
     * Close connection to the sentinel
     *
     * @retval boolean true on success, false on failure
     */
    protected function _close()
    {
        $ret = @fclose($this->_socket);
        $this->_socket = null;
        return $ret;
    }

    /**
     * See if connection to the sentinel is still active
     *
     * @retval boolean true active, false if disconnected
     */
    protected function _receiving()
    {
        return !feof($this->_socket);
    }

    /**
     * Write a command to the sentinel
     *
     * @param $c string command
     * @retval mixed integer number of bytes written
     * @retval mixed boolean false on failure
     */
    protected function _write($c)
    {
        return fwrite($this->_socket, $c . "\r\n");
    }

    /**
     * Read data back from the sentinel
     *
     * @retval string returned
     */
    protected function _get()
    {
        $buf = '';
        while ($this->_receiving()) {
            $buf .= fgets($this->_socket);
        }
        return rtrim($buf, "\r\n+OK\n");
    }

    /**
     * Convert to an array Redis response string that represents the multi-dimensional hierarchy
     *
     * @param $data string received from the redis sentinel
     * @retval array data
     */
    protected function _extract($data)
    {
        if (!$data) {
            return array();
        }
        $lines = explode("\r\n", $data);
        $is_root = $is_child = false;
        $c = count($lines);
        $results = $current = array();
        for ($i = 0; $i < $c; $i++) {
            $str = $lines[$i];
            $prefix = substr($str, 0, 1);
            if ($prefix === '*') {
                if (!$is_root) {
                    $is_root = true;
                    $current = array();
                    continue;
                } else {
                    if (!$is_child) {
                        $is_child = true;
                        continue;
                    } else {
                        $is_root = $is_child = false;
                        $results[] = $current;
                        continue;
                    }
                }
            }
            $keylen = $lines[$i++];
            $key = $lines[$i++];
            $vallen = $lines[$i++];
            $val = $lines[$i++];
            $current[$key] = $val;

            --$i;
        }
        $results[] = $current;
        return $results;
    }
}
