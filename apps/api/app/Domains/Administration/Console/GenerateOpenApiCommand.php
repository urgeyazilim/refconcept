<?php

declare(strict_types=1);

namespace App\Domains\Administration\Console;

use App\Domains\Administration\Services\AdminPermissionMatrix;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Writes the API's contract out of the router itself.
 *
 * Generated rather than hand-written, because a hand-written specification is wrong the
 * first time somebody adds a route and nobody notices until an integrator does. What the
 * router knows — the path, the verb, the name, whether authentication is required, and for
 * an administrative route which permission the matrix demands — is exactly the part that
 * must not drift.
 *
 * What it deliberately does not invent: request and response schemas. Guessing them from
 * controller code would produce a document that looks authoritative and is subtly wrong,
 * which is worse for an integrator than a document that is honest about its scope. The
 * shapes live in `packages/ui/src/runtime/types.ts`, which the three clients compile
 * against, so they are checked by a type checker rather than by prose.
 *
 *   php artisan refconcept:openapi
 *   php artisan refconcept:openapi --check
 *
 * `--check` regenerates and compares without writing: a non-zero exit means the committed
 * document no longer matches the routes, which is the whole point of freezing it.
 */
final class GenerateOpenApiCommand extends Command
{
    protected $signature = 'refconcept:openapi
        {--check : Fail if the committed document is out of date, and write nothing}';

    protected $description = 'Generate the OpenAPI document from the router';

    public function handle(AdminPermissionMatrix $matrix): int
    {
        $document = $this->document($matrix);
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->error('Belge kodlanamadı.');

            return self::FAILURE;
        }

        $json .= "\n";
        /*
         * Committed inside the API's own tree.
         *
         * It is the API's contract and belongs with it — and, more practically, it is the
         * only place `--check` can compare against, because the container has the synced
         * application and no repository above it. A document CI cannot verify is a document
         * that drifts.
         */
        $path = base_path('openapi.json');

        if ($this->option('check') === true) {
            $existing = File::exists($path) ? File::get($path) : '';

            if ($existing === $json) {
                $this->info('✔ OpenAPI belgesi güncel.');

                return self::SUCCESS;
            }

            $this->error('✖ OpenAPI belgesi rotalarla uyuşmuyor. `php artisan refconcept:openapi` çalıştırın.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $json);

        $this->info(sprintf('✔ %d uç yazıldı → apps/api/openapi.json', count($document['paths'])));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(AdminPermissionMatrix $matrix): array
    {
        $paths = [];

        foreach ($this->apiRoutes() as $route) {
            $path = '/'.ltrim($route->uri(), '/');

            // OpenAPI wants {param}; Laravel's optional {param?} has no equivalent, so the
            // marker is dropped and the parameter is described as optional instead.
            $openApiPath = str_replace('?}', '}', $path);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$openApiPath][mb_strtolower($method)] = $this->operation($route, $matrix, $path);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'RefConcept API',
                'version' => (string) config('refconcept.version'),
                'description' => "AI destekli iç mekân tasarımı ve çok satıcılı pazar yeri API'si.\n\n"
                    ."Bu belge router'dan üretilir; elle düzenlenmez. İstek ve yanıt gövdelerinin "
                    .'tipleri `packages/ui/src/runtime/types.ts` içinde tanımlıdır ve üç istemci '
                    .'tarafından derlenerek doğrulanır.',
            ],
            'servers' => [
                ['url' => rtrim((string) config('app.url'), '/'), 'description' => 'Bu ortam'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Sanctum kişisel erişim jetonu.',
                    ],
                ],
            ],
            'tags' => $this->tags(),
            'paths' => $paths,
        ];
    }

    /**
     * @return list<RoutingRoute>
     */
    private function apiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(RoutingRoute $route, AdminPermissionMatrix $matrix, string $path): array
    {
        $name = $route->getName();
        $authenticated = in_array('auth:sanctum', $route->gatherMiddleware(), true);

        $operation = [
            'tags' => [$this->tagFor($path)],
            'summary' => $this->summaryFor($route),
            'operationId' => $name ?? Str::slug($route->methods()[0].'-'.$path),
            'responses' => $this->responses($authenticated),
        ];

        if ($parameters = $this->parameters($route)) {
            $operation['parameters'] = $parameters;
        }

        if ($authenticated) {
            $operation['security'] = [['bearerAuth' => []]];
        } else {
            // Explicitly empty rather than absent: "no authentication" is a statement about
            // this endpoint, and an integrator should not have to infer it from silence.
            $operation['security'] = [];
        }

        $permission = $matrix->permissionFor($name);

        if ($permission !== null) {
            $operation['description'] = sprintf(
                'Gerekli platform yetkisi: `%s` (%s).',
                $permission->value,
                $permission->description(),
            );
        }

        return $operation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameters(RoutingRoute $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $optional = str_contains($route->uri(), '{'.$parameter.'?}');

            $parameters[] = [
                'name' => $parameter,
                'in' => 'path',
                'required' => ! $optional,
                'schema' => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * The statuses an integrator has to handle, rather than every status possible.
     *
     * Keyed by status. PHP turns a numeric string key into an integer, so the map is
     * int-keyed however it is written — json_encode puts the quotes back.
     *
     * @return array<int, array<string, string>>
     */
    private function responses(bool $authenticated): array
    {
        $responses = [
            '200' => ['description' => 'Başarılı.'],
            '422' => ['description' => 'Doğrulama hatası.'],
        ];

        if ($authenticated) {
            $responses['401'] = ['description' => 'Kimlik doğrulanmadı.'];
            $responses['403'] = ['description' => 'Yetki yok.'];
        }

        $responses['429'] = ['description' => 'Hız sınırı aşıldı.'];

        ksort($responses);

        return $responses;
    }

    private function tagFor(string $path): string
    {
        if (str_starts_with($path, '/api/v1/admin/')) {
            return 'admin';
        }

        if (str_starts_with($path, '/api/v1/seller/')) {
            return 'seller';
        }

        $segments = explode('/', trim($path, '/'));

        return $segments[2] ?? 'general';
    }

    private function summaryFor(RoutingRoute $route): string
    {
        $action = $route->getActionName();

        if ($action === 'Closure') {
            return 'Kapanış.';
        }

        [$controller, $method] = array_pad(explode('@', $action), 2, 'invoke');

        return class_basename($controller).'::'.$method;
    }

    /**
     * @return list<array<string, string>>
     */
    private function tags(): array
    {
        return [
            ['name' => 'admin', 'description' => 'Platform yönetimi. Her uç yetki matrisinden geçer.'],
            ['name' => 'seller', 'description' => 'Satıcının kendi kayıtları. Yolda kimlik yoktur; çağıran hesaptan çözülür.'],
            ['name' => 'catalog', 'description' => 'Herkese açık katalog.'],
            ['name' => 'auth', 'description' => 'Kayıt, giriş, doğrulama.'],
        ];
    }
}
