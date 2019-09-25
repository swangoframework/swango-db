<?php
namespace Swango\Db\Exception;
class QueryErrorException extends \Swango\Db\Exception {
    public $errno, $error;
    public function __construct(?int $errno, ?string $error) {
        parent::__construct("Db query error:[$errno] $error");
        $this->errno = $errno;
        $this->error = $error;
    }
}