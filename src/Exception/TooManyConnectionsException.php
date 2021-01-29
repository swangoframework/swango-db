<?php
namespace Swango\Db\Exception;
class TooManyConnectionsException extends ConnectErrorException {
    public function __construct() {
        parent::__construct(1040, 'Too many connections. Server error', null, null);
    }
}