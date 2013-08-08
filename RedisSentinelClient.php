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

    public function __construct($host, $port = 26379)
    {
        $this->_host = $host;
        $this->_port = $port;
    }

    public function __destruct()
    {
        if ($this->_socket) {
            @fclose($this->_socket);
        }
    }

    /**
     * Issue PING command
     *
     * @return boolean true on success, false on failure
     */
    public function ping()
    {
        if (!$this->_connect()) {
            return false;
        }

        $this->_write('PING');
        $data = $this->_getLine();

        return ($data === '+PONG');
    }

    /**
     * Issue SENTINEL masters command
     *
     * @return array of masters, contains the fields returned by the sentinel
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
     * @throws RedisSentinelClientNoConnectionException
     */
    public function masters()
    {
        if (!$this->_connect()) {
            throw new RedisSentinelClientNoConnectionException;
        }

        $this->_write('SENTINEL masters');
        $data = $this->_readReply();

        return $data;
    }

    /**
     * Issue SENTINEL slaves command
     *
     * @param $master string master name
     * @return array of slaves for the specified masters. returns data array with fields returned by the sentinel.
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
     * @throws RedisSentinelClientNoConnectionException
     */
    public function slaves($master)
    {
        if (!$this->_connect()) {
            throw new RedisSentinelClientNoConnectionException;
        }

        $this->_write('SENTINEL slaves ' . $master);
        $data = $this->_readReply();

        return $data;
    }

    /**
     * Issue SENTINEL is-master-down-by-addr command
     *
     * @param $ip string target server IP address
     * @param $port integer port number
     * @return array with fields returned by the sentinel.
     * @code
     * array (
     *   [0]  => 1
     *   [1]  => leader
     * )
     * @endcode
     * @throws RedisSentinelClientNoConnectionException
     */
    public function is_master_down_by_addr($ip, $port)
    {
        if (!$this->_connect()) {
            throw new RedisSentinelClientNoConnectionException;
        }

        $this->_write('SENTINEL is-master-down-by-addr ' . $ip . ' ' . $port);

        $this->_getLine();
        $state = $this->_getLine();
        $this->_getLine();
        $leader = $this->_getLine();

        return array($state, $leader);
    }

    /**
     * Issue SENTINEL get-master-addr-by-name command
     *
     * @param $master string master name
     * @return array with fields returned by the sentinel
     * @code
     * array (
     *   [0]  =>
     *     array(
     *       '<IP ADDR>' => '<PORT>',
     *     )
     * )
     * @endcode
     * @throws RedisSentinelClientNoConnectionException
     */
    public function get_master_addr_by_name($master)
    {
        if (!$this->_connect()) {
            throw new RedisSentinelClientNoConnectionException;
        }

        $this->_write('SENTINEL get-master-addr-by-name ' . $master);
        $data = $this->_readReply();

        return $data;
    }

    /**
     * Issue SENTINEL reset command
     *
     * @param $pattern string Master name pattern (glob style)
     * @return integer The number of master that matched
     * @throws RedisSentinelClientNoConnectionException
     */
    public function reset($pattern)
    {
        if (!$this->_connect()) {
            throw new RedisSentinelClientNoConnectionException;
        }

        $this->_write('SENTINEL reset ' . $pattern);
        $data = $this->_getLine();

        return $data;
    }

    /**
     * This method connects to the sentinel
     *
     * @return boolean true on success, false on failure
     */
    protected function _connect()
    {
        if ($this->_socket !== null) {
            return !feof($this->_socket);
        }

        $this->_socket = @fsockopen($this->_host, $this->_port, $en, $es);

        return (bool)$this->_socket;
    }

    /**
     * Write a command to the sentinel
     *
     * @param $c string command
     * @return mixed integer number of bytes written
     * @return mixed boolean false on failure
     */
    protected function _write($c)
    {
        return fwrite($this->_socket, $c . "\r\n");
    }

    private function _getLine()
    {
        return substr(fgets($this->_socket), 0, -2); // strips CRLF
    }

    private function _getData($size)
    {
        $size += 2;
        $data = '';
        while (strlen($data) < $size) {
            $data = fread($this->_socket, $size - strlen($data));
        }
        $data = substr($data, 0, -2); // strips last CRLF
        $data = str_replace("\r\n", "\n", $data); // convert CRLF to LF
        return $data;
    }

    /**
     * This function parses the reply on a sentinel command
     * @return array
     */
    private function _readReply()
    {

        if (feof($this->_socket)) {
            return false;
        }

        $str = $this->_getLine();
        $prefix = $str[0];
        $payload = substr($str, 1);

        switch ($prefix) {
            case '+':
                return $payload;

            case '$':
                $size = (int)$payload;
                if ($size == -1) {
                    return null;
                }
                $str = $this->_getData($size);
                return $str;

            case '*': //array
                $size = (int)$payload;
                if ($size == -1) {
                    return null;
                }

                $allStrings = ($size % 2) == 0;
                $multibulk = array();
                $multibulkAssoc = array();

                for ($i = 0; $i < $size; $i++) {
                    $reply = $this->_readReply();
                    if (!is_string($reply)) {
                        $allStrings = false;
                    }

                    if ($allStrings) {
                        if (($i % 2) == 0) {
                            $key = $reply;
                        } else {
                            $multibulkAssoc[$key] = $reply;
                        }
                    }
                    $multibulk[$i] = $reply;
                }

                if ($allStrings) {
                    return $multibulkAssoc;
                }
                return $multibulk;

            case ':': // int
                return (int)$payload;

            case '-':
                return false;

        }
    }
}
