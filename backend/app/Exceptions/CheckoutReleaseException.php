<?php
namespace App\Exceptions;
final class CheckoutReleaseException extends \RuntimeException { public function __construct(private readonly string $releaseCode){parent::__construct('Release could not be planned.');} public function releaseCode():string{return $this->releaseCode;} }
