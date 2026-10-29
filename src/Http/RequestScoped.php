<?php

declare(strict_types=1);

namespace Keel\Http;

/**
 * A type route handlers receive only from a request attribute a middleware stored under its class
 * (the authenticated principal), never from the container: on a request without one, a nullable
 * parameter gets null and a required one fails, instead of an autowired blank instance.
 */
interface RequestScoped {}
