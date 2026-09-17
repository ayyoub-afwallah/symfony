<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\RateLimiter;

/**
 * Placeholder RateLimitValueResolver stages in the controller arguments while the limiters have not
 * run yet, so that it can tell its own slots apart from a genuine null once they have.
 *
 * @author Ayyoub AFW-ALLAH <ayyoub.afwallah@gmail.com>
 *
 * @internal
 */
enum PendingRateLimit
{
    case Placeholder;
}
