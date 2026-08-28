<?php

declare(strict_types=1);

namespace App\Tests\UserInterface;

use App\UserInterface\RouteGroup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The two properties whatever guards this service depends on.
 *
 * Nothing here decides who may act — a gateway does, and a decision point behind
 * it. Both write their rules against the *addresses*, so the addresses have to
 * hold still in a particular shape: one prefix per audience, and the form's id
 * always in the same place inside it. Neither property is visible in any one
 * file, which is why it is asserted here over the whole collection: this is the
 * only place that sees every route at once.
 *
 * A route added outside the four groups fails this test rather than quietly
 * landing outside every rule in front — which is the failure mode that matters,
 * because an address no rule covers is an *open* address and nothing about it
 * looks wrong from the inside.
 */
final class RouteGroupsTest extends KernelTestCase
{
    public function testEveryAddressBelongsToExactlyOneGroup(): void
    {
        // GIVEN every route this service answers on
        $routes = $this->routes();
        self::assertNotEmpty($routes, 'The router served no routes at all, so this test proves nothing.');

        foreach ($routes as $name => $path) {
            // WHEN each is matched against the four group prefixes
            $matched = array_filter(
                RouteGroup::cases(),
                static fn(RouteGroup $group): bool => str_starts_with($path, $group->value),
            );

            // THEN exactly one claims it. None means an address no rule in front
            // covers; more than one means a rule cannot be written per group at
            // all, because two of them would have to disagree about the same path.
            self::assertCount(
                1,
                $matched,
                \sprintf('Route "%s" (%s) belongs to %d groups, not 1.', $name, $path, \count($matched)),
            );
        }
    }

    public function testAFormIdAlwaysSitsStraightAfterItsGroupPrefix(): void
    {
        // GIVEN every route that names one form
        foreach ($this->routes() as $name => $path) {
            if (!str_contains($path, '{id}')) {
                continue;
            }

            $group = RouteGroup::of($path);
            self::assertNotNull($group, \sprintf('Route "%s" (%s) is in no group.', $name, $path));

            // WHEN the group is asked where an id sits in it
            $expected = $group->idPrefix();
            self::assertNotNull($expected, \sprintf('Group "%s" names no forms, but "%s" does.', $group->value, $path));

            // THEN that is where this route has it, so a decision point outside
            // reads the form out of the path with one pattern per group instead
            // of a guess per route
            self::assertStringStartsWith(
                $expected . '{id}',
                $path,
                \sprintf('Route "%s" hides its form id: %s does not begin %s{id}.', $name, $path, $expected),
            );
        }
    }

    public function testTheGroupsCoverWhatTheyClaimTo(): void
    {
        // GIVEN the four groups
        // WHEN each is asked for the addresses it holds
        $held = [];

        foreach ($this->routes() as $path) {
            $group = RouteGroup::of($path);

            if ($group !== null) {
                $held[$group->value] = true;
            }
        }

        // THEN none of them is empty: a group nothing is served under is a rule
        // a deployment would write for no reason, and a sign the enum has
        // outlived a route rather than described one
        foreach (RouteGroup::cases() as $group) {
            self::assertArrayHasKey($group->value, $held, \sprintf('No address is served under "%s".', $group->value));
        }
    }

    /**
     * @return array<string, string> route name => path
     */
    private function routes(): array
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $paths = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $paths[$name] = $route->getPath();
        }

        return $paths;
    }
}
