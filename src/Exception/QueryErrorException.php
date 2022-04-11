<?php
namespace Swango\Db\Exception;
class QueryErrorException extends \Swango\Db\Exception {
    public function __construct(public ?int $errno, public ?string $error, public string $sql, public array $params) {
        parent::__construct("DB Error[$errno]$error " . \Json::encode([
                $sql,
                ...$params
            ]));
    }
}