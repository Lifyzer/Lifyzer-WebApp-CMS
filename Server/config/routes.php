<?php
/**
 * @author         Pierre-Henry Soria <hello@lifyzer.com>
 * @copyright      (c) 2018-2020, Pierre-Henry Soria. All Rights Reserved.
 * @license        GNU General Public License; <https://www.gnu.org/licenses/gpl-3.0.en.html>
 */

declare(strict_types=1);

namespace Lifyzer\Server;

use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

return simpleDispatcher(static function (RouteCollector $route) {
    $route->addRoute('GET', '/', 'Product@add');
    $route->addRoute('POST', '/submit', 'Product@submit');
    $route->addRoute('GET', '/approve/{hash}/{id:\d+}', 'Product@approve');
    $route->addRoute('GET', '/disapprove/{hash}/{id:\d+}', 'Product@disapprove');
});
