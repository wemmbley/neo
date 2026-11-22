<?php

declare(strict_types=1);

namespace Neo\Information\Lang;

use Neo\Foundation\Config\Config;
use Neo\Foundation\Kernel\DeferrableProviderContract;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Kernel\ServiceProvider;

final class LangServiceProvider extends ServiceProvider implements DeferrableProviderContract
{
    public static function provides(): array
    {
        return [LangProcessor::class];
    }

    public function register(): void
    {
        $this->app->singleton(LangProcessor::class, function (ServiceContainer $container): LangProcessor {

            /** @var Config $config */
            $config = $container->make(Config::class);

            $lang = new LangProcessor(
                (string) $config->get('lang.locale', 'en'),
                (string) $config->get('lang.fallback', 'en'),
            );

            #
            # Переводы пакетов, заявленные через loadTranslations().
            #
            foreach ($this->app->resources('translations') as $resource) {
                $lang->addNamespace(
                    $resource['namespace'] ?? LangProcessor::DEFAULT_NAMESPACE,
                    $resource['path'],
                );
            }

            #
            # Переводы приложения регистрируются последними: они сильнее пакетов.
            #
            if (($path = $config->get('lang.path')) !== null) {
                $lang->addNamespace(LangProcessor::DEFAULT_NAMESPACE, (string) $path);
            }

            return $lang;
        });
    }
}