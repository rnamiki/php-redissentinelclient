# php-redissentinelclient #

Redis Sentinel client for PHP

This class is written by Ryota Namiki and Casper Langemeijer. It's methods are the same as the available
sentinel commands. For more information on Redis Sentinel see http://redis.io/topics/sentinel

## Usage ##
This class is constructed with a hostname or ip parameter, and a secondary optional port parameter. If not
specified 26379 is used as the default port.

    $RedisSentinel = new RedisSentinelClient('127.0.0.1', 26379);

The Sentinel commands implemented as class methods:


### ping ###

    $ok = $RedisSentinel->ping();

This method returns true on success, false on failure.


### masters ###

    $masters = $RedisSentinel->masters();

This method returns an array of all masters currently known to the sentinel. The array looks like this:

	array (
		0 => array (
			'name' => 'MASTER2',
			'ip' => '127.0.0.1',
			'port' => '6380',
			'runid' => 'a3e509206a1dd6068e2d6ece73e6b856bca62c5e',
			'flags' => 'master',
			'pending-commands' => '0',
			'last-ok-ping-reply' => '463',
			'last-ping-reply' => '463',
			'info-refresh' => '8993',
			'num-slaves' => '0',
			'num-other-sentinels' => '0',
			'quorum' => '1',
		),
		1 => array (
			'name' => 'MASTER1',
			'ip' => '127.0.0.1',
			'port' => '6379',
			'runid' => 'b91e0679fc593da147b1ba32a4e026fac228b124',
			'flags' => 'master',
			'pending-commands' => '0',
			'last-ok-ping-reply' => '62',
			'last-ping-reply' => '62',
			'info-refresh' => '8993',
			'num-slaves' => '1',
			'num-other-sentinels' => '0',
			'quorum' => '1',
		),
	)

Note that the list of masters is not always in the same order. If you use this to build an array to feed
RedisArray(), make sure you sort it first.'


### slaves ###

    $slaves = $RedisSentinel->slaves('MASTER1');

This method returns an array of all slaves for a specific master, as currently known to the sentinel. The array
looks like this:

	array (
		0 => array (
			'name' => '127.0.0.1:6381',
			'ip' => '127.0.0.1',
			'port' => '6381',
			'runid' => 'b5576a2c95c804e42e812e9b034f34bd5e3f754f',
			'flags' => 'slave',
			'pending-commands' => '0',
			'last-ok-ping-reply' => '849',
			'last-ping-reply' => '849',
			'info-refresh' => '3759',
			'master-link-down-time' => '0',
			'master-link-status' => 'ok',
			'master-host' => '127.0.0.1',
			'master-port' => '6379',
			'slave-priority' => '100',
		),
	)


### is-master-down-by-addr ###

    $data = $RedisSentinel->is_master_down_by_addr('127.0.0.1', 6379);

This method returns an array of two elements where the first is 0 or 1 (0 if the master with that address
is known and is in SDOWN state, 1 otherwise). The second element of the reply is the subjective leader
for this master, that is, the runid of the Redis Sentinel instance that should perform the failover
accordingly to the queried instance.

	array(
		0 => '0',
		1 => '13a81bb82272e22a66a9398cba7786ed0f1d67b7',
	)


### get-master-addr-by-name ###

    $addr = $RedisSentinel->get-master-addr-by-name('MASTER1');

This method returns the ip and port number of the master with that name. If a failover is in progress
or terminated successfully for this master it returns the address and port of the promoted slave.

	array(
		0 => array(
			"127.0.0.1" (9 chars, 9 bytes) => "6380" (4 chars, 4 bytes),
		),
	)


### reset ###

	$number = $RedisSentinel->reset('MASTER*');

This command will reset all the masters with matching name. The pattern argument is a glob-style pattern.
The reset process clears any previous state in a master (including a failover in progress), and removes
every slave and sentinel already discovered and associated with the master.

The return value is the number of master that matched the pattern

