<?php
namespace Swango\Db\Exception;
class ConnectErrorException extends \Swango\Db\Exception {
    public $connect_errno, $connect_error, $errno, $error;
    public function __construct(?int $connect_errno, ?string $connect_error, ?int $errno, ?string $error) {
        parent::__construct("Db connect error. Sock error:[$connect_errno]$connect_error. Server error:[$errno]$error");
        $this->connect_errno = $connect_errno;
        $this->connect_error = $connect_error;
        $this->errno = $errno;
        $this->error = $error;
    }
}