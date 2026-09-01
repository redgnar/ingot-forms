<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The one thing this service is told about where it stands.
     *
     * Nothing else in here knows its own address: every URL a page carries — the
     * page itself, its assets, the endpoints it hands its own JavaScript — is
     * generated, so all of them move together the moment something says the
     * addresses begin somewhere other than at the root of a host.
     *
     * There are two ways to say it, one per kind of gateway, and this is the
     * second:
     *
     *   - **A gateway that rewrites** answers on `/somewhere/forms/{id}`, strips
     *     `/somewhere` and says so in `X-Forwarded-Prefix`. Nothing is configured
     *     here; the framework reads the header (from a trusted proxy only) and
     *     every generated address follows. That one is runtime.
     *   - **A gateway that does not rewrite** passes the whole path through, so
     *     the service has to *own* the prefix — its routes have to be declared
     *     under it. That is what `FORMS_BASE_PATH` does, and it is read here
     *     because routes are built when the container is, not per request.
     *
     * Which means it is a **build-time** setting: changing it needs the cache
     * cleared (`make cache-clear`, and a deploy already does it), exactly like
     * changing what a definition derives. Nothing warns about a stale one, which
     * is why `app:routes:groups` prints the prefix it is actually serving under —
     * a deployment reads the addresses instead of remembering them.
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->setParameter('forms.base_path', self::basePath());
    }

    /**
     * Normalized to one shape — leading slash, no trailing one, or empty — so a
     * deployment may write `/svc`, `svc` or `/svc/` and get the same routes.
     */
    private static function basePath(): string
    {
        $said = $_ENV['FORMS_BASE_PATH'] ?? $_SERVER['FORMS_BASE_PATH'] ?? '';
        $said = trim(\is_string($said) ? $said : '', " \t\n\r\0\x0B/");

        return $said === '' ? '' : '/' . $said;
    }
}
