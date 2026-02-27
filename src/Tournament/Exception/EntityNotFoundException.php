<?php

namespace Tournament\Exception;

/**
 * A runtime exception to throw if a requested data entry according the url parameters is not found.
 */
class EntityNotFoundException extends \Slim\Exception\HttpNotFoundException
{
}
