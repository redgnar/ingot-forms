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
        $base = $this->basePath();

        foreach ($routes as $name => $path) {
            $path = RouteGroup::under($base, $path);
            self::assertNotNull($path, \sprintf('Route "%s" is not served under "%s".', $name, $base));

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
        $base = $this->basePath();

        foreach ($this->routes() as $name => $path) {
            if (!str_contains($path, '{id}')) {
                continue;
            }

            $path = (string) RouteGroup::under($base, $path);
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
        $base = $this->basePath();

        foreach ($this->routes() as $path) {
            $group = RouteGroup::of($path, $base);

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
     * The two properties again, for an installation that put this service under a
     * path of its own: the base belongs to the deployment and the four prefixes
     * belong to the service, so a rule in front is still one line — written
     * against the base plus the prefix.
     */
    public function testTheGroupsHoldWhereverTheInstallationPutTheService(): void
    {
        // GIVEN a service installed under a prefix of the deployment's choosing
        $base = '/somewhere';

        // WHEN an address of each group is read the way a gateway reads it
        // THEN the group is the same one, and only the base has moved
        self::assertSame(RouteGroup::Manage, RouteGroup::of($base . '/api/manage/forms/x', $base));
        self::assertSame(RouteGroup::Fill, RouteGroup::of($base . '/api/forms/x/data', $base));
        self::assertSame(RouteGroup::Pages, RouteGroup::of($base . '/forms/x', $base));
        self::assertSame(RouteGroup::Contract, RouteGroup::of($base . '/api/schemas/definition', $base));

        // AND an address that does not begin with that base is not this
        // service's at all — answering "filling" for somebody else's `/forms/x`
        // is how a rule ends up guarding the wrong thing
        self::assertNull(RouteGroup::of('/forms/x', $base));
        self::assertNull(RouteGroup::of($base . 'ish/forms/x', $base));
    }

    /** Where this installation put the service: empty unless it said otherwise. */
    private function basePath(): string
    {
        self::bootKernel();
        $base = self::getContainer()->getParameter('forms.base_path');
        self::assertIsString($base);

        return $base;
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
