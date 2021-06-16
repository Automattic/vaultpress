<?php

/**
 * A base class.
 */
class Vault {
	public function process( stdClass $item, $item2, $item3, ) { // The trailing comma should fail in pre-PHP 8 envs.
		$test = xmlrpc_decode_request(); //xmlrpc_decode_request is gone in php 8.
		return [];
	}
}

/**
 * A class that extends the first one.
 */
class Press extends Vault {
	public function process( array $items ) {
		// The above should trigger an error in PHP 8 since process' $items here does not match its parent class.
	}
}
