<?php

namespace NovaMcp\Mcp;

class ActionFailed extends \RuntimeException
{
    public function __construct(public array $result)
    {
        parent::__construct('The Nova action reported failure.');
    }
}
