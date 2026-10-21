<?php

declare(strict_types=1);

namespace Keel\Routing\Exception;

/**
 * Configuration error in route declarations (bad path syntax, duplicate names, ...).
 */
final class RoutingException extends \LogicException {}
