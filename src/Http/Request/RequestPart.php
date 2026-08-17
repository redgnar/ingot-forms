<?php

declare(strict_types=1);

namespace App\Http\Request;

/**
 * Which part of the HTTP request a DTO is mapped from.
 *
 * The two parts get different mapping rules, because their wire formats
 * differ: a JSON body carries real types and a closed key set, while query
 * parameters are always strings and may legitimately carry extras a client
 * or proxy appended.
 */
enum RequestPart
{
    case Body;
    case Query;
}
